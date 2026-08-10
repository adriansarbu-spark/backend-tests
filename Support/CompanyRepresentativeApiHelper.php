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
     * GET single request — UUID must be in the `route` query value (see getRequestUuid()).
     */
    public static function representativeRequestDetailUrl(string $requestUuid): string
    {
        $base = rtrim(HTTPS_SERVER, '/') . '/index.php';

        return $base . '?route=publicapi/v1/company/representative_requests/' . rawurlencode($requestUuid);
    }

    /**
     * POST cancel for a request UUID.
     */
    public static function representativeRequestCancelUrl(string $requestUuid): string
    {
        $base = rtrim(HTTPS_SERVER, '/') . '/index.php';

        return $base
            . '?route=publicapi/v1/company/representative_requests/'
            . rawurlencode($requestUuid)
            . '/cancel';
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
