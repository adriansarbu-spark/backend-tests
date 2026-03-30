<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/DocumentsFlowManager.php';
require_once __DIR__ . '/../../../Support/DocumentsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping documents integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}


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

test('documents flow: add document', function () {
    $manager = getDocumentsFlowManager();
    expect($manager->getUuid())->not->toBe('');
});

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

test('documents flow: get document as other user is forbidden', function () {
    $manager = getDocumentsFlowManager();
    $apiBase = $manager->getApiBase();
    $uuid = $manager->getUuid();
    $user2Bearer = $manager->getUser2Bearer();

    [$otherGetStatus] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user2Bearer);
    expect($otherGetStatus)->toBe(403);
});

test('documents flow: user1 can download own document file', function () {
    $manager = getDocumentsFlowManager();
    $user1Bearer = $manager->getUser1Bearer();

    [$apiBase, $uuid] = DocumentsApiHelper::createDocumentForFlow($user1Bearer);

    [$fileStatus, , $fileRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid) . '/file',
        $user1Bearer
    );

    $fileDebug = substr((string)$fileRaw, 0, 500);
    expect($fileStatus, 'Owner should be able to download /file. status=' . $fileStatus . ' raw=' . $fileDebug)->toBe(200);
    expect(str_starts_with((string)$fileRaw, '%PDF'), 'Expected /file response to be a PDF. raw=' . $fileDebug)->toBeTrue();
});

test('documents flow: user1 cannot download user2 document file', function () {
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
    expect($fileStatus, 'Non-owner user1 must not be able to download /file. status=' . $fileStatus . ' raw=' . $fileDebug)->not->toBe(200);
    if (is_array($fileJson)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($fileJson['error'] ?? [])));
        expect($errorsJoined, 'Expected an error message for forbidden file download. raw=' . $fileDebug)->not->toBe('');
    }
});


if (defined('SKIP_USER_3_FAILED_TESTS') && SKIP_USER_3_FAILED_TESTS) {
    test('documents flow: user3 without certificate upload qualified document (behavior check)', function () {
        $this->markTestSkipped('User 3 tests are disabled');
    });
    return;
}

test('documents flow: user3 without certificate upload qualified document (behavior check)', function () {
    $user3Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_3_EMAIL, TEST_USER_3_PASSWORD);

    $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
    $documentName = 'Flow test user3 no cert ' . gmdate('YmdHis');

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


    expect(in_array($status, [200, 403, 422], true), "Unexpected status for user3 upload without certificate.\n{$debug}")->toBeTrue();
    if ($status === 200) {
        expect(is_array($json), "Expected JSON response.\n{$debug}")->toBeTrue();
        expect((string)($json['data']['uuid'] ?? ''), "Expected data.uuid on success.\n{$debug}")->not->toBe('');
    } else if (is_array($json)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($json['error'] ?? [])));
        expect($errorsJoined, "Expected error message.\n{$debug}")->not->toBe('');
    }
});