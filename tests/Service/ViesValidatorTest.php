<?php

declare(strict_types=1);

namespace Xhubio\InvoiceApiXhub\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Xhubio\InvoiceApiXhub\Service\ViesValidator;

/**
 * Unit tests for the EU VIES VAT-ID pre-validator.
 *
 * Uses Symfony's MockHttpClient to drive the validator without hitting the
 * actual ec.europa.eu endpoint. All tests stay fully offline.
 */
final class ViesValidatorTest extends TestCase
{
    public function testReturnsTrueForValidVatId(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['isValid' => true, 'name' => 'Acme GmbH'], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            ),
        ]);

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('DE', 'DE123456789'));
    }

    public function testReturnsFalseForInvalidVatId(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['isValid' => false, 'name' => '---'], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            ),
        ]);

        $validator = new ViesValidator($http, new NullLogger());

        self::assertFalse($validator->validate('DE', 'DE000000000'));
    }

    public function testFailsOpenOnTransportError(): void
    {
        // VIES is famously down 10–15% of the time. We must NOT block sales
        // when the endpoint isn't reachable — fail-open is the design contract.
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new TransportException('Connection refused');
        });

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('DE', 'DE123456789'));
    }

    public function testFailsOpenOnHttp5xx(): void
    {
        $http = new MockHttpClient([
            new MockResponse('Service Unavailable', ['http_code' => 503]),
        ]);

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('DE', 'DE123456789'));
    }

    public function testFailsOpenOnNonJsonResponse(): void
    {
        $http = new MockHttpClient([
            new MockResponse('<html>maintenance</html>', ['http_code' => 200]),
        ]);

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('DE', 'DE123456789'));
    }

    public function testCacheHitDoesNotCallApiTwice(): void
    {
        $callCount = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$callCount): MockResponse {
            $callCount++;

            return new MockResponse(
                json_encode(['isValid' => true], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });

        $validator = new ViesValidator($http, new NullLogger());

        $validator->validate('DE', 'DE123456789');
        $validator->validate('DE', 'DE123456789');
        $validator->validate('DE', 'DE 123 456 789');   // same number, formatted
        $validator->validate('DE', 'de123456789');       // case-insensitive

        self::assertSame(1, $callCount, 'cache must collapse repeated lookups to a single API call');
    }

    public function testNonEuCountryReturnsTrueWithoutCallingApi(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::fail('Validator must not call VIES for non-EU countries');
        });

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('CH', 'CHE123456789'));
        self::assertTrue($validator->validate('NO', '987654321'));
        self::assertTrue($validator->validate('US', '12-3456789'));
    }

    public function testEmptyVatNumberReturnsTrueWithoutCallingApi(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::fail('Validator must not call VIES with an empty VAT-ID');
        });

        $validator = new ViesValidator($http, new NullLogger());

        self::assertTrue($validator->validate('DE', ''));
        self::assertTrue($validator->validate('DE', '   '));
    }

    public function testStripsCountryPrefixFromVatNumber(): void
    {
        $captured = null;
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse(
                json_encode(['isValid' => true], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });

        $validator = new ViesValidator($http, new NullLogger());
        $validator->validate('DE', 'DE123456789');

        self::assertNotNull($captured);
        self::assertArrayHasKey('body', $captured);
        $body = json_decode((string) $captured['body'], true);
        self::assertIsArray($body);
        self::assertSame('DE', $body['countryCode']);
        self::assertSame('123456789', $body['vatNumber']);
    }
}
