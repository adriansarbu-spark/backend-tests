<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/AccountCompaniesApiHelper.php';

/**
 * HTTP helpers for public `/sponsorships/*` Feature tests.
 *
 * Sponsor-console routes use TEST_USER_1 as company admin
 * ({@see BILLING_TEST_COMPANY_ADMIN_UUID}). Invite tests skip when that
 * company has no partner program (`program_not_found` on the invite POST).
 */
final class SponsorshipsApiHelper
{
    public static function url(string $path, array $query = []): string
    {
        $url = rtrim(resolveTestConfig('API_URL'), '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** @param array<string, mixed> $query */
    public static function invitationsUrl(array $query = []): string
    {
        return self::url('sponsorships/invitations', $query);
    }

    public static function invitationsRevokeUrl(): string
    {
        return self::url('sponsorships/invitations/revoke');
    }

    public static function invitationsResendUrl(): string
    {
        return self::url('sponsorships/invitations/resend');
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
            'TEST_USER_2_EMAIL',
            'TEST_USER_2_PASSWORD',
            'TEST_USER_2_PERSONAL_ROLE_UUID',
            'BILLING_TEST_COMPANY_ADMIN_UUID',
        ]);
    }

    public static function bearerForUser1Admin(): string
    {
        self::assertRequiredConfigOrSkip();
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_1_EMAIL'),
            resolvedTestConfigValue('TEST_USER_1_PASSWORD'),
        );
        $roleUuid = resolvedTestConfigValue('BILLING_TEST_COMPANY_ADMIN_UUID');
        [$status, $json, $raw] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
        if ($status !== 200) {
            self::skip(
                'Could not switch TEST_USER_1 to BILLING_TEST_COMPANY_ADMIN_UUID (status=' . $status
                . ', error=' . self::joinedErrors($json)
                . ', raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw) . ').',
            );
        }

        return $bearer;
    }

    public static function bearerForUser2Personal(): string
    {
        return AccountCompaniesApiHelper::bearerForUser2Personal();
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function getJson(string $url, ?string $bearer = null): array
    {
        return self::request('GET', $url, $bearer);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postJson(string $url, ?string $bearer, array $payload): array
    {
        return self::request('POST', $url, $bearer, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function request(string $method, string $url, ?string $bearer = null, ?array $payload = null): array
    {
        $options = [];
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        if ($bearer !== null && $bearer !== '') {
            return ApiAuthHelper::apiRequest($method, $url, $bearer, $options);
        }

        $options['headers'] = [
            'Accept' => 'application/json',
            'x-backend-authenticator' => 'keycloak',
        ];
        $response = ApiAuthHelper::guzzleRequest($method, $url, $options);
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
        $err = $json['error'] ?? null;
        if (is_string($err)) {
            return $err;
        }
        if (is_array($err)) {
            return implode(', ', array_map('strval', $err));
        }

        return '';
    }

    /** @param array<string, mixed>|null $json */
    public static function debug(int $status, ?array $json, string $raw = ''): string
    {
        return 'status=' . $status
            . '; json=' . substr((string) json_encode($json), 0, 500)
            . '; raw=' . ApiAuthHelper::sanitizeRawForTestMessage($raw, 400);
    }

    /**
     * Unique partner_delivered external_ref (required for that distribution).
     */
    public static function uniqueExternalRef(string $suffix = 'ref'): string
    {
        $suffix = trim($suffix) !== '' ? preg_replace('/[^a-zA-Z0-9_-]+/', '-', $suffix) : 'ref';

        return 'sp-' . $suffix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Skip when this company has no partner program (invite POST is the signal).
     *
     * @param array<string, mixed>|null $json
     */
    public static function skipIfProgramNotFound(int $status, ?array $json, string $raw = ''): void
    {
        if ($status === 404 && str_contains(self::joinedErrors($json), 'program_not_found')) {
            self::skip(
                'No partner program for the billing test company (invite returned 404 program_not_found). '
                . self::debug($status, $json, $raw),
            );
        }
    }

    /**
     * Best-effort partner revoke for Feature cleanup. Never fails the test.
     */
    public static function revokeLinkQuietly(?string $bearer, string $linkUuid): void
    {
        $linkUuid = trim($linkUuid);
        if ($bearer === null || $bearer === '' || $linkUuid === '') {
            return;
        }

        try {
            self::postJson(self::invitationsRevokeUrl(), $bearer, ['link_uuid' => $linkUuid]);
        } catch (Throwable) {
            // Cleanup must not mask the scenario under test.
        }
    }

    /** @param array<string, mixed>|null $json */
    public static function invitationLinkUuid(?array $json): string
    {
        return trim((string) ($json['data']['invitation']['link_uuid'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $json
     * @return list<array<string, mixed>>
     */
    public static function rosterInvitations(?array $json): array
    {
        $rows = $json['data']['invitations'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>|null
     */
    public static function findRosterInvitationByUuid(?array $json, string $linkUuid): ?array
    {
        $linkUuid = trim($linkUuid);
        if ($linkUuid === '') {
            return null;
        }
        foreach (self::rosterInvitations($json) as $row) {
            if (trim((string) ($row['link_uuid'] ?? '')) === $linkUuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * True when a non-owner was refused the sponsor console. HTTP 200 is never a denial.
     *
     * @param array<string, mixed>|null $json
     */
    public static function isSponsorshipsConsoleDenied(int $status, ?array $json): bool
    {
        if ($status === 200) {
            return false;
        }
        $errors = self::joinedErrors($json);
        if ($errors === '') {
            return false;
        }

        return ($status === 401 && str_contains($errors, 'unauthenticated'))
            || ($status === 400 && (
                str_contains($errors, 'company_context_required')
                || str_contains($errors, 'company_role_required')
            ))
            || ($status === 403 && (
                str_contains($errors, 'admin_role_required')
                || str_contains($errors, 'access_denied')
            ))
            || ($status === 404 && (
                str_contains($errors, 'program_not_found')
                || str_contains($errors, 'link_not_found')
            ));
    }

    private static function skip(string $message): never
    {
        \PHPUnit\Framework\Assert::markTestSkipped($message);
    }
}
