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
 * - Integration tests are on (`SKIP_INTEGRATION_TESTS` false); templates API env matches `tests_config.php`.
 *
 * Steps:
 * 1. Ask `TemplatesApiHelper` to confirm required configuration; if missing, skip the whole file with a clear reason.
 */
beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in owner (`TEST_USER_1_*`); helper can create a draft template (`createTemplateForFlow`).
 *
 * Steps:
 * 1. Publish the draft; expect success (**HTTP 200**) and **`data.status`** reads **published**.
 * 2. Publish again; the second attempt must be refused (**HTTP 422**) with a non-empty **`error`** (structured or messages).
 * 3. Archive once; expect **HTTP 200** and **`data.status`** **archived**.
 * 4. Archive again; expect **HTTP 422** with non-empty **`error`** (invalid transition).
 */
test('Templates - publish then archive; repeat actions get a validation error', function () {
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
 * - A draft template exists for the owner (helper).
 *
 * Steps:
 * 1. Try to add a new version with an empty body (no document body).
 * 2. Expect refusal (**HTTP 422**); when the API returns structured validation, expect **`VALIDATION_ERROR`** on **`content`**, otherwise any non-empty **`error`** payload.
 */
test('Templates - new version without body is rejected', function () {
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
 * - A template exists for listing versions (helper).
 *
 * Steps:
 * 1. Open the version history with a bogus sort column in the query string.
 * 2. Expect **HTTP 422**; if JSON is returned, **`error`** must not be empty.
 */
test('Templates - invalid sort on version list is rejected', function () {
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
 * - Owner can publish, duplicate, remove a draft copy, and start an edit from a live template.
 *
 * Steps:
 * 1. Create a template and publish it.
 * 2. Duplicate it; expect a **new** id and **`data.status`** **draft**; delete that duplicate draft to clean up.
 * 3. Start an edit from the still-published original; expect **HTTP 200** and a **new** draft id in **`data.uuid`** with **`data.status`** **draft**.
 */
test('Templates - duplicate published template and edit published yields a new draft', function () {
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
 * - Owner can publish a template.
 *
 * Steps:
 * 1. Publish a template so it is no longer a plain draft.
 * 2. Try to delete it outright while still published.
 * 3. Expect refusal (**HTTP 422**), not success; JSON should carry a non-empty **`error`** when present.
 */
test('Templates - deleting a published template outright is not allowed', function () {
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
