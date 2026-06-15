<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiAuthHelper.php';
require_once __DIR__ . '/AccountCompaniesApiHelper.php';
require_once __DIR__ . '/TeamApiHelper.php';

final class AuthorDocumentsApiHelper
{
    /** HTML with one signature block (required for send validation). */
    public const SAMPLE_CONTENT_WITH_SIGNATURE = '<p>Author document body</p>'
        . '<div class="signature-block-item" data-signature-party-code="signer"></div>';

    public static function apiBase(): string
    {
        return API_URL . 'esign/author-documents';
    }

    public static function documentUrl(string $uuid): string
    {
        return self::apiBase() . '/' . rawurlencode($uuid);
    }

    public static function campaignsUrl(string $uuid): string
    {
        return self::documentUrl($uuid) . '/campaigns';
    }

    public static function campaignDataUrl(string $uuid, string $campaignUuid): string
    {
        return self::campaignsUrl($uuid) . '/' . rawurlencode($campaignUuid) . '/data';
    }

    public static function sendUrl(string $uuid): string
    {
        return self::documentUrl($uuid) . '/send';
    }

    public static function signingDocumentsUrl(string $uuid): string
    {
        return self::documentUrl($uuid) . '/signing-documents';
    }

    public static function assertRequiredConfigOrSkip(): void
    {
        TeamApiHelper::assertRequiredConfigOrSkip();
    }

    /**
     * Owner bearer: sign in as TEST_USER_1 and activate personal role before author-document calls.
     */
    public static function bearerWithCompanyAdmin(): string
    {
        return AccountCompaniesApiHelper::bearerForUser1Personal();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function postJson(string $url, string $bearer, array $payload): array
    {
        return ApiAuthHelper::apiRequest('POST', $url, $bearer, ['json' => $payload]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function putJson(string $url, string $bearer, array $payload): array
    {
        return ApiAuthHelper::apiRequest('PUT', $url, $bearer, ['json' => $payload]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function getJson(string $url, string $bearer): array
    {
        return ApiAuthHelper::apiRequest('GET', $url, $bearer);
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

    public static function skipIfPdfServiceUnavailable(int $status, ?array $json): void
    {
        if ($status !== 503) {
            return;
        }
        $msg = self::joinedErrors($json);
        if (str_contains($msg, 'PDF service not configured')) {
            test()->markTestSkipped('PDF service (esign_pdf_service) is not configured in this environment.');
        }
    }

    public static function skipIfBillingBlocksSend(int $status, ?array $json): void
    {
        if ($status !== 409) {
            return;
        }
        $msg = self::joinedErrors($json);
        if (str_contains($msg, 'Insufficient') || str_contains($msg, 'entitlement')) {
            test()->markTestSkipped('Billing entitlements blocked author-document send in this environment: ' . $msg);
        }
    }

    /**
     * Create a scratch draft with parties suitable for single-recipient campaign upload.
     *
     * @return array{uuid: string, campaign_uuid: string|null}
     */
    public static function createDraftWithSignerParty(string $bearer, string $signerEmail): array
    {
        $name = 'Author docs flow ' . gmdate('YmdHis');
        [$createStatus, $createJson, $createRaw] = self::postJson(self::apiBase(), $bearer, [
            'name' => $name,
            'source_type' => 'scratch',
            'content_snapshot' => self::SAMPLE_CONTENT_WITH_SIGNATURE,
        ]);

        $debug = "Create status={$createStatus}\n" . substr((string)$createRaw, 0, 1000);
        TeamApiHelper::skipIfAdminRoleRequired($createStatus, $createJson, '(create author document)');
        expect($createStatus)->toBe(200, "Create author document failed.\n{$debug}");

        $uuid = (string)($createJson['data']['uuid'] ?? '');
        expect($uuid)->not->toBe('');

        [$putStatus, $putJson, $putRaw] = self::putJson(self::documentUrl($uuid), $bearer, [
            'parties' => [
                [
                    'role_code' => 'signer',
                    'role_label' => 'Signer',
                    'name' => 'Test Signer',
                    'email' => $signerEmail,
                    'signing_order' => 1,
                    'parallel_group' => 0,
                ],
            ],
        ]);

        $putDebug = "PUT status={$putStatus}\n" . substr((string)$putRaw, 0, 1000);
        expect($putStatus)->toBe(200, "PUT parties failed.\n{$putDebug}");

        return ['uuid' => $uuid, 'campaign_uuid' => null];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: array<string, mixed>|null, 2: string}
     */
    public static function uploadCampaignRows(
        string $bearer,
        string $documentUuid,
        string $campaignUuid,
        array $rows,
    ): array {
        return self::postJson(
            self::campaignDataUrl($documentUuid, $campaignUuid),
            $bearer,
            ['rows' => $rows],
        );
    }

    /**
     * Create draft, empty campaign, and upload one recipient row.
     *
     * @return array{uuid: string, campaign_uuid: string}
     */
    public static function createDraftCampaignAndUpload(string $bearer, string $signerEmail): array
    {
        $draft = self::createDraftWithSignerParty($bearer, $signerEmail);
        $uuid = $draft['uuid'];

        [$campStatus, $campJson, $campRaw] = self::postJson(self::campaignsUrl($uuid), $bearer, []);
        TeamApiHelper::skipIfAdminRoleRequired($campStatus, $campJson, '(create campaign)');
        expect($campStatus)->toBe(200, 'Campaign create failed: ' . substr((string)$campRaw, 0, 400));

        $campaignUuid = (string)($campJson['data']['campaign_uuid'] ?? '');
        expect($campaignUuid)->not->toBe('');

        [$dataStatus, $dataJson, $dataRaw] = self::uploadCampaignRows($bearer, $uuid, $campaignUuid, [
            [
                'party_values' => [
                    'signer' => ['name' => 'Test Signer', 'email' => $signerEmail],
                ],
                'field_values' => [],
            ],
        ]);
        expect($dataStatus)->toBe(200, 'Campaign data failed: ' . substr((string)$dataRaw, 0, 400));
        expect((int)($dataJson['data']['total_recipients'] ?? 0))->toBeGreaterThanOrEqual(1);

        return ['uuid' => $uuid, 'campaign_uuid' => $campaignUuid];
    }
}
