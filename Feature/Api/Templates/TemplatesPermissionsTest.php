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
 * - Two different test accounts (`TEST_USER_1_*`, `TEST_USER_2_*`).
 *
 * Steps:
 * 1. User A creates a **private** template.
 * 2. User B tries to open it by id; expect **forbidden** (**HTTP 403**) and a non-empty **`error`** when JSON is returned.
 */
test('Templates - someone else cannot open your private template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$apiBase, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($user1Bearer, [
        'visibility' => 'private',
        'category_code' => 'general',
    ]);

    // GET /templates/{uuid} → getTemplate(); private template, different customer_role → 403
    [$getSt, $getJson] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($templateUuid),
        $user2Bearer
    );
    expect($getSt)->toBe(403);
    if (is_array($getJson)) {
        expect(implode(' ', array_map('strval', (array)($getJson['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - User A has a **private** template (helper).
 *
 * Steps:
 * 1. User B tries to delete A’s template by id.
 * 2. Expect **HTTP 403** and a non-empty **`error`** when JSON is returned (B must not remove A’s row).
 */
test('Templates - someone else cannot delete your private template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$apiBase, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($user1Bearer, [
        'visibility' => 'private',
        'category_code' => 'general',
    ]);

    // DELETE /templates/{uuid} → deleteTemplate(); private template, different customer_role → 403
    [$delSt, $delJson] = ApiAuthHelper::apiRequest(
        'DELETE',
        $apiBase . '/' . rawurlencode($templateUuid),
        $user2Bearer
    );
    expect($delSt)->toBe(403);
    if (is_array($delJson)) {
        expect(implode(' ', array_map('strval', (array)($delJson['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - User A owns a **private** template.
 *
 * Steps:
 * 1. User B tries to list version history; expect **HTTP 403** and non-empty **`error`** when JSON is returned.
 * 2. User B tries to add a version with a body; expect **HTTP 403** and non-empty **`error`**.
 */
test('Templates - someone else cannot list or add versions on your private template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$apiBase, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($user1Bearer, [
        'visibility' => 'private',
        'category_code' => 'general',
    ]);

    [$listSt, $listJson] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '/' . rawurlencode($templateUuid) . '/versions',
        $user2Bearer
    );
    expect($listSt)->toBe(403);
    if (is_array($listJson)) {
        expect(implode(' ', array_map('strval', (array)($listJson['error'] ?? []))))->not->toBe('');
    }

    [$postSt, $postJson] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBase . '/' . rawurlencode($templateUuid) . '/versions',
        $user2Bearer,
        [
            'json' => [
                'content' => '<p>Malicious</p>',
            ],
        ]
    );
    expect($postSt)->toBe(403);
    if (is_array($postJson)) {
        expect(implode(' ', array_map('strval', (array)($postJson['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - User A has a **private** draft template.
 *
 * Steps:
 * 1. User B tries to publish A’s template; expect **HTTP 403** and non-empty **`error`**.
 * 2. User B tries to duplicate A’s template; expect **HTTP 403** and non-empty **`error`**.
 */
test('Templates - someone else cannot publish or duplicate your private template', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$apiBase, $templateUuid] = TemplatesApiHelper::createTemplateForFlow($user1Bearer, [
        'visibility' => 'private',
        'category_code' => 'general',
    ]);

    [$pubSt, $pubJson] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBase . '/' . rawurlencode($templateUuid) . '/publish',
        $user2Bearer
    );
    expect($pubSt)->toBe(403);
    if (is_array($pubJson)) {
        expect(implode(' ', array_map('strval', (array)($pubJson['error'] ?? []))))->not->toBe('');
    }

    [$cloneSt, $cloneJson] = ApiAuthHelper::apiRequest(
        'POST',
        $apiBase . '/' . rawurlencode($templateUuid) . '/clone',
        $user2Bearer
    );
    expect($cloneSt)->toBe(403);
    if (is_array($cloneJson)) {
        expect(implode(' ', array_map('strval', (array)($cloneJson['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Two accounts; A can create a uniquely named **private** template.
 *
 * Steps:
 * 1. User A creates that template and keeps its id.
 * 2. User B scrolls their own template list; B’s rows must **not** include A’s id (tenant isolation).
 */
test('Templates - another account’s list does not leak your private template id', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $suffix = gmdate('YmdHis');
    [$apiBase, $uuid] = TemplatesApiHelper::createTemplateForFlow(
        $user1Bearer,
        [
            'name' => 'Tenant isolation private ' . $suffix,
            'visibility' => 'private',
            'category_code' => 'general',
        ]
    );

    [$listSt, $listJson, $listRaw] = ApiAuthHelper::apiRequest(
        'GET',
        $apiBase . '?page=1&per_page=100&sort=date_added&order=DESC',
        $user2Bearer
    );
    expect($listSt)->toBe(200, "List as user2 failed unexpectedly.\nStatus={$listSt}\n" . substr((string)$listRaw, 0, 1200));
    expect(is_array($listJson))->toBeTrue();

    foreach ((array)($listJson['data'] ?? []) as $item) {
        if (is_array($item)) {
            expect((string)($item['uuid'] ?? ''))->not->toBe($uuid);
        }
    }
});

/**
 * Prerequisites:
 * - Signed-in user with a normal templates session.
 *
 * Steps:
 * 1. Send **PATCH** to the templates collection root (not supported).
 * 2. Expect **method not allowed** (**HTTP 405**); JSON **`error`** non-empty or raw body non-empty.
 */
test('Templates - PATCH on the template collection is not supported', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $apiBase = TemplatesApiHelper::apiBase();

    [$status, $json, $raw] = ApiAuthHelper::apiRequest('PATCH', $apiBase, $bearer);

    expect($status)->toBe(405);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    } else {
        expect(strlen((string)$raw))->toBeGreaterThan(0);
    }
});
