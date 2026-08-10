<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';

final class ReferralsApiHelper
{
    public static function referralsUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals';
    }

    public static function validateUrl(string $code): string
    {
        return resolveTestConfig('API_URL') . 'referrals/validate?code=' . rawurlencode($code);
    }

    public static function statusUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals/status';
    }

    public static function campaignUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals/campaign';
    }

    public static function inviteUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals/invite';
    }

    public static function inviteSendUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals/invite/send';
    }

    public static function remindUrl(): string
    {
        return resolveTestConfig('API_URL') . 'referrals/remind';
    }

    public static function assertRequiredConfigOrSkip(): void
    {
        assertTestConfigKeysOrSkip([
            'AUTH_URL',
            'CLIENT_ID',
            'CLIENT_SECRET',
            'TEST_USER_1_EMAIL',
            'TEST_USER_1_PASSWORD',
        ]);
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
        return ApiAuthHelper::bearerTokenFor(resolvedTestConfigValue('TEST_USER_1_EMAIL'), resolvedTestConfigValue('TEST_USER_1_PASSWORD'));
    }
}
