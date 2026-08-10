<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';

/**
 * HTTP helpers for {@see ControllerPublicAPIV1AccountCompanies} integration tests.
 */
final class AccountCompaniesApiHelper
{
    /**
     * Base URL for GET/POST `/publicapi/v1/account/companies`.
     */
    public static function url(): string
    {
        return resolveTestConfig('API_URL') . 'account/companies';
    }

    /**
     * POST /publicapi/v1/account/active-role — persist default company/role for the session.
     */
    public static function activeRoleUrl(): string
    {
        return resolveTestConfig('API_URL') . 'account/active-role';
    }

    /**
     * Skip the suite when Keycloak or primary test users are not configured.
     */
    public static function assertRequiredConfigOrSkip(): void
    {
        assertTestConfigKeysOrSkip([
            'AUTH_URL',
            'CLIENT_ID',
            'CLIENT_SECRET',
            'TEST_USER_1_EMAIL',
            'TEST_USER_1_PASSWORD',
            'TEST_USER_2_EMAIL',
            'TEST_USER_2_PASSWORD',
        ]);
    }

    /**
     * JSON POST with bearer (same stack as {@see ApiAuthHelper::apiRequest}).
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postJson(string $bearer, array $body): array
    {
        return ApiAuthHelper::apiRequest('POST', self::url(), $bearer, [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * GET with bearer.
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function get(string $bearer): array
    {
        return ApiAuthHelper::apiRequest('GET', self::url(), $bearer);
    }

    /**
     * Request without an Authorization header (browser-like anonymous call).
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function requestWithoutAuth(string $method): array
    {
        $response = ApiAuthHelper::guzzleRequest($method, self::url(), [
            'headers' => [
                'Accept' => 'application/json',
                'x-backend-authenticator' => 'keycloak',
            ],
        ]);

        $status = (int)$response->getStatusCode();
        $raw = (string)$response->getBody();
        $json = json_decode($raw, true);

        return [$status, is_array($json) ? $json : null, $raw];
    }

    /**
     * Minimal valid create body with unique identifiers safe for dev DB inserts.
     *
     * @return array<string, mixed>
     */
    public static function validCreatePayload(string $uniqueToken): array
    {
        $digits = preg_replace('/\D/', '', $uniqueToken);
        if ($digits === '') {
            $digits = (string)random_int(10000000, 99999999);
        }
        $digits = substr($digits, 0, 10);
        if (strlen($digits) < 6) {
            $digits .= (string)random_int(100000, 999999);
        }

        $block = [
            'street' => 'Str. Feature Test',
            'street_no' => '1',
            'details' => '',
            'jud_cod' => 10,
            'loc_cod' => 1,
        ];

        return [
            'name' => 'Feature API Co ' . $uniqueToken,
            'country' => 'RO',
            'tin' => 'RO ' . substr($digits, 0, 5) . ' ' . substr($digits, 5),
            'registration_number' => 'J40/feat/' . $uniqueToken,
            'fiscal_address' => $block,
            'headquarters_address' => $block,
        ];
    }

    /**
     * @param list<array<string, mixed>> $companies
     */
    public static function findCompanyByUuid(array $companies, string $companyUuid): ?array
    {
        foreach ($companies as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['company_uuid'] ?? '') === $companyUuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public static function joinedErrors(?array $json): string
    {
        if (!is_array($json)) {
            return '';
        }

        return implode(' | ', array_map('strval', (array)($json['error'] ?? [])));
    }

    public static function assertRepresentativeRoleConfigOrSkip(): void
    {
        $value = isTestConfigDefined('TEST_USER_1_COMPANY_ID_REPRESENTATIVE')
            ? resolvedTestConfigValue('TEST_USER_1_COMPANY_ID_REPRESENTATIVE')
            : '';
        if ($value === '') {
            test()->markTestSkipped('Missing required test config constant: TEST_USER_1_COMPANY_ID_REPRESENTATIVE');
        }
    }

    /**
     * Resolve role_uuid for Company Representative context (TEST_USER_1).
     *
     * Matches {@see resolvedTestConfigValue('TEST_USER_1_COMPANY_ID_REPRESENTATIVE')} against listed companies:
     * - company_uuid → first non-personal role in that company
     * - role_uuid → returned as-is
     * - otherwise → constant value used directly (typical dev fixture)
     */
    public static function resolveUser1CompanyRepresentativeRoleUuid(string $bearer): string
    {
        self::assertRepresentativeRoleConfigOrSkip();
        $needle = trim(resolvedTestConfigValue('TEST_USER_1_COMPANY_ID_REPRESENTATIVE'));

        [$status, $json] = self::get($bearer);
        if ($status !== 200 || !is_array($json)) {
            test()->markTestSkipped('GET account/companies failed while resolving Company Representative role (status=' . $status . ').');
        }

        $companies = (array)($json['data']['companies'] ?? []);
        $company = self::findCompanyByUuid($companies, $needle);
        if ($company !== null) {
            foreach ((array)($company['roles'] ?? []) as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $roleUuid = trim((string)($role['role_uuid'] ?? ''));
                if ($roleUuid === '') {
                    continue;
                }
                if (!empty($company['is_personal']) && (string)($role['role_code'] ?? '') === 'personal') {
                    continue;
                }

                return $roleUuid;
            }

            test()->markTestSkipped('No role found under company ' . $needle . ' for Company Representative context.');
        }

        foreach ($companies as $companyRow) {
            if (!is_array($companyRow)) {
                continue;
            }
            foreach ((array)($companyRow['roles'] ?? []) as $role) {
                if (!is_array($role)) {
                    continue;
                }
                if ((string)($role['role_uuid'] ?? '') === $needle) {
                    return $needle;
                }
            }
        }

        return $needle;
    }

    /**
     * Switch persisted active role after Keycloak sign-in.
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function switchActiveRole(string $bearer, string $roleUuid): array
    {
        $roleUuid = trim($roleUuid);
        if ($roleUuid === '') {
            test()->markTestSkipped('Cannot switch active role: role_uuid is empty.');
        }

        return ApiAuthHelper::apiRequest('POST', self::activeRoleUrl(), $bearer, [
            'json' => ['role_uuid' => $roleUuid],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * POST account/active-role for TEST_USER_1 Company Representative context; skips when not allowed.
     */
    public static function switchUser1ToCompanyRepresentativeRole(string $bearer): void
    {
        $roleUuid = self::resolveUser1CompanyRepresentativeRoleUuid($bearer);
        [$status, $json, $raw] = self::switchActiveRole($bearer, $roleUuid);
        if ($status !== 200) {
            test()->markTestSkipped(
                'POST account/active-role failed for Company Representative context (status=' . $status
                . ', errors=' . self::joinedErrors($json) . ', raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw) . ').'
            );
        }
    }

    /**
     * Switch persisted active role to the user's personal company role (integration baseline).
     */
    public static function switchTestUserToPersonalRole(string $bearer, string $roleUuid, string $label): void
    {
        $roleUuid = trim($roleUuid);
        if ($roleUuid === '') {
            test()->markTestSkipped("Missing personal role_uuid for {$label}.");
        }

        [$status, $json, $raw] = self::switchActiveRole($bearer, $roleUuid);
        if ($status !== 200) {
            test()->markTestSkipped(
                "POST account/active-role failed for {$label} personal role (status={$status}, errors="
                . self::joinedErrors($json)
                . ', raw=' . ApiAuthHelper::sanitizeRawForTestMessage((string)$raw) . ').'
            );
        }
    }

    /**
     * Sign in as TEST_USER_1 and POST /account/active-role with resolvedTestConfigValue('TEST_USER_1_PERSONAL_ROLE_UUID').
     */
    public static function bearerForUser1Personal(): string
    {
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_1_EMAIL'),
            resolvedTestConfigValue('TEST_USER_1_PASSWORD')
        );
        self::switchTestUserToPersonalRole($bearer, resolvedTestConfigValue('TEST_USER_1_PERSONAL_ROLE_UUID'), 'TEST_USER_1');

        return $bearer;
    }

    /**
     * Sign in as TEST_USER_2 and POST /account/active-role with resolvedTestConfigValue('TEST_USER_2_PERSONAL_ROLE_UUID').
     */
    public static function bearerForUser2Personal(): string
    {
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_2_EMAIL'),
            resolvedTestConfigValue('TEST_USER_2_PASSWORD')
        );
        self::switchTestUserToPersonalRole($bearer, resolvedTestConfigValue('TEST_USER_2_PERSONAL_ROLE_UUID'), 'TEST_USER_2');

        return $bearer;
    }

    /**
     * Reset TEST_USER_1 session to personal role before each integration scenario.
     */
    public static function ensureTestUser1PersonalActiveRole(): void
    {
        if (!isTestConfigDefined('TEST_USER_1_EMAIL')
            || !isTestConfigDefined('TEST_USER_1_PASSWORD')
            || !isTestConfigDefined('TEST_USER_1_PERSONAL_ROLE_UUID')) {
            return;
        }

        self::bearerForUser1Personal();
    }

    /**
     * Reset TEST_USER_1 and TEST_USER_2 sessions to personal roles before integration scenarios.
     */
    public static function ensureIntegrationUsersPersonalActiveRoles(): void
    {
        self::ensureTestUser1PersonalActiveRole();

        if (!isTestConfigDefined('TEST_USER_2_EMAIL')
            || !isTestConfigDefined('TEST_USER_2_PASSWORD')
            || !isTestConfigDefined('TEST_USER_2_PERSONAL_ROLE_UUID')) {
            return;
        }

        if (resolvedTestConfigValue('TEST_USER_2_EMAIL') === '') {
            return;
        }

        self::bearerForUser2Personal();
    }
}
