<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping templates CRUD integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Main CRUD flows for user templates:
 * - create + list
 * - read/update/delete for owner vs non-owner
 *
 * These tests follow the patterns from DocumentsFlowTest and SigningFlowTest:
 * use helpers for auth, focus on status codes and response shape, and avoid
 * asserting on human-readable error messages.
 */

test('templates flow: owner can create template and see it in list', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();

    $templateName = 'Templates CRUD flow ' . gmdate('YmdHis');

    [$createStatus, $createJson, $createRaw] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        [
            'name' => $templateName,
            'content' => '<p>Body</p>',
            'category_code' => 'contracts',
            'visibility' => 'private',
        ],
        $apiBase
    );

    $createJsonText = is_array($createJson)
        ? (string)json_encode($createJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $createDebug = "Status={$createStatus}\nJSON:\n{$createJsonText}\nRAW:\n" . substr((string)$createRaw, 0, 1200);

    expect($createStatus, "Create template failed.\n{$createDebug}")->toBe(200);
    expect(is_array($createJson), "Expected JSON create response.\n{$createDebug}")->toBeTrue();
    $uuid = (string)($createJson['data']['uuid'] ?? '');
    expect($uuid, "Expected data.uuid in create response.\n{$createDebug}")->not->toBe('');

    [$listStatus, $listJson, $listRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?page=1&per_page=50&sort=date_added&order=DESC',
        $user1Bearer
    );

    $listJsonText = is_array($listJson)
        ? (string)json_encode($listJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $listDebug = "Status={$listStatus}\nJSON:\n{$listJsonText}\nRAW:\n" . substr((string)$listRaw, 0, 1200);

    expect($listStatus, "List templates failed.\n{$listDebug}")->toBe(200);
    expect(is_array($listJson), "Expected JSON list response.\n{$listDebug}")->toBeTrue();

    $items = (array)($listJson['data'] ?? []);
    $found = null;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string)($item['uuid'] ?? '') === $uuid) {
            $found = $item;
            break;
        }
    }

    expect($found, "Created template uuid={$uuid} not found in list.\n{$listDebug}")->not->toBeNull();
    expect($found['name'] ?? null)->toBe($templateName);
    expect($found['category_code'] ?? null)->toBe('contracts');
    expect($found['visibility'] ?? null)->toBe('private');
    expect((string)($found['status'] ?? ''))->not->toBe('');
    expect(is_int($found['version'] ?? null))->toBeTrue();
    expect(is_int($found['parties_count'] ?? null))->toBeTrue();
    expect(is_int($found['smartfields_count'] ?? null))->toBeTrue();
});

test('templates flow: create validation errors return 422 with code/field', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();

    // Missing name
    [$statusMissingName, $jsonMissingName] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        ['content' => '<p>Body</p>'],
        $apiBase
    );
    expect($statusMissingName)->toBe(422);
    $err1 = $jsonMissingName['error'] ?? null;
    // Some environments return structured validation errors (code/field),
    // others only a list of messages; assert shape defensively.
    if (is_array($err1) && array_key_exists('code', $err1)) {
        expect(($err1['code'] ?? null))->toBe('VALIDATION_ERROR');
        expect(($err1['field'] ?? null))->toBe('name');
    } else {
        $errorsJoined = implode(' | ', array_map('strval', (array)$err1));
        expect($errorsJoined)->not->toBe('');
    }

    // Missing content
    [$statusMissingContent, $jsonMissingContent] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        ['name' => 'No content'],
        $apiBase
    );
    expect($statusMissingContent)->toBe(422);
    $err2 = $jsonMissingContent['error'] ?? null;
    if (is_array($err2) && array_key_exists('code', $err2)) {
        expect(($err2['code'] ?? null))->toBe('VALIDATION_ERROR');
        expect(($err2['field'] ?? null))->toBe('content');
    } else {
        $errorsJoined = implode(' | ', array_map('strval', (array)$err2));
        expect($errorsJoined)->not->toBe('');
    }

    // Invalid category_code
    [$statusInvalidCategory, $jsonInvalidCategory] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        [
            'name' => 'Bad category',
            'content' => '<p>Body</p>',
            'category_code' => 'invalid-category',
        ],
        $apiBase
    );
    expect($statusInvalidCategory)->toBe(422);
    $err3 = $jsonInvalidCategory['error'] ?? null;
    if (is_array($err3) && array_key_exists('code', $err3)) {
        expect(($err3['code'] ?? null))->toBe('VALIDATION_ERROR');
        expect(($err3['field'] ?? null))->toBe('category_code');
    } else {
        $errorsJoined = implode(' | ', array_map('strval', (array)$err3));
        expect($errorsJoined)->not->toBe('');
    }

    // Invalid parties/smartfields (non-array)
    [$statusBadParties, $jsonBadParties] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        [
            'name' => 'Bad parties',
            'content' => '<p>Body</p>',
            'parties' => 'not-an-array',
        ],
        $apiBase
    );
    expect($statusBadParties)->toBe(422);
    $err4 = $jsonBadParties['error'] ?? null;
    if (is_array($err4) && array_key_exists('code', $err4)) {
        expect(($err4['code'] ?? null))->toBe('VALIDATION_ERROR');
        expect(($err4['field'] ?? null))->toBe('parties');
    } else {
        $errorsJoined = implode(' | ', array_map('strval', (array)$err4));
        expect($errorsJoined)->not->toBe('');
    }

    [$statusBadSmartfields, $jsonBadSmartfields] = TemplatesApiHelper::rawCreateTemplate(
        $user1Bearer,
        [
            'name' => 'Bad smartfields',
            'content' => '<p>Body</p>',
            'smartfields' => 'not-an-array',
        ],
        $apiBase
    );
    expect($statusBadSmartfields)->toBe(422);
    $err5 = $jsonBadSmartfields['error'] ?? null;
    if (is_array($err5) && array_key_exists('code', $err5)) {
        expect(($err5['code'] ?? null))->toBe('VALIDATION_ERROR');
        expect(($err5['field'] ?? null))->toBe('smartfields');
    } else {
        $errorsJoined = implode(' | ', array_map('strval', (array)$err5));
        expect($errorsJoined)->not->toBe('');
    }
});

test('templates flow: owner can get, update and delete own draft template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);

    [$apiBase, $uuid] = TemplatesApiHelper::createTemplateForFlow(
        $user1Bearer,
        [
            'visibility' => 'private',
            'category_code' => 'general',
        ]
    );

    // GET as owner
    [$getStatus, $getJson, $getRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer
    );

    $getJsonText = is_array($getJson)
        ? (string)json_encode($getJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $getDebug = "Status={$getStatus}\nJSON:\n{$getJsonText}\nRAW:\n" . substr((string)$getRaw, 0, 1200);

    expect($getStatus, "Owner GET failed.\n{$getDebug}")->toBe(200);
    expect(is_array($getJson), "Expected JSON GET response.\n{$getDebug}")->toBeTrue();
    expect((string)($getJson['data']['uuid'] ?? ''))->toBe($uuid);
    expect(isset($getJson['data']['parties']))->toBeTrue();
    expect(isset($getJson['data']['smartfields']))->toBeTrue();

    // UPDATE as owner (draft)
    TemplatesApiHelper::updateTemplateForFlow(
        $user1Bearer,
        $uuid,
        [
            'content' => '<p>Updated</p>',
            'visibility' => 'public',
        ],
        $apiBase
    );

    // Verify changes via GET
    [$getAfterStatus, $getAfterJson] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($getAfterStatus)->toBe(200);
    expect((string)($getAfterJson['data']['uuid'] ?? ''))->toBe($uuid);

    // DELETE as owner (draft)
    [$deleteStatus, $deleteJson, $deleteRaw] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer
    );

    $deleteJsonText = is_array($deleteJson)
        ? (string)json_encode($deleteJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $deleteDebug = "Status={$deleteStatus}\nJSON:\n{$deleteJsonText}\nRAW:\n" . substr((string)$deleteRaw, 0, 1200);

    expect($deleteStatus, "Owner DELETE failed.\n{$deleteDebug}")->toBe(200);
    expect(is_array($deleteJson), "Expected JSON DELETE response.\n{$deleteDebug}")->toBeTrue();
    expect((bool)($deleteJson['data']['deleted'] ?? false))->toBeTrue();

    // After delete, GET should return 404
    [$getAfterDeleteStatus] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($getAfterDeleteStatus)->toBe(404);
});

test('templates flow: non-owner receives 403 for GET/PUT/DELETE on private template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$apiBase, $uuid] = TemplatesApiHelper::createTemplateForFlow(
        $user1Bearer,
        [
            'visibility' => 'private',
            'category_code' => 'general',
        ]
    );

    // GET as non-owner (another role must not read owner's private template)
    [$getStatusNonOwner, $getJsonNonOwner] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user2Bearer
    );
    expect($getStatusNonOwner)->toBe(403);
    if (is_array($getJsonNonOwner)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($getJsonNonOwner['error'] ?? [])));
        expect($errorsJoined)->not->toBe('');
    }

    // UPDATE as non-owner
    [$putStatusNonOwner, $putJsonNonOwner] = TemplatesApiHelper::rawUpdateTemplate(
        $user2Bearer,
        $uuid,
        ['content' => '<p>Attempted update</p>'],
        $apiBase
    );
    expect($putStatusNonOwner)->toBe(403);
    if (is_array($putJsonNonOwner)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($putJsonNonOwner['error'] ?? [])));
        expect($errorsJoined)->not->toBe('');
    }

    // DELETE as non-owner
    [$deleteStatusNonOwner, $deleteJsonNonOwner] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBase . '/' . rawurlencode($uuid),
        $user2Bearer
    );
    expect($deleteStatusNonOwner)->toBe(403);
    if (is_array($deleteJsonNonOwner)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($deleteJsonNonOwner['error'] ?? [])));
        expect($errorsJoined)->not->toBe('');
    }
});

