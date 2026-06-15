<?php

declare(strict_types=1);

use GuzzleHttp\Client;

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/AccountCompaniesApiHelper.php';

/**
 * HTTP helpers for team public API integration tests
 * ({@see ControllerPublicAPIV1TeamInvitations}, {@see ControllerPublicAPIV1TeamMembers}).
 */
final class TeamApiHelper
{
    public static function invitationsUrl(): string
    {
        return API_URL . 'team/invitations';
    }

    public static function invitationsRevokeUrl(): string
    {
        return API_URL . 'team/invitations/revoke';
    }

    public static function invitationsResendUrl(): string
    {
        return API_URL . 'team/invitations/resend';
    }

    public static function membersUrl(): string
    {
        return API_URL . 'team/members';
    }

    public static function membersTerminateUrl(string $roleUuid): string
    {
        return API_URL . 'team/members/' . rawurlencode($roleUuid) . '/terminate';
    }

    /**
     * Keycloak + primary test user (company admin flows).
     */
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
     * Two-account scenarios (cross-company / privilege checks).
     */
    public static function assertUserTwoConfigOrSkip(): void
    {
        self::assertRequiredConfigOrSkip();
        $required = [
            'TEST_USER_2_EMAIL' => defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : '',
            'TEST_USER_2_PASSWORD' => defined('TEST_USER_2_PASSWORD') ? TEST_USER_2_PASSWORD : '',
        ];
        foreach ($required as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                test()->markTestSkipped("Missing required test config constant: {$key}");
            }
        }
    }

    /**
     * Terminate scenarios need TOTP for accounts that enroll it.
     */
    public static function assertTotpConfigOrSkip(): void
    {
        self::assertRequiredConfigOrSkip();
        $totp = defined('TEST_USER_1_TOTP_SECRET') ? TEST_USER_1_TOTP_SECRET : '';
        if (!is_string($totp) || trim($totp) === '') {
            test()->markTestSkipped('Missing required test config constant: TEST_USER_1_TOTP_SECRET');
        }
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

    /**
     * True when the API refused the call because the active session is not a company admin.
     * Team invitation/member endpoints return this when TEST_USER_1 is not admin for the active company.
     */
    public static function isAdminRoleRequired(int $status, ?array $json): bool
    {
        return (int)$status === 403 && str_contains(self::joinedErrors($json), 'admin_role_required');
    }

    /**
     * Skip the current test when the bearer is not a company admin (integration env mismatch).
     */
    public static function skipIfAdminRoleRequired(int $status, ?array $json, string $context = ''): void
    {
        if (!self::isAdminRoleRequired($status, $json)) {
            return;
        }
        $suffix = $context !== '' ? ' ' . $context : '';
        test()->markTestSkipped(
            'Company admin required: session is not company admin (admin_role_required).'
            . ' Use POST /account/active-role with an admin role for TEST_USER_1, or adjust test config.'
            . $suffix
        );
    }

    /**
     * Caller has no company context (personal session or wrong role) — used by revoke/resend before admin gate.
     */
    public static function isCompanyMembershipRequiredError(?array $json): bool
    {
        $e = self::joinedErrors($json);

        return str_contains($e, 'company_context_required') || str_contains($e, 'company_role_required');
    }

    /**
     * Outsider was blocked from mutating another tenant’s invitation (acceptable outcomes for isolation tests).
     */
    public static function isOutsiderTeamInvitationMutationBlocked(int $status, ?array $json): bool
    {
        if (!is_array($json)) {
            return false;
        }
        $e = self::joinedErrors($json);
        if ($status === 404 && str_contains($e, 'invitation_not_found')) {
            return true;
        }
        if ($status === 403 && (str_contains($e, 'admin_role_required') || str_contains($e, 'access_denied'))) {
            return true;
        }
        if ($status === 400 && self::isCompanyMembershipRequiredError($json)) {
            return true;
        }

        return false;
    }

    /**
     * Bearer for team admin flows: activate first available company **admin** role, or create a company
     * (POST /account/companies) then POST /account/active-role with returned **role_uuid**.
     *
     * @return string same bearer token string (session company/role updated server-side)
     */
    public static function bearerWithActiveCompanyAdminRole(string $email, string $password): string
    {
        $bearer = ApiAuthHelper::bearerTokenFor($email, $password);
        $lastSwitchStatus = 0;
        $lastSwitchRaw = '';
        $pst = 0;
        $pjson = null;

        [$gst, $gjson, $geraw] = AccountCompaniesApiHelper::get($bearer);
        if ($gst === 200 && is_array($gjson)) {
            foreach ((array)($gjson['data']['companies'] ?? []) as $company) {
                if (!is_array($company)) {
                    continue;
                }
                foreach ((array)($company['roles'] ?? []) as $role) {
                    if (!is_array($role)) {
                        continue;
                    }
                    if (mb_strtolower((string)($role['role_code'] ?? '')) !== 'admin') {
                        continue;
                    }
                    $roleUuid = trim((string)($role['role_uuid'] ?? ''));
                    if ($roleUuid === '') {
                        continue;
                    }
                    [$sst, , $sraw] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
                    $lastSwitchStatus = $sst;
                    $lastSwitchRaw = (string)$sraw;
                    if ($sst === 200) {
                        return $bearer;
                    }
                }
            }
        }

        $token = 'teamco-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
        $body = AccountCompaniesApiHelper::validCreatePayload($token);
        [$pst, $pjson, $praw] = AccountCompaniesApiHelper::postJson($bearer, $body);
        if ($pst === 200 && is_array($pjson)) {
            $roleUuid = trim((string)($pjson['data']['role_uuid'] ?? ''));
            if ($roleUuid !== '') {
                [$ast, , $araw] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
                $lastSwitchStatus = $ast;
                $lastSwitchRaw = (string)$araw;
                if ($ast === 200) {
                    return $bearer;
                }
            }
        }

        $createErrs = is_array($pjson) ? AccountCompaniesApiHelper::joinedErrors($pjson) : '';

        test()->markTestSkipped(
            'Could not activate a company admin role for team tests. '
            . 'GET /account/companies status=' . $gst . ' raw=' . substr((string)$geraw, 0, 200)
            . '; POST /account/companies status=' . $pst . ' errors=' . $createErrs
            . '; POST /account/active-role last status=' . $lastSwitchStatus . ' raw=' . substr($lastSwitchRaw, 0, 400)
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function requestWithoutAuth(string $method, string $url): array
    {
        $client = new Client([
            'http_errors' => false,
            'timeout' => 30,
        ]);

        $response = $client->request($method, $url, [
            'headers' => [
                'Accept' => 'application/json',
                'x-backend-authenticator' => 'keycloak',
            ],
        ]);

        $status = (int)$response->getStatusCode();
        $raw = (string)$response->getBody();
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : null, $raw];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postJson(string $url, string $bearer, array $body): array
    {
        return ApiAuthHelper::apiRequest('POST', $url, $bearer, [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function get(string $url, string $bearer): array
    {
        return ApiAuthHelper::apiRequest('GET', $url, $bearer);
    }
}
