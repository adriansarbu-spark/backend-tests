<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/AuthorDocumentsApiHelper.php';
require_once __DIR__ . '/../../../Support/TeamApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Author documents - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    AuthorDocumentsApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Company admin; PDF service and signing entitlements available in this environment.
 *
 * Steps:
 * 1. Create draft, campaign, and upload one recipient row (helper).
 * 2. **POST** `/send` on the draft.
 * 3. On **HTTP 200**, expect `status` **sent** and `total_recipients` at least 1.
 * 4. **GET** `/signing-documents` and expect at least one linked signing document.
 * 5. Skip when PDF service or billing blocks send (environment limitation).
 */
test('Author documents - owner can send a prepared campaign and get signing documents', function () {
    $bearer = AuthorDocumentsApiHelper::bearerWithCompanyAdmin();
    $signerEmail = defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : 'signer@example.com';

    $draft = AuthorDocumentsApiHelper::createDraftWithSignerParty($bearer, $signerEmail);
    $uuid = $draft['uuid'];

    [$campStatus, $campJson, $campRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::campaignsUrl($uuid),
        $bearer,
        [],
    );
    TeamApiHelper::skipIfAdminRoleRequired($campStatus, $campJson);
    expect($campStatus)->toBe(200, 'Campaign create failed: ' . substr((string)$campRaw, 0, 400));
    $campaignUuid = (string)($campJson['data']['campaign_uuid'] ?? '');
    expect($campaignUuid)->not->toBe('');

    [$dataStatus, $dataJson, $dataRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::campaignDataUrl($uuid, $campaignUuid),
        $bearer,
        [
            'rows' => [
                [
                    'party_values' => [
                        'signer' => ['name' => 'Test Signer', 'email' => $signerEmail],
                    ],
                    'field_values' => [],
                ],
            ],
        ],
    );
    expect($dataStatus)->toBe(200, 'Campaign data failed: ' . substr((string)$dataRaw, 0, 400));

    [$sendStatus, $sendJson, $sendRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::sendUrl($uuid),
        $bearer,
        [],
    );

    $sendDebug = "Send status={$sendStatus}\n" . substr((string)$sendRaw, 0, 1200);
    AuthorDocumentsApiHelper::skipIfPdfServiceUnavailable($sendStatus, $sendJson);
    AuthorDocumentsApiHelper::skipIfBillingBlocksSend($sendStatus, $sendJson);

    expect($sendStatus)->toBe(200, "Send author document failed.\n{$sendDebug}");
    expect((string)($sendJson['data']['status'] ?? ''))->toBe('sent');
    expect((int)($sendJson['data']['total_recipients'] ?? 0))->toBeGreaterThanOrEqual(1);

    [$listStatus, $listJson, $listRaw] = AuthorDocumentsApiHelper::getJson(
        AuthorDocumentsApiHelper::signingDocumentsUrl($uuid),
        $bearer,
    );

    $listDebug = "Signing docs status={$listStatus}\n" . substr((string)$listRaw, 0, 800);
    expect($listStatus)->toBe(200, "List signing documents failed.\n{$listDebug}");
    $items = (array)($listJson['data'] ?? []);
    expect(count($items))->toBeGreaterThanOrEqual(1);
});
