<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/AuthorDocumentsApiHelper.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
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
 * - Author-documents API is reachable.
 *
 * Steps:
 * 1. **POST** create draft without an Authorization header.
 * 2. Access is refused (**HTTP 404**).
 */
test('Author documents - creating a draft without a token is refused', function () {
    [$status, $json, $raw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::apiBase(),
        '',
        [
            'name' => 'No auth ' . gmdate('YmdHis'),
            'source_type' => 'scratch',
            'content_snapshot' => AuthorDocumentsApiHelper::SAMPLE_CONTENT_WITH_SIGNATURE,
        ],
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 600);
    expect($status)->toBe(404, "Expected unauthenticated create to fail.\n{$debug}");
    expect(AuthorDocumentsApiHelper::joinedErrors($json))->not->toBe('');
});

/**
 * Prerequisites:
 * - User 1 has a draft; user 2 is signed in.
 *
 * Steps:
 * 1. Create draft as company admin (user 1).
 * 2. As user 2, **PUT** the same document uuid.
 * 3. Access is refused (**HTTP 403** or **404**).
 */
test('Author documents - someone else cannot update your draft', function () {
    TeamApiHelper::assertUserTwoConfigOrSkip();

    $bearer1 = AuthorDocumentsApiHelper::bearerWithCompanyAdmin();
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $draft = AuthorDocumentsApiHelper::createDraftWithSignerParty(
        $bearer1,
        'outsider-put-' . gmdate('YmdHis') . '@simplifi.ro',
    );

    [$status, $json, $raw] = AuthorDocumentsApiHelper::putJson(
        AuthorDocumentsApiHelper::documentUrl($draft['uuid']),
        $bearer2,
        ['name' => 'Hijacked title'],
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 600);
    expect(in_array($status, [403, 404], true))->toBeTrue("Expected outsider PUT blocked.\n{$debug}");
    expect(AuthorDocumentsApiHelper::joinedErrors($json))->not->toBe('');
});

/**
 * Prerequisites:
 * - Full send flow completed for user 1 (may skip when PDF/billing unavailable).
 *
 * Steps:
 * 1. Build draft, campaign, upload, and **POST** send.
 * 2. **POST** send again on the same uuid.
 * 3. Expect **HTTP 422** because the document is no longer a draft.
 */
test('Author documents - sending an already sent document is rejected', function () {
    $bearer = AuthorDocumentsApiHelper::bearerWithCompanyAdmin();
    $signerEmail = defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : 'signer@example.com';

    $built = AuthorDocumentsApiHelper::createDraftCampaignAndUpload($bearer, $signerEmail);
    $uuid = $built['uuid'];

    [$sendStatus, $sendJson, $sendRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::sendUrl($uuid),
        $bearer,
        [],
    );

    AuthorDocumentsApiHelper::skipIfPdfServiceUnavailable($sendStatus, $sendJson);
    AuthorDocumentsApiHelper::skipIfBillingBlocksSend($sendStatus, $sendJson);

    if ($sendStatus !== 200) {
        test()->markTestSkipped('First send did not succeed; cannot test double-send guard. Status=' . $sendStatus);
    }

    [$againStatus, $againJson, $againRaw] = AuthorDocumentsApiHelper::postJson(
        AuthorDocumentsApiHelper::sendUrl($uuid),
        $bearer,
        [],
    );

    $debug = "Second send status={$againStatus}\n" . substr((string)$againRaw, 0, 800);
    expect($againStatus)->toBe(422, "Expected double send blocked.\n{$debug}");
    expect(AuthorDocumentsApiHelper::joinedErrors($againJson))->toContain('document_not_draft');
});
