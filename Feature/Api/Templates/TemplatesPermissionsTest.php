<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping templates permissions integration tests', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Authorization and tenancy for templates: non-owner GET/DELETE on private templates (getTemplate, deleteTemplate),
 * version routes, list isolation, unsupported HTTP methods. Follows documents-testing-guidelines.md (403 + non-empty errors).
 */

test('templates permissions: non-owner denied on GET single private template', function () {
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

test('templates permissions: non-owner denied on DELETE single private template', function () {
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

test('templates permissions: non-owner denied on versions list and create version (private template)', function () {
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

test('templates permissions: non-owner denied on publish and clone (private template)', function () {
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

test('templates permissions: other user list does not contain foreign private template uuid', function () {
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
    $debug = "Status={$listSt}\n" . substr((string)$listRaw, 0, 1200);
    expect($listSt, "List as user2 failed unexpectedly.\n{$debug}")->toBe(200);
    expect(is_array($listJson))->toBeTrue();

    foreach ((array)($listJson['data'] ?? []) as $item) {
        if (is_array($item)) {
            expect((string)($item['uuid'] ?? ''))->not->toBe($uuid);
        }
    }
});

test('templates permissions: unsupported PATCH on collection returns 405', function () {
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
