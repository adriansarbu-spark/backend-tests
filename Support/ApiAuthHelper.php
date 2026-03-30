<?php

declare(strict_types=1);

use GuzzleHttp\Client;

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
        $client = new Client([
            'http_errors' => false,
            'timeout' => 20,
        ]);

        $response = $client->request('POST', AUTH_URL, [
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
        ]);

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
        $client = new Client([
            'http_errors' => false,
            'timeout' => 30,
        ]);

        $headers = $options['headers'] ?? [];
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = $bearer;
        $headers['x-backend-authenticator'] = 'keycloak';
        $options['headers'] = $headers;

        $response = $client->request($method, $url, $options);
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
}

