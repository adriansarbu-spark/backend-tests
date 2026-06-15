<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/AuthorDocumentsApiHelper.php';
require_once __DIR__ . '/../../../Support/TeamApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php`.
     *
     * Steps:
     * 1. Skip this file so no author-documents HTTP calls run.
     */
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
 * - Company admin session; author-documents API enabled for the active company.
 *
 * Steps:
 * 1. Create a scratch draft with a signature block and one signer party.
 * 2. **POST** `/campaigns` on that document; expect **HTTP 200** and `campaign_uuid`.
 * 3. **POST** campaign `/data` with one recipient row; expect `total_recipients` at least 1.
 * 4. **GET** the document; status remains **draft** until send.
 */
test('Author documents - owner can create a campaign and upload recipient rows', function () {
    $bearer = AuthorDocumentsApiHelper::bearerWithCompanyAdmin();
    $signerEmail = defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : 'signer@example.com';

    $draft = AuthorDocumentsApiHelper::createDraftWithSignerParty($bearer, $signerEmail);
    $uuid = $draft['uuid'];

    [$campStatus, $campJson, $campRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::campaignsUrl($uuid),
        $bearer,
        [],
    );

    $campDebug = "Campaign create status={$campStatus}\n" . substr((string)$campRaw, 0, 800);
    TeamApiHelper::skipIfAdminRoleRequired($campStatus, $campJson, '(create campaign)');
    expect($campStatus)->toBe(200, "Create campaign failed.\n{$campDebug}");

    $campaignUuid = (string)($campJson['data']['campaign_uuid'] ?? '');
    expect($campaignUuid)->not->toBe('');

    [$dataStatus, $dataJson, $dataRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::campaignDataUrl($uuid, $campaignUuid),
        $bearer,
        [
            'rows' => [
                [
                    'party_values' => [
                        'signer' => [
                            'name' => 'Test Signer',
                            'email' => $signerEmail,
                        ],
                    ],
                    'field_values' => [],
                ],
            ],
        ],
    );

    $dataDebug = "Upload data status={$dataStatus}\n" . substr((string)$dataRaw, 0, 800);
    expect($dataStatus)->toBe(200, "Upload campaign data failed.\n{$dataDebug}");
    expect((int)($dataJson['data']['total_recipients'] ?? 0))->toBeGreaterThanOrEqual(1);

    [$getStatus, $getJson] = AuthorDocumentsApiHelper::getJson(
        AuthorDocumentsApiHelper::documentUrl($uuid),
        $bearer,
    );
    expect($getStatus)->toBe(200);
    expect((string)($getJson['data']['status'] ?? ''))->toBe('draft');
});

/**
 * Prerequisites:
 * - Draft exists for user 1; user 2 is signed in.
 *
 * Steps:
 * 1. Create a draft as user 1.
 * 2. As user 2, **GET** that document by uuid.
 * 3. Access is refused (**HTTP 403** or **404** depending on API policy).
 */
test('Author documents - someone else cannot open your draft document', function () {
    TeamApiHelper::assertUserTwoConfigOrSkip();

    $bearer1 = AuthorDocumentsApiHelper::bearerWithCompanyAdmin();
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $draft = AuthorDocumentsApiHelper::createDraftWithSignerParty(
        $bearer1,
        'outsider-check-' . gmdate('YmdHis') . '@simplifi.ro',
    );

    [$status, $json, $raw] = AuthorDocumentsApiHelper::getJson(
        AuthorDocumentsApiHelper::documentUrl($draft['uuid']),
        $bearer2,
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 600);
    expect(in_array($status, [403, 404], true))->toBeTrue("Expected outsider blocked.\n{$debug}");
    expect(AuthorDocumentsApiHelper::joinedErrors($json))->not->toBe('');
});
