<?php

declare(strict_types=1);

use GuzzleHttp\Client;

final class ApiAuthHelper
{
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

