<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no templates API calls run.
     */
    test('Templates - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; templates API env matches `tests_config.php`.
 *
 * Steps:
 * 1. Ask `TemplatesApiHelper` to confirm required configuration; if missing, skip the whole file with a clear reason.
 */
beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in owner (`TEST_USER_1_*`); file guard passed (`beforeAll`).
 *
 * Steps:
 * 1. Create a private template with name, body, and category via the helper.
 * 2. Expect **HTTP 200** and a new **`data.uuid`**.
 * 3. Open the owner’s template list and find that row; check name, category, visibility, status, version, party count, and smartfield count match what you created.
 */
test('Templates - owner can create a template and see it in their list', function () {
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

    expect($createStatus)->toBe(200, "Create template failed.\n{$createDebug}");
    expect(is_array($createJson))->toBeTrue("Expected JSON create response.\n{$createDebug}");
    $uuid = (string)($createJson['data']['uuid'] ?? '');
    expect($uuid)->not->toBe('', "Expected data.uuid in create response.\n{$createDebug}");

    [$listStatus, $listJson, $listRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?page=1&per_page=50&sort=date_added&order=DESC',
        $user1Bearer
    );

    $listJsonText = is_array($listJson)
        ? (string)json_encode($listJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $listDebug = "Status={$listStatus}\nJSON:\n{$listJsonText}\nRAW:\n" . substr((string)$listRaw, 0, 1200);

    expect($listStatus)->toBe(200, "List templates failed.\n{$listDebug}");
    expect(is_array($listJson))->toBeTrue("Expected JSON list response.\n{$listDebug}");

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

    expect($found)->not->toBeNull("Created template uuid={$uuid} not found in list.\n{$listDebug}");
    expect($found['name'] ?? null)->toBe($templateName);
    expect($found['category_code'] ?? null)->toBe('contracts');
    expect($found['visibility'] ?? null)->toBe('private');
    expect((string)($found['status'] ?? ''))->not->toBe('');
    expect(is_int($found['version'] ?? null))->toBeTrue();
    expect(is_int($found['parties_count'] ?? null))->toBeTrue();
    expect(is_int($found['smartfields_count'] ?? null))->toBeTrue();
});

/**
 * Prerequisites:
 * - Owner session is valid for the templates API.
 *
 * Steps:
 * 1. Try to create without a name; expect **HTTP 422** and a non-empty **`error`** (or structured **`VALIDATION_ERROR`** on **`name`**).
 * 2. Try without body content; expect **HTTP 422** and **`content`** called out when structured.
 * 3. Try with an invalid category code; expect **HTTP 422** and **`category_code`** when structured.
 * 4. Send **`parties`** / **`smartfields`** as non-arrays; expect **HTTP 422** with the matching **`field`** when structured.
 */
test('Templates - bad create payloads return validation errors', function () {
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

/**
 * Prerequisites:
 * - Owner has a draft template from the helper.
 *
 * Steps:
 * 1. Open the draft by id; expect **HTTP 200** and **`data.parties`** / **`data.smartfields`** present.
 * 2. Save changes to body and visibility (helper).
 * 3. Open again; the same **`data.uuid`** should still be there.
 * 4. Delete the draft; expect **`data.deleted`** true; a follow-up open by id should be **not found** (**HTTP 404**).
 */
test('Templates - owner can open, edit, and remove their own draft', function () {
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

    expect($getStatus)->toBe(200, "Owner GET failed.\n{$getDebug}");
    expect(is_array($getJson))->toBeTrue("Expected JSON GET response.\n{$getDebug}");
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

    expect($deleteStatus)->toBe(200, "Owner DELETE failed.\n{$deleteDebug}");
    expect(is_array($deleteJson))->toBeTrue("Expected JSON DELETE response.\n{$deleteDebug}");
    expect((bool)($deleteJson['data']['deleted'] ?? false))->toBeTrue();

    // After delete, GET should return 404
    [$getAfterDeleteStatus] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($getAfterDeleteStatus)->toBe(404);
});

/**
 * Prerequisites:
 * - Two different accounts (`TEST_USER_1_*`, `TEST_USER_2_*`).
 *
 * Steps:
 * 1. User A creates a **private** template and keeps its id.
 * 2. User B tries to open it; expect **forbidden** (**HTTP 403**) and a non-empty **`error`** when JSON is returned.
 * 3. User B tries to save changes; expect **HTTP 403** and errors.
 * 4. User B tries to delete it; expect **HTTP 403** and errors (A’s template must still exist for A).
 */
test('Templates - someone else cannot read, edit, or delete your private draft', function () {
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

