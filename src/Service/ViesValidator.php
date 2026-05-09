<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * EU VIES (VAT Information Exchange System) pre-validator.
 *
 * Hits the modern VIES REST endpoint to verify whether a buyer VAT-ID is
 * still on file before we send an invoice payload to invoice-api.xhub. This
 * mirrors the validation Pickware / sevDesk / Lexware Office do today; it
 * is the most-requested compliance feature in our competitor analysis.
 *
 * Endpoint:
 *   POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *   Body: {"countryCode":"DE","vatNumber":"123456789"}
 *   Response: {"isValid":true,"name":"...","address":"...", ...}
 *
 * Notes:
 *   - The `vatNumber` field expects the *digits only*, not the country prefix.
 *     We strip the prefix defensively (e.g., "DE123" → "123").
 *   - VIES is famously slow / occasionally down (10-15% of the time during
 *     EU office hours). We cap timeout at 3s and FAIL OPEN — i.e., return
 *     true on transport failure so a sale isn't blocked by a flaky third
 *     party. Only a *positive* invalid response from VIES counts as invalid.
 *   - Successful results (valid + invalid) are cached for 1 hour to keep
 *     repeated re-tries cheap. The cache is in-process (single-request);
 *     persistent caching is left for a future iteration.
 *
 * Country scope: EU member states only. Calling this with a non-EU code
 * returns true (treated as out-of-scope, not invalid) so non-EU B2B flows
 * are unaffected.
 */
final class ViesValidator
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    private const TIMEOUT = 3.0;

    private const CACHE_TTL_SECONDS = 3600;

    /** ISO-3166-1 alpha-2 of EU member states (incl. Greece "EL" alias for "GR"). */
    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * In-process cache. Key = "<countryCode>|<vatNumber>", value = [timestamp, isValid].
     *
     * @var array<string, array{0:int,1:bool}>
     */
    private array $cache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate a VAT-ID against VIES.
     *
     * @return bool true if VIES says valid OR the country is out-of-EU OR the
     *              endpoint is unreachable (fail-open). false only on a
     *              positive isValid=false response.
     */
    public function validate(string $countryCode, string $vatId): bool
    {
        $cc = strtoupper(trim($countryCode));
        if ('' === $cc) {
            return true; // No country → out of scope, don't block.
        }

        if (!\in_array($cc, self::EU_COUNTRY_CODES, true)) {
            // Non-EU country (CH, NO, US, ...): VIES does not cover it.
            // Treat as out-of-scope rather than invalid.
            return true;
        }

        $vatNumber = $this->normaliseVatNumber($cc, $vatId);
        if ('' === $vatNumber) {
            // Empty VAT-ID — caller should not have asked us; treat as
            // out-of-scope. The caller is responsible for the empty-check
            // before deciding to validate.
            return true;
        }

        $cacheKey = $cc . '|' . $vatNumber;
        $now      = time();
        if (
            isset($this->cache[$cacheKey])
            && ($now - $this->cache[$cacheKey][0]) < self::CACHE_TTL_SECONDS
        ) {
            return $this->cache[$cacheKey][1];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'json'    => [
                    'countryCode' => $cc,
                    'vatNumber'   => $vatNumber,
                ],
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $status = $response->getStatusCode();
            $raw    = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            // Transport / DNS / timeout: fail-open and log.
            $this->logger->warning(
                'VIES transport error — failing open: ' . $e->getMessage(),
                [
                    'source'      => self::LOG_SOURCE,
                    'countryCode' => $cc,
                    'exception'   => $e,
                ],
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'VIES unexpected error — failing open: ' . $e->getMessage(),
                [
                    'source'      => self::LOG_SOURCE,
                    'countryCode' => $cc,
                    'exception'   => $e,
                ],
            );

            return true;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                sprintf('VIES HTTP %d — failing open.', $status),
                [
                    'source'      => self::LOG_SOURCE,
                    'countryCode' => $cc,
                    'body'        => substr($raw, 0, 512),
                ],
            );

            return true;
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || !\array_key_exists('isValid', $parsed)) {
            $this->logger->warning(
                'VIES non-JSON / missing isValid — failing open.',
                [
                    'source'      => self::LOG_SOURCE,
                    'countryCode' => $cc,
                    'body'        => substr($raw, 0, 512),
                ],
            );

            return true;
        }

        $isValid                = (bool) $parsed['isValid'];
        $this->cache[$cacheKey] = [$now, $isValid];

        if (!$isValid) {
            $this->logger->info(
                sprintf('VIES reported VAT-ID %s%s as invalid.', $cc, $vatNumber),
                ['source' => self::LOG_SOURCE],
            );
        }

        return $isValid;
    }

    /**
     * Strip the country prefix from a VAT-ID and remove whitespace / dashes.
     * Examples: "DE 123 456 789" → "123456789", "atu12345678" → "U12345678".
     */
    private function normaliseVatNumber(string $countryCode, string $vatId): string
    {
        $clean = preg_replace('/[\s\-\.]/', '', strtoupper(trim($vatId))) ?? '';
        if ('' === $clean) {
            return '';
        }

        if (str_starts_with($clean, $countryCode)) {
            $clean = substr($clean, strlen($countryCode));
        }

        return $clean;
    }
}
