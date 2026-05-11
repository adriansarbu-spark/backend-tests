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
     * 1. Mark skipped; permission scenarios do not run.
     */
    test('Skipping library permissions integration tests', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests enabled; `TemplatesApiHelper::assertRequiredConfigOrSkip()` in `beforeAll`.
 * - Bearer for `TEST_USER_1_*`.
 *
 * Steps:
 * 1. PATCH the library collection URL (unsupported method).
 * 2. Assert 405; assert non-empty `error` in JSON or non-empty raw body.
 */
test('library permissions: unsupported PATCH on collection returns 405', function () {
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
 * - Integration tests enabled; bearer for user with DELETE permission when applicable (403 → skip).
 *
 * Steps:
 * 1. DELETE `/library/{unknownUuid}`.
 * 2. Assert 404 (not success); if JSON, assert non-empty `error`.
 */
test('library permissions: DELETE unknown uuid returns 404', function () {
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
 * - Integration tests enabled; publish allowed for user when applicable (403 → skip).
 *
 * Steps:
 * 1. POST publish on unknown library UUID.
 * 2. Assert 404; non-empty `error` when JSON.
 */
test('library permissions: POST publish on unknown uuid returns 404', function () {
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
 * - Integration tests enabled; POST library allowed (403 → skip).
 *
 * Steps:
 * 1. POST create with `name` and `content` but omit `language_id`.
 * 2. Assert 422; structured error expects `VALIDATION_ERROR` and `field` `language_id`, else non-empty error.
 */
test('library permissions: POST create missing language_id returns 422', function () {
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
