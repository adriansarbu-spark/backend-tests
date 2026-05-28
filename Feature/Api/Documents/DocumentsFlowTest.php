<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/DocumentsFlowManager.php';
require_once __DIR__ . '/../../../Support/DocumentsApiHelper.php';
require_once __DIR__ . '/../../../Support/AccountCompaniesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no HTTP calls or database work run from this file.
     */
    test('Documents — integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * Shared helper: one “documents” session for the whole file (log in test users once, create one sample document).
 *
 * Steps (first time this runs in the process):
 * 1. Build a flow manager for the configured test users (`DocumentsFlowManager::forConfiguredTestUsers()`).
 * 2. Run `initialize()` so a real document exists and tokens are ready.
 * 3. Return the same manager on later calls so tests do not repeat that setup.
 */
function getDocumentsFlowManager(): DocumentsFlowManager
{
    static $manager = null;

    if ($manager instanceof DocumentsFlowManager) {
        return $manager;
    }

    $manager = DocumentsFlowManager::forConfiguredTestUsers();
    $manager->initialize();
    return $manager;
}

/**
 * Prerequisites:
 * - Integration tests are enabled (`SKIP_INTEGRATION_TESTS` is false).
 * - Test users and API base URL are set in `tests_config.php` so the flow manager can sign in and talk to the API.
 *
 * Steps:
 * 1. Ask for the shared flow manager (this triggers login and creates a sample document for the main user).
 * 2. Read the document id the API returned.
 * 3. Check that the id is not empty (creation really happened).
 */
test('Documents — adding a document succeeds', function () {
    $manager = getDocumentsFlowManager();
    expect($manager->getUuid())->not->toBe('');
});

/**
 * Prerequisites:
 * - The shared flow already created a document owned by the main test user.
 *
 * Steps:
 * 1. Open that document again using the main user’s session (same id as after creation).
 * 2. Check that the server answers with success (HTTP 200).
 * 3. Check that the response body includes the same document id we expect.
 */
test('Documents — owner can view their document', function () {
    $manager = getDocumentsFlowManager();
    $apiBase = $manager->getApiBase();
    $uuid = $manager->getUuid();
    $user1Bearer = $manager->getUser1Bearer();

    [$ownerGetStatus, $ownerGetJson] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user1Bearer);
    expect($ownerGetStatus)->toBe(200);
    expect(is_array($ownerGetJson))->toBeTrue();
    expect(DocumentsApiHelper::documentUuidFromGetResponse($ownerGetJson, $uuid))->toBe($uuid);
});

/**
 * Prerequisites:
 * - A document exists that belongs only to the main test user.
 * - A second test user exists and can sign in (different account from the owner).
 *
 * Steps:
 * 1. Ask the server for the same document id, but using the second user’s session.
 * 2. Check that access is refused (HTTP 403 — “forbidden”, not a normal document view).
 */
test('Documents — someone else cannot open your document', function () {
    $manager = getDocumentsFlowManager();
    $apiBase = $manager->getApiBase();
    $uuid = $manager->getUuid();
    $user2Bearer = $manager->getUser2Bearer();

    [$otherGetStatus] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user2Bearer);
    expect($otherGetStatus)->toBe(403);
});

/**
 * Prerequisites:
 * - TEST_USER_1 can sign in and can switch to a company representative/admin active role.
 * - Documents API supports ownership update for DRAFT via PUT /documents/{uuid}.
 *
 * Steps:
 * 1. Sign in as TEST_USER_1 with a fresh token and switch active role to company context.
 * 2. Create a new DRAFT document.
 * 3. Update the draft through PUT /documents/{uuid} with ownership=company.
 * 4. Verify PUT response reports ownership=company.
 * 5. Verify the document appears in company list and does not appear in personal list.
 */
test('Documents — company ownership is saved and listed under company envelopes', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    AccountCompaniesApiHelper::switchUser1ToCompanyRepresentativeRole($user1Bearer);

    [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($user1Bearer);

    [$putStatus, $putJson, $putRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer,
        [
            'json' => [
                'ownership' => 'company',
            ],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]
    );

    expect($putStatus)->toBe(200, 'Ownership update failed. raw=' . substr((string)$putRaw, 0, 700));
    expect(is_array($putJson))->toBeTrue('Ownership update returned non-JSON. raw=' . substr((string)$putRaw, 0, 700));
    expect((string)($putJson['data']['ownership'] ?? ''))->toBe('company');

    [$companyListStatus, $companyListJson, $companyListRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?scope=all_related&envelope_ownership=company&per_page=100',
        $user1Bearer
    );
    expect($companyListStatus)->toBe(200, 'Company ownership list failed. raw=' . substr((string)$companyListRaw, 0, 700));
    expect(is_array($companyListJson))->toBeTrue('Company ownership list returned non-JSON. raw=' . substr((string)$companyListRaw, 0, 700));

    $companyRows = (array)($companyListJson['data'] ?? []);
    $foundInCompanyList = false;
    foreach ($companyRows as $row) {
        if ((string)($row['uuid'] ?? '') === $uuid) {
            $foundInCompanyList = true;
            expect((string)($row['ownership'] ?? ''))->toBe('company');
            break;
        }
    }
    expect($foundInCompanyList)->toBeTrue('Updated document should appear in company envelope list.');

    [$personalListStatus, $personalListJson, $personalListRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?scope=all_related&envelope_ownership=personal&per_page=100',
        $user1Bearer
    );
    expect($personalListStatus)->toBe(200, 'Personal ownership list failed. raw=' . substr((string)$personalListRaw, 0, 700));
    expect(is_array($personalListJson))->toBeTrue('Personal ownership list returned non-JSON. raw=' . substr((string)$personalListRaw, 0, 700));

    $personalRows = (array)($personalListJson['data'] ?? []);
    $foundInPersonalList = false;
    foreach ($personalRows as $row) {
        if ((string)($row['uuid'] ?? '') === $uuid) {
            $foundInPersonalList = true;
            break;
        }
    }
    expect($foundInPersonalList)->toBeFalse('Updated company-owned document should not appear in personal envelope list.');
});

/**
 * Prerequisites:
 * - TEST_USER_1 can switch to company representative/admin active role.
 * - TEST_USER_2 is a different account and not the owner of TEST_USER_1 company envelopes.
 *
 * Steps:
 * 1. Sign in as TEST_USER_1, switch to company role, create a document, then set ownership=company.
 * 2. Sign in as TEST_USER_2 and attempt direct GET /documents/{uuid}.
 * 3. Verify access is denied (never HTTP 200).
 * 4. Verify the same UUID is not visible in TEST_USER_2 list for both all/company ownership filters.
 */
test('Documents — you cannot see another company document', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    AccountCompaniesApiHelper::switchUser1ToCompanyRepresentativeRole($user1Bearer);

    [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($user1Bearer);

    [$putStatus, $putJson, $putRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer,
        [
            'json' => ['ownership' => 'company'],
            'headers' => ['Content-Type' => 'application/json'],
        ]
    );
    expect($putStatus)->toBe(200, 'Could not switch ownership to company. raw=' . substr((string)$putRaw, 0, 700));
    expect(is_array($putJson))->toBeTrue('Ownership update returned non-JSON. raw=' . substr((string)$putRaw, 0, 700));
    expect((string)($putJson['data']['ownership'] ?? ''))->toBe('company');

    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);
    [$switch2Status] = AccountCompaniesApiHelper::switchActiveRole($user2Bearer, (string)TEST_USER_2_PERSONAL_ROLE_UUID);
    expect($switch2Status)->toBe(200);

    [$otherGetStatus] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user2Bearer
    );
    expect($otherGetStatus)->not->toBe(200, 'Another account must not be able to open company-owned document by uuid.');

    [$user2AllListStatus, $user2AllListJson, $user2AllListRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?scope=all_related&envelope_ownership=all&per_page=100',
        $user2Bearer
    );
    expect($user2AllListStatus)->toBe(200, 'User2 all list failed. raw=' . substr((string)$user2AllListRaw, 0, 700));
    expect(is_array($user2AllListJson))->toBeTrue('User2 all list returned non-JSON. raw=' . substr((string)$user2AllListRaw, 0, 700));
    $foundInUser2All = false;
    foreach ((array)($user2AllListJson['data'] ?? []) as $row) {
        if ((string)($row['uuid'] ?? '') === $uuid) {
            $foundInUser2All = true;
            break;
        }
    }
    expect($foundInUser2All)->toBeFalse('Another account should not receive owner UUID for company-owned document in all list.');

    [$user2CompanyListStatus, $user2CompanyListJson, $user2CompanyListRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?scope=all_related&envelope_ownership=company&per_page=100',
        $user2Bearer
    );
    expect($user2CompanyListStatus)->toBe(200, 'User2 company list failed. raw=' . substr((string)$user2CompanyListRaw, 0, 700));
    expect(is_array($user2CompanyListJson))->toBeTrue('User2 company list returned non-JSON. raw=' . substr((string)$user2CompanyListRaw, 0, 700));
    $foundInUser2Company = false;
    foreach ((array)($user2CompanyListJson['data'] ?? []) as $row) {
        if ((string)($row['uuid'] ?? '') === $uuid) {
            $foundInUser2Company = true;
            break;
        }
    }
    expect($foundInUser2Company)->toBeFalse('Another account should not see company-owned document in company list.');
});

/**
 * Prerequisites:
 * - The main test user can sign in.
 * - The server allows creating a new upload for this test (if uploads cannot be written, the helper may skip).
 *
 * Steps:
 * 1. Create a fresh document with a small PDF attached for the main user.
 * 2. Request the “download file” endpoint for that document as the owner.
 * 3. Check that the download succeeds (HTTP 200) and the bytes look like a real PDF (starts with `%PDF`).
 */
test('Documents — owner can download their PDF', function () {
    $manager = getDocumentsFlowManager();
    $user1Bearer = $manager->getUser1Bearer();

    [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($user1Bearer);

    [$fileStatus, , $fileRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid) . '/file',
        $user1Bearer
    );

    $fileDebug = substr((string)$fileRaw, 0, 500);
    expect($fileStatus)->toBe(200, 'Owner should be able to download /file. status=' . $fileStatus . ' raw=' . $fileDebug);
    expect(str_starts_with((string)$fileRaw, '%PDF'))->toBeTrue('Expected /file response to be a PDF. raw=' . $fileDebug);
});

/**
 * Prerequisites:
 * - Two different test accounts can sign in.
 *
 * Steps:
 * 1. User B creates their own document with a PDF.
 * 2. User A (not the owner) tries to download B’s file using the same document id.
 * 3. Check that the download does not succeed as a normal file (not HTTP 200).
 * 4. If the server returns JSON with error details, check that an error message is present.
 */
test('Documents — you cannot download another person’s PDF', function () {
    $manager = getDocumentsFlowManager();
    $user2Bearer = $manager->getUser2Bearer();
    $user1Bearer = $manager->getUser1Bearer();

    [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($user2Bearer);

    [$fileStatus, $fileJson, $fileRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid) . '/file',
        $user1Bearer
    );

    $fileDebug = substr((string)$fileRaw, 0, 700);
    expect($fileStatus)->not->toBe(200, 'Non-owner must not be able to download peer document /file. status=' . $fileStatus . ' raw=' . $fileDebug);
    if (is_array($fileJson)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($fileJson['error'] ?? [])));
        expect($errorsJoined)->not->toBe('', 'Expected an error message for forbidden file download. raw=' . $fileDebug);
    }
});




/**
 * Prerequisites:
 * - `TEST_USER_3_EMAIL` and `TEST_USER_3_PASSWORD` in `tests_config.php` point to a real “less verified” test account.
 *
 * Steps:
 * 1. Sign in as that third user and prepare a tiny PDF in memory.
 * 2. Upload it as a “qualified” document through the same API the app uses.
 * 3. Check that the HTTP status is one of: success (200), forbidden (403), or validation issue (422) — different environments may legitimately differ.
 * 4. If the upload succeeded (200), check that the response includes a new document id.
 * 5. If the upload did not succeed but JSON errors came back, check that at least one error message is present.
 */
test('Documents — uncertified account may upload or be blocked (environment-specific)', function () {
    $user3Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_3_EMAIL, TEST_USER_3_PASSWORD);

    $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
    $documentName = 'Flow test uncertified qualified ' . gmdate('YmdHis');

    [$status, $json, $raw] = DocumentsApiHelper::uploadDocumentForFlow(
        $user3Bearer,
        $documentName,
        'QUALIFIED',
        $pdfContent,
        API_URL . 'documents',
        'flow-test-user3.pdf'
    );

    $jsonText = is_array($json)
        ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr((string)$raw, 0, 1200);


    expect(in_array($status, [200, 403, 422], true))->toBeTrue("Unexpected status for uncertified account qualified upload.\n{$debug}");
    if ($status === 200) {
        expect(is_array($json))->toBeTrue("Expected JSON response.\n{$debug}");
        expect((string)($json['data']['uuid'] ?? ''))->not->toBe('', "Expected data.uuid on success.\n{$debug}");
    } else if (is_array($json)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($json['error'] ?? [])));
        expect($errorsJoined)->not->toBe('', "Expected error message.\n{$debug}");
    }
});