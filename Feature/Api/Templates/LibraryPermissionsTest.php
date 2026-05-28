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
     * 1. Mark this placeholder as skipped so no library API calls run.
     */
    test('Library - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; templates/library API env matches `tests_config.php`.
 *
 * Steps:
 * 1. Ask `TemplatesApiHelper` to confirm required configuration; if missing, skip the whole file with a clear reason.
 */
beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`); file guard passed (`beforeAll`).
 *
 * Steps:
 * 1. Send **PATCH** to the library collection root (unsupported).
 * 2. Expect **HTTP 405**; JSON **`error`** non-empty or raw body non-empty.
 */
test('Library - PATCH on the library collection is not supported', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest('PATCH', $libraryBase, $bearer);
    expect($st)->toBe(405);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    } else {
        expect(strlen((string)$raw))->toBeGreaterThan(0);
    }
});

/**
 * Prerequisites:
 * - Library API reachable; user may delete when policy allows (**HTTP 403** on this call means the test skips).
 *
 * Steps:
 * 1. Try to delete a well-formed id that does not exist in the library.
 * 2. Expect **not found** (**HTTP 404**); JSON **`error`** non-empty when present.
 */
test('Library - delete with an unknown id returns not found', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $fake = '00000000-0000-4000-8000-000000000088';

    [$st, $json] = ApiAuthHelper::apiRequest(
        'DELETE',
        $libraryBase . '/' . rawurlencode($fake),
        $bearer
    );

    if ($st === 403) {
        test()->markTestSkipped('Library DELETE not allowed for test user (403).');
    }
    expect($st)->toBe(404);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Library API reachable; publish may be denied for the test user (**HTTP 403** means skip).
 *
 * Steps:
 * 1. Try to publish a well-formed id that is not in the library.
 * 2. Expect **HTTP 404**; **`error`** non-empty when JSON is returned.
 */
test('Library - publish with an unknown id returns not found', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $fake = '00000000-0000-4000-8000-000000000077';

    [$st, $json] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase . '/' . rawurlencode($fake) . '/publish',
        $bearer
    );

    if ($st === 403) {
        test()->markTestSkipped('Library publish not allowed for test user (403).');
    }
    expect($st)->toBe(404);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - User may POST library rows (**HTTP 403** means skip).
 *
 * Steps:
 * 1. Try to create a library entry with name and body but **no** language id.
 * 2. Expect **HTTP 422**; structured responses should use **`VALIDATION_ERROR`** on **`language_id`**, otherwise any non-empty **`error`**.
 */
test('Library - create without language is rejected', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json] = ApiAuthHelper::apiRequest(
        'POST',
        $libraryBase,
        $bearer,
        [
            'json' => [
                'name' => 'No language ' . gmdate('YmdHis'),
                'content' => '<p>x</p>',
            ],
        ]
    );

    if ($st === 403) {
        test()->markTestSkipped('Library POST not allowed for test user (403).');
    }
    expect($st)->toBe(422);
    $err = $json['error'] ?? null;
    if (is_array($err) && array_key_exists('code', $err)) {
        expect($err['code'] ?? null)->toBe('VALIDATION_ERROR');
        expect($err['field'] ?? null)->toBe('language_id');
    } else {
        expect(implode(' ', array_map('strval', (array)$err)))->not->toBe('');
    }
});
