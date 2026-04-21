<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping templates versioning integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Versioning and lifecycle flows: publish/archive, versions CRUD, parties/smartfields on versions,
 * clone and edit-published. Uses ApiAuthHelper + TemplatesApiHelper; asserts status and response
 * shape per documents-testing-guidelines.md (no reliance on free-text error messages).
 */

test('templates versioning: publish draft then archive; invalid transitions return 422', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();

    [$apiBaseResolved, $uuid] = TemplatesApiHelper::createTemplateForFlow($bearer, [], $apiBase);

    [$pubStatus, $pubJson, $pubRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );
    $pubDebug = "Status={$pubStatus}\n" . substr((string)$pubRaw, 0, 800);
    expect($pubStatus, "Publish failed.\n{$pubDebug}")->toBe(200);
    expect(is_array($pubJson))->toBeTrue();
    expect((string)($pubJson['data']['status'] ?? ''))->toBe('published');

    [$pubAgainStatus, $pubAgainJson] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );
    expect($pubAgainStatus)->toBe(422);
    if (is_array($pubAgainJson)) {
        $err = $pubAgainJson['error'] ?? null;
        if (is_array($err) && array_key_exists('code', $err)) {
            expect($err['code'] ?? null)->not->toBe('');
        } else {
            expect(implode(' ', array_map('strval', (array)$err)))->not->toBe('');
        }
    }

    [$archStatus, $archJson, $archRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/archive',
        $bearer
    );
    $archDebug = "Status={$archStatus}\n" . substr((string)$archRaw, 0, 800);
    expect($archStatus, "Archive failed.\n{$archDebug}")->toBe(200);
    expect(is_array($archJson))->toBeTrue();
    expect((string)($archJson['data']['status'] ?? ''))->toBe('archived');

    [$archAgainStatus, $archAgainJson] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/archive',
        $bearer
    );
    expect($archAgainStatus)->toBe(422);
    if (is_array($archAgainJson)) {
        $err = $archAgainJson['error'] ?? null;
        if (is_array($err) && array_key_exists('code', $err)) {
            expect($err['code'] ?? null)->not->toBe('');
        } else {
            expect(implode(' ', array_map('strval', (array)$err)))->not->toBe('');
        }
    }
});

test('templates versioning: list versions, create draft version, update, parties, publish version', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();

    [$apiBaseResolved, $templateUuid] = TemplatesApiHelper::createTemplateForFlow(
        $bearer,
        ['name' => 'Version flow ' . gmdate('YmdHis')],
        $apiBase
    );

    [$p1, $j1, $r1] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/publish',
        $bearer
    );
    expect($p1, substr((string)$r1, 0, 600))->toBe(200);

    [$list1Status, $list1Json, $list1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions?sort=version&order=DESC&per_page=20',
        $bearer
    );
    $list1Debug = "Status={$list1Status}\n" . substr((string)$list1Raw, 0, 1000);
    expect($list1Status, "List versions failed.\n{$list1Debug}")->toBe(200);
    expect(is_array($list1Json))->toBeTrue();
    $rows1 = (array)($list1Json['data'] ?? []);
    expect(count($rows1))->toBeGreaterThan(0);
    $first = $rows1[0];
    expect(is_array($first))->toBeTrue();
    expect((string)($first['uuid'] ?? ''))->not->toBe('');
    expect((string)($first['status'] ?? ''))->not->toBe('');
    expect(is_int($first['version'] ?? null))->toBeTrue();

    [$createVerStatus, $createVerJson, $createVerRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions',
        $bearer,
        [
            'json' => [
                'content' => '<p>Version 2 body</p>',
            ],
        ]
    );
    $cvDebug = "Status={$createVerStatus}\n" . substr((string)$createVerRaw, 0, 1000);
    expect($createVerStatus, "Create version failed.\n{$cvDebug}")->toBe(200);
    expect(is_array($createVerJson))->toBeTrue();
    $versionUuid = (string)($createVerJson['data']['uuid'] ?? '');
    expect($versionUuid)->not->toBe('');
    // Create payload uses DB column `version`; JSON key `version_number` may be 0. Confirm via list (same shape as GET /versions).
    expect((string)($createVerJson['data']['status'] ?? ''))->toBe('draft');
    [$afterCreateStatus, $afterCreateJson] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions?sort=version&order=DESC&per_page=20',
        $bearer
    );
    expect($afterCreateStatus)->toBe(200);
    $newRow = null;
    foreach ((array)($afterCreateJson['data'] ?? []) as $row) {
        if (is_array($row) && (string)($row['uuid'] ?? '') === $versionUuid) {
            $newRow = $row;
            break;
        }
    }
    expect($newRow)->not->toBeNull();
    expect((int)($newRow['version'] ?? 0))->toBeGreaterThan(1);
    expect((string)($newRow['status'] ?? ''))->toBe('draft');

    // Controller matches version row's template_uuid to the request uuid; API returns the linkage.
    $templateRefUuid = (string)($createVerJson['data']['template_uuid'] ?? '');
    if ($templateRefUuid === '') {
        $templateRefUuid = $templateUuid;
    }

    $putUrl = TemplatesApiHelper::urlTemplateQuery($apiBaseResolved, $templateRefUuid, [
        'version_uuid' => $versionUuid,
    ]);
    [$updStatus, $updJson, $updRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $putUrl,
        $bearer,
        [
            'json' => [
                'content' => '<p>Version 2 updated</p>',
            ],
        ]
    );
    $updDebug = "Status={$updStatus}\n" . substr((string)$updRaw, 0, 1000);
    expect($updStatus, "Update version failed.\n{$updDebug}")->toBe(200);
    expect(is_array($updJson))->toBeTrue();
    expect((string)($updJson['data']['uuid'] ?? ''))->toBe($versionUuid);

    $partiesUrl = TemplatesApiHelper::urlTemplateQuery($apiBaseResolved, $templateRefUuid, [
        'version_uuid' => $versionUuid,
        'action' => 'parties',
    ]);
    [$parStatus, $parJson, $parRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $partiesUrl,
        $bearer,
        [
            'json' => [
                'parties' => [
                    [
                        'code' => 'buyer',
                        'label' => 'Buyer',
                        'signing_order' => 1,
                    ],
                ],
            ],
        ]
    );
    $parDebug = "Status={$parStatus}\n" . substr((string)$parRaw, 0, 1000);
    expect($parStatus, "Replace parties failed.\n{$parDebug}")->toBe(200);
    expect(is_array($parJson))->toBeTrue();
    expect(isset($parJson['data']['parties']))->toBeTrue();
    expect(is_array($parJson['data']['parties']))->toBeTrue();

    $sfUrl = TemplatesApiHelper::urlTemplateQuery($apiBaseResolved, $templateRefUuid, [
        'version_uuid' => $versionUuid,
        'action' => 'smartfields',
    ]);
    [$sfStatus, $sfJson, $sfRaw] = ApiAuthHelper::apiRequest(
        'PUT',
        $sfUrl,
        $bearer,
        [
            'json' => [
                'smartfields' => [
                    [
                        'field_key' => 'amount',
                        'label' => 'Amount',
                        'type' => 'text',
                    ],
                ],
            ],
        ]
    );
    $sfDebug = "Status={$sfStatus}\n" . substr((string)$sfRaw, 0, 1000);
    expect($sfStatus, "Replace smartfields failed.\n{$sfDebug}")->toBe(200);
    expect(is_array($sfJson))->toBeTrue();
    expect(isset($sfJson['data']['smartfields']))->toBeTrue();

    $publishVerUrl = TemplatesApiHelper::urlTemplateQuery($apiBaseResolved, $templateRefUuid, [
        'version_uuid' => $versionUuid,
        'action' => 'publish',
    ]);
    [$pvStatus, $pvJson, $pvRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $publishVerUrl,
        $bearer
    );
    $pvDebug = "Status={$pvStatus}\n" . substr((string)$pvRaw, 0, 1000);
    expect($pvStatus, "Publish version failed.\n{$pvDebug}")->toBe(200);
    expect(is_array($pvJson))->toBeTrue();
    expect((string)($pvJson['data']['uuid'] ?? ''))->toBe($versionUuid);
    expect((string)($pvJson['data']['status'] ?? ''))->toBe('published');
    expect((string)($pvJson['data']['published_at'] ?? ''))->not->toBe('');

    [$list2Status, $list2Json] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions?sort=version&order=DESC',
        $bearer
    );
    expect($list2Status)->toBe(200);
    $publishedRow = null;
    foreach ((array)($list2Json['data'] ?? []) as $row) {
        if (is_array($row) && (string)($row['uuid'] ?? '') === $versionUuid) {
            $publishedRow = $row;
            break;
        }
    }
    expect($publishedRow)->not->toBeNull();
    expect((string)($publishedRow['status'] ?? ''))->toBe('published');
});

test('templates versioning: create version rejects missing content with 422', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$apiBaseResolved, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($bearer);

    [$st, $json] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions',
        $bearer,
        ['json' => []]
    );
    expect($st)->toBe(422);
    $err = is_array($json) ? ($json['error'] ?? null) : null;
    if (is_array($err) && array_key_exists('code', $err)) {
        expect($err['code'] ?? null)->toBe('VALIDATION_ERROR');
        expect($err['field'] ?? null)->toBe('content');
    } else {
        expect(implode(' ', array_map('strval', (array)$err)))->not->toBe('');
    }
});

test('templates versioning: invalid sort on versions list returns 422', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$apiBaseResolved, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($bearer);

    [$st, $json] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBaseResolved . '/' . rawurlencode($templateUuid) . '/versions?sort=not_a_real_column',
        $bearer
    );
    expect($st)->toBe(422);
    if (is_array($json)) {
        $joined = implode(' ', array_map('strval', (array)($json['error'] ?? [])));
        expect($joined)->not->toBe('');
    }
});

test('templates versioning: clone published template and edit published creates new draft', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$apiBaseResolved, $uuid] = TemplatesApiHelper::createTemplateForFlow(
        $bearer,
        ['name' => 'Clone edit flow ' . gmdate('YmdHis')]
    );

    ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );

    [$cloneStatus, $cloneJson, $cloneRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/clone',
        $bearer
    );
    $cloneDebug = "Status={$cloneStatus}\n" . substr((string)$cloneRaw, 0, 800);
    expect($cloneStatus, "Clone failed.\n{$cloneDebug}")->toBe(200);
    $newUuid = (string)($cloneJson['data']['uuid'] ?? '');
    expect($newUuid)->not->toBe('');
    expect($newUuid)->not->toBe($uuid);
    expect((string)($cloneJson['data']['status'] ?? ''))->toBe('draft');

    [$delStatus] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBaseResolved . '/' . rawurlencode($newUuid),
        $bearer
    );
    expect($delStatus)->toBe(200);

    [$editStatus, $editJson, $editRaw] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/edit',
        $bearer
    );
    $editDebug = "Status={$editStatus}\n" . substr((string)$editRaw, 0, 800);
    expect($editStatus, "Edit published failed.\n{$editDebug}")->toBe(200);
    $draftUuid = (string)($editJson['data']['uuid'] ?? '');
    expect($draftUuid)->not->toBe('');
    expect((string)($editJson['data']['status'] ?? ''))->toBe('draft');
});

test('templates versioning: delete published template returns 422', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$apiBaseResolved, $uuid] = TemplatesApiHelper::createTemplateForFlow($bearer);

    ApiAuthHelper::apiRequest(
        'POST',
        $apiBaseResolved . '/' . rawurlencode($uuid) . '/publish',
        $bearer
    );

    [$delStatus, $delJson] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBaseResolved . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($delStatus)->toBe(422);
    if (is_array($delJson)) {
        $joined = implode(' ', array_map('strval', (array)($delJson['error'] ?? [])));
        expect($joined)->not->toBe('');
    }
});
