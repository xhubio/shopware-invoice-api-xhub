<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Xhubio\InvoiceApiXhub\Service\ApiClient;

/**
 * Unit tests for the Symfony-HttpClient wrapper around invoice-api.xhub.io.
 *
 * Exercises generate/validate/parse plus every error-normalisation branch
 * (Zod, business, complianceErrors, string error, message, fallback) using
 * Symfony's MockHttpClient — no real network traffic.
 */
final class ApiClientTest extends TestCase
{
    private const API_KEY  = 'test-key-123';
    private const BASE_URL = 'https://service.invoice-api.xhub.io';

    public function testGeneratePostsToCorrectPathAndReturnsDecodedJson(): void
    {
        $captured = [];
        $http     = new MockHttpClient(function ($method, $url, $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(
                json_encode(['success' => true, 'data' => 'AAEC', 'mimeType' => 'application/pdf', 'filename' => 'INV-1.pdf'], JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new ApiClient($http, new NullLogger());

        $resp = $client->generate('DE', 'XRECHNUNG', ['invoiceNumber' => 'INV-1'], null, self::API_KEY, self::BASE_URL);

        self::assertSame('POST', $captured['method']);
        self::assertSame(self::BASE_URL . '/api/v1/invoice/de/xrechnung/generate', $captured['url']);
        self::assertContains('Authorization: Bearer ' . self::API_KEY, $captured['options']['headers']);
        self::assertContains('Content-Type: application/json', $captured['options']['headers']);
        self::assertTrue($resp['success']);
        self::assertSame('AAEC', $resp['data']);
    }

    public function testGenerateOmitsTemplateIdWhenNullOrEmpty(): void
    {
        $captured = '';
        $http     = new MockHttpClient(function ($method, $url, $options) use (&$captured): MockResponse {
            $captured = $options['body'] ?? '';

            return new MockResponse(json_encode(['success' => true, 'data' => 'X'], JSON_THROW_ON_ERROR));
        });
        $client = new ApiClient($http, new NullLogger());

        $client->generate('de', 'pdf', ['x' => 1], '   ', self::API_KEY, self::BASE_URL);

        self::assertIsString($captured);
        self::assertStringNotContainsString('templateId', $captured);
    }

    public function testGenerateIncludesTemplateIdWhenProvided(): void
    {
        $captured = '';
        $http     = new MockHttpClient(function ($method, $url, $options) use (&$captured): MockResponse {
            $captured = $options['body'] ?? '';

            return new MockResponse(json_encode(['success' => true, 'data' => 'X'], JSON_THROW_ON_ERROR));
        });
        $client = new ApiClient($http, new NullLogger());

        $client->generate('de', 'pdf', ['x' => 1], '  tpl-1  ', self::API_KEY, self::BASE_URL);

        self::assertStringContainsString('"templateId":"tpl-1"', (string) $captured);
    }

    public function testValidatePostsToValidatePath(): void
    {
        $url    = '';
        $http   = new MockHttpClient(function ($m, $u) use (&$url): MockResponse {
            $url = $u;

            return new MockResponse(json_encode(['success' => true, 'valid' => true], JSON_THROW_ON_ERROR));
        });
        $client = new ApiClient($http, new NullLogger());

        $client->validate('AT', ['invoiceNumber' => 'AT-1'], self::API_KEY, self::BASE_URL);

        self::assertSame(self::BASE_URL . '/api/v1/invoice/at/validate', $url);
    }

    public function testParsePostsToParsePathWithBase64Body(): void
    {
        $url  = '';
        $body = '';
        $http = new MockHttpClient(function ($m, $u, $o) use (&$url, &$body): MockResponse {
            $url  = $u;
            $body = $o['body'] ?? '';

            return new MockResponse(json_encode(['success' => true, 'invoice' => []], JSON_THROW_ON_ERROR));
        });
        $client = new ApiClient($http, new NullLogger());

        $client->parse('DE', 'xrechnung', 'BASE64DATA==', self::API_KEY, self::BASE_URL);

        self::assertSame(self::BASE_URL . '/api/v1/invoice/de/xrechnung/parse', $url);
        self::assertStringContainsString('"data":"BASE64DATA=="', (string) $body);
    }

    public function testThrowsWhenApiKeyMissing(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key is not configured');

        $client->generate('de', 'pdf', [], null, '', self::BASE_URL);
    }

    public function testThrowsWhenBaseUrlMissing(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API base URL is not configured');

        $client->generate('de', 'pdf', [], null, self::API_KEY, '');
    }

    public function testThrowsOnHttp4xxWithZodStyleError(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                json_encode([
                    'success' => false,
                    'error'   => [
                        ['code' => 'invalid_string', 'message' => 'Invalid VAT id', 'path' => ['seller', 'vatId']],
                    ],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 422],
            ),
        ]);
        $client = new ApiClient($http, new NullLogger());

        try {
            $client->generate('de', 'pdf', [], null, self::API_KEY, self::BASE_URL);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Invalid VAT id', $e->getMessage());
            self::assertStringContainsString('seller.vatId', $e->getMessage());
        }
    }

    public function testThrowsOnHttp5xxFallsBackToHttpErrorMessage(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['anything' => 'goes'], JSON_THROW_ON_ERROR), ['http_code' => 503]),
        ]);
        $client = new ApiClient($http, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice API HTTP error 503');

        $client->generate('de', 'pdf', [], null, self::API_KEY, self::BASE_URL);
    }

    public function testThrowsOnNonJsonResponse(): void
    {
        $http = new MockHttpClient([
            new MockResponse('<html>bad gateway</html>', ['http_code' => 502]),
        ]);
        $client = new ApiClient($http, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-JSON response');

        $client->generate('de', 'pdf', [], null, self::API_KEY, self::BASE_URL);
    }

    public function testThrowsOnSuccessFalseEvenWith2xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['success' => false, 'message' => 'logical failure here'], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            ),
        ]);
        $client = new ApiClient($http, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('logical failure here');

        $client->generate('de', 'pdf', [], null, self::API_KEY, self::BASE_URL);
    }

    public function testTransportErrorIsWrappedInRuntimeException(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
        ]);
        $client = new ApiClient($http, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Network error talking to invoice-api.xhub');

        $client->generate('de', 'pdf', [], null, self::API_KEY, self::BASE_URL);
    }

    public function testBuildErrorMessageHandlesBusinessRuleError(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        $msg = $client->buildErrorMessage([
            'errors' => [
                ['field' => 'leitwegId', 'message' => 'Leitweg-ID required for B2G'],
            ],
        ]);

        self::assertSame('Leitweg-ID required for B2G (field: leitwegId)', $msg);
    }

    public function testBuildErrorMessageHandlesComplianceErrors(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        $msg = $client->buildErrorMessage([
            'complianceErrors' => [
                ['message' => 'BR-CO-15 mismatch'],
            ],
        ]);

        self::assertSame('BR-CO-15 mismatch', $msg);
    }

    public function testBuildErrorMessageHandlesStringErrorAndMessage(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        self::assertSame('plain string error', $client->buildErrorMessage(['error' => 'plain string error']));
        self::assertSame('plain message', $client->buildErrorMessage(['message' => 'plain message']));
    }

    public function testBuildErrorMessageReturnsNullForUnknownShape(): void
    {
        $client = new ApiClient(new MockHttpClient(), new NullLogger());

        self::assertNull($client->buildErrorMessage([]));
        self::assertNull($client->buildErrorMessage(['unrelated' => 'noise']));
    }

    public function testBaseUrlTrailingSlashIsNormalised(): void
    {
        $url  = '';
        $http = new MockHttpClient(function ($m, $u) use (&$url): MockResponse {
            $url = $u;

            return new MockResponse(json_encode(['success' => true, 'data' => 'X'], JSON_THROW_ON_ERROR));
        });
        $client = new ApiClient($http, new NullLogger());

        $client->generate('DE', 'pdf', [], null, self::API_KEY, self::BASE_URL . '/');

        self::assertSame(self::BASE_URL . '/api/v1/invoice/de/pdf/generate', $url);
    }
}
