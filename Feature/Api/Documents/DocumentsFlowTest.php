<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping documents integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

test('documents flow: add, get as owner, get as other user forbidden', function () {
    $required = [
        'AUTH_URL' => defined('AUTH_URL') ? AUTH_URL : '',
        'CLIENT_ID' => defined('CLIENT_ID') ? CLIENT_ID : '',
        'CLIENT_SECRET' => defined('CLIENT_SECRET') ? CLIENT_SECRET : '',
        'TEST_USER_1_EMAIL' => defined('TEST_USER_1_EMAIL') ? TEST_USER_1_EMAIL : '',
        'TEST_USER_1_PASSWORD' => defined('TEST_USER_1_PASSWORD') ? TEST_USER_1_PASSWORD : '',
        'TEST_USER_2_EMAIL' => defined('TEST_USER_2_EMAIL') ? TEST_USER_2_EMAIL : '',
        'TEST_USER_2_PASSWORD' => defined('TEST_USER_2_PASSWORD') ? TEST_USER_2_PASSWORD : '',
    ];

    foreach ($required as $key => $value) {
        if (!is_string($value) || trim($value) === '') {
            $this->markTestSkipped("Missing required test config constant: {$key}");
        }
    }

    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $apiBase = API_URL . 'documents';

    $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";

    $documentName = 'Flow test ' . gmdate('YmdHis');

    [$createStatus, $createJson, $createRaw] = ApiAuthHelper::apiRequest('POST', $apiBase, $user1Bearer, [
        'multipart' => [
            [
                'name' => 'file',
                // Keep upload payload fully in-memory (no local temp file needed)
                'contents' => $pdfContent,
                'filename' => 'flow-test.pdf',
                'headers' => ['Content-Type' => 'application/pdf'],
            ],
            ['name' => 'name', 'contents' => $documentName],
            ['name' => 'signature_level', 'contents' => 'SIMPLE'],
        ],
    ]);

    if ($createStatus === 500) {
        $errors = is_array($createJson) ? (array)($createJson['error'] ?? []) : [];
        $joined = implode(' | ', array_map('strval', $errors));
        if (stripos($joined, 'error_directory_not_writable') !== false || stripos($createRaw, 'error_directory_not_writable') !== false) {
            $this->markTestSkipped('Environment issue: upload directory is not writable for document create flow.');
        }
    }

    expect($createStatus, 'Create response: ' . substr($createRaw, 0, 500))->toBe(200);
    expect(is_array($createJson))->toBeTrue();
    $uuid = (string)($createJson['data']['uuid'] ?? '');
    expect($uuid)->not->toBe('');

    [$ownerGetStatus, $ownerGetJson] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user1Bearer);
    expect($ownerGetStatus)->toBe(200);
    expect(is_array($ownerGetJson))->toBeTrue();
    expect((string)($ownerGetJson['data']['uuid'] ?? ''))->toBe($uuid);

    [$otherGetStatus] = ApiAuthHelper::apiRequest('GET', $apiBase . '/' . rawurlencode($uuid), $user2Bearer);
    expect($otherGetStatus)->toBe(403);
});

