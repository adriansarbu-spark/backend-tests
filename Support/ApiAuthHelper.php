<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Utils as GuzzleUtils;
use Psr\Http\Message\ResponseInterface;

final class ApiAuthHelper
{
    /**
     * Generate a RFC 6238-like TOTP code from a Base32 secret.
     *
     * Used by integration tests to satisfy endpoints that require `totp`,
     * without relying on external authenticator apps.
     */
    public static function getOtpFromTotpSecret(
        string $totpSecret,
        ?int $timestamp = null,
        int $digits = 6,
        int $period = 30
    ): string {
        $normalized = strtoupper(str_replace(' ', '', $totpSecret));
        $normalized = rtrim($normalized, '=');

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split($normalized) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                throw new InvalidArgumentException('Invalid Base32 TOTP secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $secret = '';
        $bitLength = strlen($bits);
        for ($i = 0; $i + 8 <= $bitLength; $i += 8) {
            $secret .= chr(bindec(substr($bits, $i, 8)));
        }

        $counter = intdiv($timestamp ?? time(), $period);
        $counterBytes = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        $offset = ord($hash[19]) & 0x0F;
        $binaryCode = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binaryCode % (10 ** $digits);

        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Authenticate against Keycloak and return an Authorization header value.
     *
     * Endpoint: AUTH_URL (Keycloak token endpoint)
     * Returns: "Bearer <access_token>"
     */
    public static function bearerTokenFor(string $username, string $password): string
    {
        $response = self::guzzleRequest('POST', AUTH_URL, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'password',
                'client_id' => CLIENT_ID,
                'client_secret' => CLIENT_SECRET,
                'username' => $username,
                'password' => $password,
                'scope' => 'openid profile email',
            ],
        ], ['timeout' => 20]);

        $status = (int)$response->getStatusCode();
        $body = (string)$response->getBody();
        $json = json_decode($body, true);

        expect($status)->toBe(200);
        expect(is_array($json))->toBeTrue();
        $token = (string)($json['access_token'] ?? '');
        expect($token)->not->toBe('');

        return preg_match('/^Bearer\s+/i', $token) ? $token : 'Bearer ' . $token;
    }

    /**
     * Make an HTTP request to the API with default headers and safe JSON parsing.
     *
     * - Adds Authorization and common API headers.
     * - Attempts to decode JSON, but returns raw body even for non-JSON responses
     *   (useful for debugging and for binary endpoints like PDFs).
     *
     * Returns: [$statusCode, $jsonOrNull, $rawBody]
     */
    public static function apiRequest(string $method, string $url, string $bearer, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = $bearer;
        $headers['x-backend-authenticator'] = 'keycloak';
        $options['headers'] = $headers;

        $response = self::guzzleRequest($method, $url, $options);
        $status = (int)$response->getStatusCode();
        $body = (string)$response->getBody();
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonStart = strpos($body, '{');
            if ($jsonStart !== false) {
                $json = json_decode(substr($body, $jsonStart), true);
            }
            if (json_last_error() !== JSON_ERROR_NONE) {
                $json = null;
            }
        }

        return [$status, $json, $body];
    }

    /**
     * Guzzle 7.10 + psr7 2.11 sets Content-Length as int in PrepareBodyMiddleware.
     * Pre-setting Content-Length as a string avoids the psr7 3.0 deprecation warning.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function normalizeGuzzleRequestOptions(array $options): array
    {
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }

        if (isset($options['form_params']) && is_array($options['form_params'])) {
            $body = http_build_query($options['form_params'], '', '&', PHP_QUERY_RFC3986);
            $options['body'] = $body;
            unset($options['form_params']);

            if (!self::hasHeader($headers, 'Content-Type')) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
            if (!self::hasHeader($headers, 'Content-Length')) {
                $headers['Content-Length'] = (string) strlen($body);
            }
        }

        if (array_key_exists('json', $options)) {
            $body = GuzzleUtils::jsonEncode($options['json']);
            $options['body'] = $body;
            unset($options['json']);

            if (!self::hasHeader($headers, 'Content-Type')) {
                $headers['Content-Type'] = 'application/json';
            }
            if (!self::hasHeader($headers, 'Content-Length')) {
                $headers['Content-Length'] = (string) strlen($body);
            }
        }

        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $stream = new MultipartStream($options['multipart']);
            $options['body'] = $stream;
            unset($options['multipart']);

            $size = $stream->getSize();
            if ($size !== null && !self::hasHeader($headers, 'Content-Length')) {
                $headers['Content-Length'] = (string) $size;
            }
        }

        $options['headers'] = $headers;

        return $options;
    }

    /**
     * Low-level Guzzle request for integration test helpers.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $clientConfig
     */
    public static function guzzleRequest(
        string $method,
        string $url,
        array $options = [],
        array $clientConfig = []
    ): ResponseInterface {
        $client = new Client(array_merge([
            'http_errors' => false,
            'timeout' => 30,
        ], $clientConfig));

        return $client->request($method, $url, self::normalizeGuzzleRequestOptions($options));
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        $needle = strtolower($name);
        foreach ($headers as $key => $_) {
            if (strtolower((string) $key) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make raw HTTP bodies safe for Pest/Collision console output.
     *
     * HTML fragments (e.g. Font Awesome icons in 404 pages) break Termwind styling
     * when included in skip/warning messages.
     */
    public static function sanitizeRawForTestMessage(string $raw, int $maxLength = 400): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($raw)));

        if ($text === '') {
            return '(empty)';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength) . '...';
    }
}

