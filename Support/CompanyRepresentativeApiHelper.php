<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/AccountCompaniesApiHelper.php';

/**
 * URLs and small helpers for company representative public API integration tests
 * ({@see ControllerPublicAPIV1CompanyRepresentativeRequests}, {@see ControllerPublicAPIV1CompanyRepresentatives}).
 */
final class CompanyRepresentativeApiHelper
{
    /** Hyphen path is normalized by the router to `representative_requests`. */
    public static function representativeRequestsUrl(): string
    {
        return resolveTestConfig('API_URL') . 'company/representative-requests';
    }

    /**
     * Front-controller URL for a representative-requests sub-route.
     *
     * Pretty extra-path segments are not reliably rewritten into `request_uuid` on this
     * host, and `HTTPS_SERVER` is the local catalog origin (wrong host when
     * TEST_EXECUTOR=remote). Derive index.php from {@see resolveTestConfig('API_URL')}
     * so UUID/action stay in `route` for getRequestUuid()/getRequestAction().
     */
    private static function representativeRequestsIndexRoute(string $suffix): string
    {
        $apiUrl = rtrim(resolveTestConfig('API_URL'), '/');
        $origin = (string)preg_replace('#/publicapi/v1$#i', '', $apiUrl);
        $route = 'publicapi/v1/company/representative_requests';
        if ($suffix !== '') {
            $route .= '/' . ltrim($suffix, '/');
        }

        return $origin . '/index.php?route=' . $route;
    }

    /**
     * GET single request — UUID must stay in the `route` query value (see getRequestUuid()).
     */
    public static function representativeRequestDetailUrl(string $requestUuid): string
    {
        return self::representativeRequestsIndexRoute(rawurlencode($requestUuid));
    }

    /**
     * POST cancel for a request UUID.
     */
    public static function representativeRequestCancelUrl(string $requestUuid): string
    {
        return self::representativeRequestsIndexRoute(rawurlencode($requestUuid) . '/cancel');
    }

    public static function representativesUrl(): string
    {
        return resolveTestConfig('API_URL') . 'company/representatives';
    }

    public static function representativesEligibilityUrl(): string
    {
        return resolveTestConfig('API_URL') . 'company/representatives/eligibility';
    }

    public static function representativesRevokeUrl(string $representativeUuid): string
    {
        return resolveTestConfig('API_URL') . 'company/representatives/' . rawurlencode($representativeUuid) . '/revoke';
    }

    public static function assertPrimaryUserConfigOrSkip(): void
    {
        assertTestConfigKeysOrSkip([
            'AUTH_URL',
            'CLIENT_ID',
            'CLIENT_SECRET',
            'TEST_USER_1_EMAIL',
            'TEST_USER_1_PASSWORD',
        ]);
    }

    public static function assertTwoUsersConfigOrSkip(): void
    {
        self::assertPrimaryUserConfigOrSkip();
        assertTestConfigKeysOrSkip([
            'TEST_USER_2_EMAIL',
            'TEST_USER_2_PASSWORD',
        ]);
    }

    public static function assertTotpUser1ConfigOrSkip(): void
    {
        self::assertPrimaryUserConfigOrSkip();
        assertTestConfigKeysOrSkip(['TEST_USER_1_TOTP_SECRET']);
    }

    public static function assertRepresentativeCompanyConfigOrSkip(): void
    {
        self::assertPrimaryUserConfigOrSkip();
        AccountCompaniesApiHelper::assertRepresentativeRoleConfigOrSkip();
    }

    /**
     * Keycloak bearer for TEST_USER_1 with active role switched via POST account/active-role.
     */
    public static function bearerTokenForUser1AsCompanyRepresentative(): string
    {
        self::assertRepresentativeCompanyConfigOrSkip();
        $bearer = ApiAuthHelper::bearerTokenFor(resolvedTestConfigValue('TEST_USER_1_EMAIL'), resolvedTestConfigValue('TEST_USER_1_PASSWORD'));
        AccountCompaniesApiHelper::switchUser1ToCompanyRepresentativeRole($bearer);

        return $bearer;
    }

    /**
     * Apply Company Representative active role to an existing bearer.
     */
    public static function switchUser1ToCompanyRepresentativeRole(string $bearer): void
    {
        self::assertRepresentativeCompanyConfigOrSkip();
        AccountCompaniesApiHelper::switchUser1ToCompanyRepresentativeRole($bearer);
    }

    /**
     * Keycloak bearer for TEST_USER_2, switched off the Pest.php personal-role baseline
     * onto the first non-personal company role when one exists.
     */
    public static function bearerTokenForUser2CompanyContext(): string
    {
        $bearer = ApiAuthHelper::bearerTokenFor(
            resolvedTestConfigValue('TEST_USER_2_EMAIL'),
            resolvedTestConfigValue('TEST_USER_2_PASSWORD')
        );
        [$status, $json] = AccountCompaniesApiHelper::get($bearer);
        if ($status !== 200 || !is_array($json)) {
            return $bearer;
        }

        foreach ((array)($json['data']['companies'] ?? []) as $company) {
            if (!is_array($company) || !empty($company['is_personal'])) {
                continue;
            }
            foreach ((array)($company['roles'] ?? []) as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $roleUuid = trim((string)($role['role_uuid'] ?? ''));
                if ($roleUuid === '') {
                    continue;
                }
                [$switchStatus] = AccountCompaniesApiHelper::switchActiveRole($bearer, $roleUuid);
                if ($switchStatus === 200) {
                    return $bearer;
                }
            }
        }

        return $bearer;
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
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function requestWithoutAuth(string $method, string $url): array
    {
        $response = ApiAuthHelper::guzzleRequest($method, $url, [
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

    /**
     * Shared fixture path for multipart representative-request tests.
     */
    public static function samplePdfPath(): string
    {
        return dirname(__DIR__) . '/FileSamples/sample.pdf';
    }

    /**
     * POST `multipart/form-data` create representative request with a PDF on the `file` field
     * ({@see ControllerPublicAPIV1CompanyRepresentativeRequests::createRequest}).
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postMultipartCreateRepresentativeRequest(
        string $bearer,
        string $candidateRoleUuid,
        string $absolutePdfPath,
        string $title,
        string $justification
    ): array {
        if (!is_readable($absolutePdfPath)) {
            throw new InvalidArgumentException('PDF path not readable: ' . $absolutePdfPath);
        }

        $handle = fopen($absolutePdfPath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Could not open PDF: ' . $absolutePdfPath);
        }

        $multipart = [
            ['name' => 'candidate_role_uuid', 'contents' => $candidateRoleUuid],
            ['name' => 'title', 'contents' => $title],
            ['name' => 'justification', 'contents' => $justification],
            ['name' => 'document_type', 'contents' => 'supporting_pdf'],
            [
                'name' => 'file',
                'contents' => $handle,
                'filename' => 'sample.pdf',
            ],
        ];

        try {
            $response = ApiAuthHelper::guzzleRequest('POST', self::representativeRequestsUrl(), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => $bearer,
                    'x-backend-authenticator' => 'keycloak',
                ],
                'multipart' => $multipart,
            ], ['timeout' => 90]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $status = (int)$response->getStatusCode();
        $raw = (string)$response->getBody();
        $json = json_decode($raw, true);

        return [$status, is_array($json) ? $json : null, $raw];
    }
}
