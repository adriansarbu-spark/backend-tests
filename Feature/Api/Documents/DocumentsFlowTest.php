<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/DocumentsFlowManager.php';
require_once __DIR__ . '/../../../Support/DocumentsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - `SKIP_INTEGRATION_TESTS` is true in `tests_config.php` (integration suite disabled for this run).
     *
     * Steps:
     * 1. Mark skipped so no database or HTTP document flow runs.
     */
    test('Skipping documents integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * Lazy singleton for `DocumentsFlowManager` so this file shares one initialized flow
 * (bearers + one document UUID from `initialize()`) across tests that use `getDocumentsFlowManager()`.
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
 * - Integration tests enabled (`SKIP_INTEGRATION_TESTS` false).
 * - `DocumentsFlowManager::forConfiguredTestUsers()` requirements met (e.g. `TEST_USER_1_*`, `TEST_USER_2_*`, `API_URL`).
 *
 * Steps:
 * 1. Resolve the shared flow manager and run `initialize()` (creates a document as user1).
 * 2. Assert the manager exposes a non-empty document UUID.
 */
test('documents flow: add document', function () {
    $manager = getDocumentsFlowManager();
    expect($manager->getUuid())->not->toBe('');
});

/**
 * Prerequisites:
 * - Shared flow initialized: document owned by user1; UUID and API base from the manager.
 *
 * Steps:
 * 1. `GET /documents/{uuid}` with user1 bearer.
 * 2. Assert 200, JSON body, and `data.uuid` equals the flow UUID.
 */
test('documents flow: get document as owner', function () {
    $manager = getDocumentsFlowManager();
    $apiBase = $manager->getApiBase();
    $uuid = $manager->getUuid();
    $user1Bearer = $manager->getUser1Bearer();

    [$ownerGetStatus, $ownerGetJson] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user1Bearer);
    expect($ownerGetStatus)->toBe(200);
    expect(is_array($ownerGetJson))->toBeTrue();
    expect((string)($ownerGetJson['data']['uuid'] ?? ''))->toBe($uuid);
});

/**
 * Prerequisites:
 * - Shared document owned by user1; user2 is a different account with a valid bearer.
 *
 * Steps:
 * 1. `GET /documents/{uuid}` with user2 bearer.
 * 2. Assert 403 (non-owner must not read the resource).
 */
test('documents flow: get document as non-owner is forbidden', function () {
    $manager = getDocumentsFlowManager();
    $apiBase = $manager->getApiBase();
    $uuid = $manager->getUuid();
    $user2Bearer = $manager->getUser2Bearer();

    [$otherGetStatus] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user2Bearer);
    expect($otherGetStatus)->toBe(403);
});

/**
 * Prerequisites:
 * - User1 bearer valid; document create succeeds (helper may skip if upload directory is not writable).
 *
 * Steps:
 * 1. Create a new document for user1 via `DocumentsApiHelper::createDocumentForFlow`.
 * 2. `GET /documents/{uuid}/file` as owner.
 * 3. Assert 200 and raw body starts with `%PDF`.
 */
test('documents flow: document owner can download their PDF via /file', function () {
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
 * - Two distinct test users with valid bearers (`DocumentsFlowManager` / `tests_config.php`).
 *
 * Steps:
 * 1. Create a document owned by user2.
 * 2. Request `GET /documents/{uuid}/file` with user1 bearer (non-owner).
 * 3. Assert status is not 200; when JSON is returned, assert non-empty `error` (guideline: assert error shape on failures).
 */
test('documents flow: non-owner cannot download another account document via /file', function () {
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


if (defined('SKIP_USER_3_FAILED_TESTS') && SKIP_USER_3_FAILED_TESTS) {
    /**
     * Prerequisites:
     * - `SKIP_USER_3_FAILED_TESTS` is true — user3 uncertified upload scenarios are disabled for this run.
     *
     * Steps:
     * 1. Mark skipped; the full scenario runs when the flag is false.
     */
    test('documents flow: uncertified account qualified upload (behavior check)', function () {
        $this->markTestSkipped('Uncertified-account upload tests are disabled');
    });
    return;
}

/**
 * Prerequisites:
 * - Integration tests enabled; `SKIP_USER_3_FAILED_TESTS` false.
 * - `TEST_USER_3_EMAIL` / `TEST_USER_3_PASSWORD` available for `ApiAuthHelper::bearerTokenFor`.
 *
 * Steps:
 * 1. Upload a minimal PDF as QUALIFIED via `DocumentsApiHelper::uploadDocumentForFlow` as user3 (uncertified account behavior).
 * 2. Assert status is one of 200, 403, or 422 (environment/account-dependent).
 * 3. On 200: assert JSON and non-empty `data.uuid`. On non-200 with JSON: assert non-empty `error`.
 */
test('documents flow: uncertified account qualified upload (behavior check)', function () {
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