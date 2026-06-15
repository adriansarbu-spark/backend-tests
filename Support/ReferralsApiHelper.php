<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';

final class ReferralsApiHelper
{
    public static function referralsUrl(): string
    {
        return API_URL . 'referrals';
    }

    public static function validateUrl(string $code): string
    {
        return API_URL . 'referrals/validate?code=' . rawurlencode($code);
    }

    public static function statusUrl(): string
    {
        return API_URL . 'referrals/status';
    }

    public static function campaignUrl(): string
    {
        return API_URL . 'referrals/campaign';
    }

    public static function inviteUrl(): string
    {
        return API_URL . 'referrals/invite';
    }

    public static function inviteSendUrl(): string
    {
        return API_URL . 'referrals/invite/send';
    }

    public static function remindUrl(): string
    {
        return API_URL . 'referrals/remind';
    }

    public static function assertRequiredConfigOrSkip(): void
    {
        $required = [
            'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
            'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
            'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
            'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
            'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
        ];

        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function getJson(string $url, ?string $bearer = null): array
    {
        return ApiAuthHelper::apiRequest('GET', $url, $bearer ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postJson(string $url, ?string $bearer, array $payload): array
    {
        return ApiAuthHelper::apiRequest('POST', $url, $bearer ?? '', [
            'json' => $payload,
        ]);
    }

    public static function joinedErrors(?array $json): string
    {
        if (!is_array($json)) {
            return '';
        }
        $err = $json['error'] ?? null;
        if (is_string($err)) {
            return $err;
        }
        if (is_array($err)) {
            return implode(', ', array_map('strval', $err));
        }

        return '';
    }

    public static function bearerForTestUser1(): string
    {
        return ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    }
}
