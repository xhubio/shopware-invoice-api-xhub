<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP wrapper for invoice-api.xhub.io.
 *
 * Mirrors the WooCommerce reference (Invoice_Api_Xhub_Api_Client) but uses
 * Symfony HttpClient (Shopware standard). The caller passes apiKey + baseUrl
 * per call so the service stays stateless and DI-friendly — credentials live
 * in SystemConfigService and are resolved by the consuming service.
 *
 * Bearer auth is added per request. All errors (Zod-style {path,message}
 * arrays, HTTP 4xx/5xx, network/timeout) are normalised by buildErrorMessage()
 * and re-thrown as \RuntimeException with a user-facing message; the full
 * structured detail is logged via $logger with source 'invoice-api-xhub'.
 */
final class ApiClient
{
    private const LOG_SOURCE = 'invoice-api-xhub';

    private const DEFAULT_TIMEOUT = 30.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate an e-invoice document.
     *
     * @param string               $countryCode     e.g. "DE", "AT"
     * @param string               $format          e.g. "pdf", "xrechnung", "zugferd"
     * @param array<string,mixed>  $invoicePayload  Invoice JSON (already mapped)
     * @param string|null          $templateId      Optional console template id
     *
     * @return array<string,mixed> Decoded API response. On success contains at
     *                             minimum: success, data (base64), mimeType,
     *                             filename, format, country.
     *
     * @throws \RuntimeException On any API or transport error.
     */
    public function generate(
        string $countryCode,
        string $format,
        array $invoicePayload,
        ?string $templateId = null,
        string $apiKey = '',
        string $baseUrl = '',
    ): array {
        $body = [
            'invoice'       => $invoicePayload,
            'formatOptions' => new \stdClass(),
        ];

        $templateId = null !== $templateId ? trim($templateId) : '';
        if ('' !== $templateId) {
            $body['templateId'] = $templateId;
        }

        $path = sprintf(
            '/api/v1/invoice/%s/%s/generate',
            strtolower($countryCode),
            strtolower($format),
        );

        return $this->request('POST', $path, $apiKey, $baseUrl, $body);
    }

    /**
     * Validate an invoice payload without generating output.
     *
     * @param array<string,mixed> $invoicePayload
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    public function validate(
        string $countryCode,
        array $invoicePayload,
        string $apiKey = '',
        string $baseUrl = '',
    ): array {
        $path = sprintf('/api/v1/invoice/%s/validate', strtolower($countryCode));

        return $this->request('POST', $path, $apiKey, $baseUrl, [
            'invoice' => $invoicePayload,
        ]);
    }

    /**
     * Parse a base64-encoded e-invoice document back into structured data.
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    public function parse(
        string $countryCode,
        string $format,
        string $base64Document,
        string $apiKey = '',
        string $baseUrl = '',
    ): array {
        $path = sprintf(
            '/api/v1/invoice/%s/%s/parse',
            strtolower($countryCode),
            strtolower($format),
        );

        return $this->request('POST', $path, $apiKey, $baseUrl, [
            'data' => $base64Document,
        ]);
    }

    /**
     * Low-level HTTP. Wraps Symfony HttpClient and normalises all error
     * surfaces into a single \RuntimeException via buildErrorMessage().
     *
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    private function request(
        string $method,
        string $path,
        string $apiKey,
        string $baseUrl,
        ?array $body = null,
    ): array {
        if ('' === $apiKey) {
            $msg = 'API key is not configured. Open Invoice-api.xhub settings to add it.';
            $this->logger->error($msg, ['source' => self::LOG_SOURCE]);
            throw new \RuntimeException($msg);
        }
        if ('' === $baseUrl) {
            $msg = 'API base URL is not configured.';
            $this->logger->error($msg, ['source' => self::LOG_SOURCE]);
            throw new \RuntimeException($msg);
        }

        $url = rtrim($baseUrl, '/') . $path;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
            ],
            'timeout' => self::DEFAULT_TIMEOUT,
        ];

        if (null !== $body) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $raw      = $response->getContent(false);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error(
                'Invoice-api.xhub transport error: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'method'    => $method,
                    'path'      => $path,
                    'exception' => $e,
                ],
            );
            throw new \RuntimeException(
                'Network error talking to invoice-api.xhub: ' . $e->getMessage(),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Invoice-api.xhub unexpected error: ' . $e->getMessage(),
                [
                    'source'    => self::LOG_SOURCE,
                    'method'    => $method,
                    'path'      => $path,
                    'exception' => $e,
                ],
            );
            throw new \RuntimeException(
                'Unexpected error talking to invoice-api.xhub: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $this->logger->error(
                sprintf('Invoice-api.xhub returned non-JSON response (HTTP %d).', $status),
                [
                    'source' => self::LOG_SOURCE,
                    'method' => $method,
                    'path'   => $path,
                    'status' => $status,
                    'body'   => substr($raw, 0, 1024),
                ],
            );
            throw new \RuntimeException(
                sprintf('Invoice API returned a non-JSON response (HTTP %d).', $status),
            );
        }

        if ($status < 200 || $status >= 300) {
            $parsed['statusCode'] = $status;
            $parsed['success']    = isset($parsed['success']) ? (bool) $parsed['success'] : false;

            $message = $this->buildErrorMessage($parsed)
                ?? sprintf('Invoice API HTTP error %d.', $status);

            $this->logger->error(
                'Invoice-api.xhub HTTP error: ' . $message,
                [
                    'source'   => self::LOG_SOURCE,
                    'method'   => $method,
                    'path'     => $path,
                    'status'   => $status,
                    'response' => $parsed,
                ],
            );
            throw new \RuntimeException($message);
        }

        if (!isset($parsed['success'])) {
            $parsed['success'] = true;
        }

        // Some endpoints (validate) return success=true with valid=false +
        // errors[] — caller decides what to do. Other 2xx with success=false
        // still surfaces an exception so callers cannot accidentally accept
        // a failed payload.
        if (false === $parsed['success']) {
            $message = $this->buildErrorMessage($parsed) ?? 'Invoice API reported failure.';
            $this->logger->error(
                'Invoice-api.xhub logical failure: ' . $message,
                [
                    'source'   => self::LOG_SOURCE,
                    'method'   => $method,
                    'path'     => $path,
                    'response' => $parsed,
                ],
            );
            throw new \RuntimeException($message);
        }

        return $parsed;
    }

    /**
     * Build a human-readable error string from a failed API response.
     * Handles Zod-style array errors, business-rule errors{field,message},
     * complianceErrors[], and string error/message fields.
     *
     * @param array<string,mixed> $response
     */
    public function buildErrorMessage(array $response): ?string
    {
        // Zod-validation-style errors: `error` is an array of {code, path, message}
        if (!empty($response['error']) && is_array($response['error'])) {
            $first = reset($response['error']);
            if (is_array($first) && !empty($first['message'])) {
                $msg = (string) $first['message'];
                if (!empty($first['path']) && is_array($first['path'])) {
                    $msg .= ' (field: ' . implode('.', array_map('strval', $first['path'])) . ')';
                }

                return $msg;
            }
        }

        if (!empty($response['errors']) && is_array($response['errors'])) {
            $first = reset($response['errors']);
            if (is_array($first) && !empty($first['message'])) {
                $msg = (string) $first['message'];
                if (!empty($first['field'])) {
                    $msg .= ' (field: ' . (string) $first['field'] . ')';
                }

                return $msg;
            }
        }

        if (!empty($response['complianceErrors']) && is_array($response['complianceErrors'])) {
            $first = reset($response['complianceErrors']);
            if (is_array($first) && !empty($first['message'])) {
                return (string) $first['message'];
            }
        }

        if (!empty($response['error']) && is_string($response['error'])) {
            return $response['error'];
        }

        if (!empty($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }

        return null;
    }
}
