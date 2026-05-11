<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - `SKIP_INTEGRATION_TESTS` is true in `tests_config.php`.
     *
     * Steps:
     * 1. Mark skipped; versioning scenarios do not run.
     */
    test('Skipping templates versioning integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests enabled; `createTemplateForFlow` succeeds for `TEST_USER_1_*`.
 *
 * Steps:
 * 1. POST publish draft → 200; `data.status` published.
 * 2. POST publish again → 422; non-empty structured or string `error`.
 * 3. POST archive → 200; `data.status` archived.
 * 4. POST archive again → 422; non-empty `error`.
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
    expect($pubStatus)->toBe(200, "Publish failed.\nStatus={$pubStatus}\n" . substr((string)$pubRaw, 0, 800));
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
    expect($archStatus)->toBe(200, "Archive failed.\nStatus={$archStatus}\n" . substr((string)$archRaw, 0, 800));
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

/**
 * Prerequisites:
 * - Integration tests enabled; draft template from `createTemplateForFlow`.
 *
 * Steps:
 * 1. POST `/templates/{uuid}/versions` with empty JSON body (no content).
 * 2. Assert 422; structured error expects `VALIDATION_ERROR` and `field` `content`, else non-empty error.
 */
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

/**
 * Prerequisites:
 * - Integration tests enabled; template uuid from helper.
 *
 * Steps:
 * 1. GET versions with invalid `sort` query value.
 * 2. Assert 422; non-empty `error` in JSON when present.
 */
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

/**
 * Prerequisites:
 * - Integration tests enabled; user can publish, clone, delete draft, and edit published.
 *
 * Steps:
 * 1. Create and publish template A.
 * 2. POST clone → new uuid, `status` draft; DELETE the clone draft (cleanup).
 * 3. POST edit on published A → new draft uuid in response with `status` draft.
 */
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
    expect($cloneStatus)->toBe(200, "Clone failed.\nStatus={$cloneStatus}\n" . substr((string)$cloneRaw, 0, 800));
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
    expect($editStatus)->toBe(200, "Edit published failed.\nStatus={$editStatus}\n" . substr((string)$editRaw, 0, 800));
    $draftUuid = (string)($editJson['data']['uuid'] ?? '');
    expect($draftUuid)->not->toBe('');
    expect((string)($editJson['data']['status'] ?? ''))->toBe('draft');
});

/**
 * Prerequisites:
 * - Integration tests enabled; template lifecycle publish allowed.
 *
 * Steps:
 * 1. Create template and publish it.
 * 2. DELETE same uuid while published.
 * 3. Assert 422 (must not treat as success); non-empty `error` when JSON.
 */
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
