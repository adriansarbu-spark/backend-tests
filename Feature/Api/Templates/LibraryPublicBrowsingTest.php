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
 * 1. Open the library list with normal pagination and sort.
 * 2. Expect **HTTP 200** and **`data`** as an array; for the first row that has an id, check version and count fields look numeric and **`status`** / **`category_code`** are non-empty.
 */
test('Library - list loads and first rows look well shaped', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?page=1&per_page=20&sort=date_added&order=DESC',
        $bearer
    );
    expect($st)->toBe(200, "GET /library failed.\nStatus={$st}\n" . substr((string)$raw, 0, 1200));
    expect(is_array($json))->toBeTrue();
    expect(isset($json['data']))->toBeTrue();
    expect(is_array($json['data']))->toBeTrue();

    foreach ((array)$json['data'] as $row) {
        if (!is_array($row) || (string)($row['uuid'] ?? '') === '') {
            continue;
        }
        expect(is_int($row['version'] ?? null) || is_numeric($row['version'] ?? null))->toBeTrue();
        expect(is_int($row['parties_count'] ?? null) || is_numeric($row['parties_count'] ?? null))->toBeTrue();
        expect(is_int($row['smartfields_count'] ?? null) || is_numeric($row['smartfields_count'] ?? null))->toBeTrue();
        expect(is_int($row['language_id'] ?? null) || is_numeric($row['language_id'] ?? null))->toBeTrue();
        expect((string)($row['status'] ?? ''))->not->toBe('');
        expect((string)($row['category_code'] ?? ''))->not->toBe('');
        break;
    }
});

/**
 * Prerequisites:
 * - Library API reachable with a normal session.
 *
 * Steps:
 * 1. Open the library list filtered by category and language.
 * 2. Expect **HTTP 200** and **`data`** as an array.
 */
test('Library - list accepts category and language filters', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?category_code=general&language_id=1&page=1&per_page=10',
        $bearer
    );
    expect($st)->toBe(200, "Filtered list failed.\nStatus={$st}\n" . substr((string)$raw, 0, 800));
    expect(is_array($json))->toBeTrue();
    expect(is_array($json['data'] ?? null))->toBeTrue();
});

/**
 * Prerequisites:
 * - At least one **published** library row exists (otherwise skip).
 *
 * Steps:
 * 1. Load the published list and take the first id.
 * 2. Open that row; expect **HTTP 200**, matching **`data.uuid`**, **`parties`** / **`smartfields`** arrays present, and **`can_archive`** is a boolean.
 */
test('Library - single item shows parties, smartfields, and archive flag', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$listSt, $listJson] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?status=published&page=1&per_page=5',
        $bearer
    );
    expect($listSt)->toBe(200);

    $uuid = '';
    foreach ((array)($listJson['data'] ?? []) as $row) {
        if (is_array($row) && (string)($row['uuid'] ?? '') !== '') {
            $uuid = (string)$row['uuid'];
            break;
        }
    }

    if ($uuid === '') {
        test()->markTestSkipped('No library row available for GET single');
    }

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid),
        $bearer
    );
    expect($st)->toBe(200, "GET /library/{uuid} failed.\nStatus={$st}\n" . substr((string)$raw, 0, 1500));
    expect(is_array($json))->toBeTrue();
    expect((string)($json['data']['uuid'] ?? ''))->toBe($uuid);
    expect(isset($json['data']['parties']))->toBeTrue();
    expect(is_array($json['data']['parties']))->toBeTrue();
    expect(isset($json['data']['smartfields']))->toBeTrue();
    expect(is_array($json['data']['smartfields']))->toBeTrue();
    expect(is_bool($json['data']['can_archive'] ?? null))->toBeTrue();
});

/**
 * Prerequisites:
 * - A **published** library id is available (otherwise skip).
 *
 * Steps:
 * 1. Resolve an id from the published list.
 * 2. Open version history with sort, order, and page size.
 * 3. Expect **HTTP 200** and **`data`** as an array; the first version row has a non-empty id, a version number field, and non-empty **`status`**.
 */
test('Library - version history returns rows for a published item', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$listSt, $listJson] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?status=published&page=1&per_page=5',
        $bearer
    );
    expect($listSt)->toBe(200);

    $uuid = '';
    foreach ((array)($listJson['data'] ?? []) as $row) {
        if (is_array($row) && (string)($row['uuid'] ?? '') !== '') {
            $uuid = (string)$row['uuid'];
            break;
        }
    }

    if ($uuid === '') {
        test()->markTestSkipped('No library row available for GET versions');
    }

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($uuid) . '/versions?page=1&per_page=20&sort=version&order=DESC',
        $bearer
    );
    expect($st)->toBe(200, "GET /library/{uuid}/versions failed.\nStatus={$st}\n" . substr((string)$raw, 0, 1200));
    expect(is_array($json))->toBeTrue();
    expect(is_array($json['data'] ?? null))->toBeTrue();

    foreach ((array)($json['data'] ?? []) as $v) {
        if (!is_array($v)) {
            continue;
        }
        expect((string)($v['uuid'] ?? ''))->not->toBe('');
        expect(isset($v['version_number']))->toBeTrue();
        expect((string)($v['status'] ?? ''))->not->toBe('');
        break;
    }
});

/**
 * Prerequisites:
 * - Normal signed-in session.
 *
 * Steps:
 * 1. Open a well-formed library id that does not exist.
 * 2. Expect **not found** (**HTTP 404**); **`error`** non-empty when JSON is returned.
 */
test('Library - unknown id returns not found', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();
    $fake = '00000000-0000-4000-8000-000000000099';

    [$st, $json] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '/' . rawurlencode($fake),
        $bearer
    );
    expect($st)->toBe(404);
    if (is_array($json)) {
        expect(implode(' ', array_map('strval', (array)($json['error'] ?? []))))->not->toBe('');
    }
});
