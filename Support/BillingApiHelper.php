<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/AccountCompaniesApiHelper.php';

final class BillingApiHelper
{
    /** @var list<string> */
    private const INCLUDED_GET_PATHS = [
        'billing/catalog',
        'billing/company_overview',
        'billing/entitlement_grants',
        'billing/entitlements',
        'billing/ledger_role_consumption',
        'billing/ledger_role_grants',
        'billing/me',
        'billing/seats',
    ];

    public static function url(string $path, array $query = []): string
    {
        $url = rtrim(resolveTestConfig('API_URL'), '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** @return list<string> */
    public static function includedGetPaths(): array
    {
        return self::INCLUDED_GET_PATHS;
    }

    public static function assertRequiredConfigOrSkip(): void
    {
        assertTestConfigKeysOrSkip([
            'API_URL',
            'AUTH_URL',
            'CLIENT_ID',
            'CLIENT_SECRET',
            'TEST_USER_1_EMAIL',
            'TEST_USER_1_PASSWORD',
            'TEST_USER_1_PERSONAL_ROLE_UUID',
            'TEST_USER_2_EMAIL',
            'TEST_USER_2_PASSWORD',
            'TEST_USER_2_PERSONAL_ROLE_UUID',
        ]);
    }

    public static function bearerForUser1Personal(): string
    {
        return AccountCompaniesApiHelper::bearerForUser1Personal();
    }

    public static function bearerForUser2Personal(): string
    {
        return AccountCompaniesApiHelper::bearerForUser2Personal();
    }

    public static function bearerForUser1Admin(): string
    {
        assertTestConfigKeysOrSkip(['BILLING_TEST_COMPANY_ADMIN_UUID']);
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_1_EMAIL'),
            resolvedTestConfigValue('TEST_USER_1_PASSWORD'),
        );
        $roleUuid = resolvedTestConfigValue('BILLING_TEST_COMPANY_ADMIN_UUID');
        [$status, $json, $raw] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
        if ($status !== 200) {
            test()->markTestSkipped(
                'Could not switch TEST_USER_1 to BILLING_TEST_COMPANY_ADMIN_UUID (status=' . $status
                . ', error=' . self::joinedErrors($json)
                . ', raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw) . ').',
            );
        }

        return $bearer;
    }

    public static function bearerForUser1NonAdminCompanyRole(): string
    {
        assertTestConfigKeysOrSkip(['BILLING_TEST_COMPANY_NON_ADMIN_UUID']);
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_1_EMAIL'),
            resolvedTestConfigValue('TEST_USER_1_PASSWORD'),
        );
        $roleUuid = resolvedTestConfigValue('BILLING_TEST_COMPANY_NON_ADMIN_UUID');
        [$status, $json, $raw] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
        if ($status !== 200) {
            test()->markTestSkipped(
                'Could not switch TEST_USER_1 to BILLING_TEST_COMPANY_NON_ADMIN_UUID (status=' . $status
                . ', error=' . self::joinedErrors($json)
                . ', raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw) . ').',
            );
        }

        return $bearer;
    }

    /**
     * @param string|list<string> $roleCodes
     */
    public static function findUserRoleUuid(string $bearer, string|array $roleCodes): ?string
    {
        $roleCodes = (array) $roleCodes;
        [$status, $json] = AccountCompaniesApiHelper::get($bearer);
        if ($status !== 200 || !is_array($json)) {
            return null;
        }
        foreach ((array) ($json['data']['companies'] ?? []) as $company) {
            if (!is_array($company) || !empty($company['is_personal'])) {
                continue;
            }
            foreach ((array) ($company['roles'] ?? []) as $role) {
                if (!is_array($role) || !in_array((string) ($role['role_code'] ?? ''), $roleCodes, true)) {
                    continue;
                }
                $uuid = trim((string) ($role['role_uuid'] ?? ''));
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function get(string $path, ?string $bearer = null, array $query = [], array $headers = []): array
    {
        return self::request('GET', $path, $bearer, null, $query, $headers);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function post(string $path, ?string $bearer, array $payload, array $headers = []): array
    {
        return self::request('POST', $path, $bearer, $payload, [], $headers);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function request(
        string $method,
        string $path,
        ?string $bearer = null,
        ?array $payload = null,
        array $query = [],
        array $headers = [],
    ): array {
        $options = ['headers' => $headers];
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        if ($bearer !== null) {
            return ApiAuthHelper::apiRequest($method, self::url($path, $query), $bearer, $options);
        }

        $options['headers']['Accept'] = 'application/json';
        $options['headers']['x-backend-authenticator'] = 'keycloak';
        $response = ApiAuthHelper::guzzleRequest($method, self::url($path, $query), $options);
        $status = (int) $response->getStatusCode();
        $raw = (string) $response->getBody();
        $json = json_decode($raw, true);

        return [$status, is_array($json) ? $json : null, $raw];
    }

    /** @param array<string, mixed>|null $json */
    public static function joinedErrors(?array $json): string
    {
        if (!is_array($json)) {
            return '';
        }
        $errors = $json['error'] ?? [];
        if (is_string($errors)) {
            return $errors;
        }

        return implode(' | ', array_map('strval', (array) $errors));
    }

    /** @param array<string, mixed> $query */
    public static function ledgerQuery(array $query = []): array
    {
        return array_filter([
            'page' => $query['page'] ?? 1,
            'per_page' => $query['per_page'] ?? 20,
            'sort' => $query['sort'] ?? null,
            'order' => $query['order'] ?? null,
            'search' => $query['search'] ?? null,
            'state' => $query['state'] ?? null,
            'occurred_start' => $query['occurred_start'] ?? null,
            'occurred_end' => $query['occurred_end'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed>|null $json */
    public static function assertSuccessfulEnvelope(int $status, ?array $json, string $raw = ''): void
    {
        $debug = self::debug($status, $json, $raw);
        expect($status)->toBe(200, $debug)
            ->and(is_array($json))->toBeTrue($debug)
            ->and($json)->toHaveKey('data');
    }

    /** @param array<string, mixed>|null $json */
    public static function assertFailure(int $status, ?array $json, int|array $expectedStatus): void
    {
        expect((array) $expectedStatus)->toContain($status)
            ->and(self::joinedErrors($json))->not->toBe('');
    }

    /**
     * Local HTTP-guard failure: expected status, non-empty error, and no Stripe portal/session URL.
     *
     * @param array<string, mixed>|null $json
     * @param int|list<int> $expectedStatus
     */
    public static function assertLocalGuardFailure(int $status, ?array $json, int|array $expectedStatus): void
    {
        self::assertFailure($status, $json, $expectedStatus);
        $url = is_array($json) ? ($json['data']['url'] ?? null) : null;
        expect($url === null || $url === '')->toBeTrue(
            'Local billing guard leaked a Stripe portal URL. ' . self::debug($status, $json),
        );
    }

    /**
     * Portal (and similar) controllers check Stripe SDK/config before later local guards.
     * Skip rather than fail when that bootstrap blocks the HTTP path under test.
     *
     * @param array<string, mixed>|null $json
     */
    public static function skipIfStripeBootstrapBlocks(int $status, ?array $json, string $raw = ''): void
    {
        if ($status !== 503) {
            return;
        }
        test()->markTestSkipped(
            'Stripe SDK or config is unavailable (HTTP 503); a later local guard cannot be reached over HTTP. '
            . self::debug($status, $json, $raw),
        );
    }

    /** @param array<string, mixed>|null $json */
    public static function debug(int $status, ?array $json, string $raw = ''): string
    {
        return 'status=' . $status
            . '; json=' . substr((string) json_encode($json), 0, 500)
            . '; raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw, 500);
    }

    public static function isZulu(?string $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;
    }
}

