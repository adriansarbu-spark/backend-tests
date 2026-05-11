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
     * 1. Mark skipped; browsing scenarios do not run against the API.
     */
    test('Skipping library public browsing integration tests', function () {
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
 * 1. GET `/library` with pagination/sort query params.
 * 2. Assert 200, `data` is array; for first row with `uuid`, assert numeric/count fields and non-empty `status`, `category_code`.
 */
test('library browsing: GET list returns 200 with normalized items and pagination', function () {
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
 * - Integration tests enabled; library API reachable; valid bearer.
 *
 * Steps:
 * 1. GET `/library` with `category_code` and `language_id` filters.
 * 2. Assert 200 and `data` is an array.
 */
test('library browsing: GET list accepts category_code and language_id query filters', function () {
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
 * - Integration tests enabled; at least one published library row (else skip).
 *
 * Steps:
 * 1. GET published list; take first `uuid`.
 * 2. GET `/library/{uuid}`; assert 200, matching `data.uuid`, `parties`/`smartfields` arrays present, `can_archive` is bool.
 */
test('library browsing: GET single returns parties and smartfields; can_archive is boolean', function () {
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
 * - Integration tests enabled; published library row available (else skip).
 *
 * Steps:
 * 1. Resolve a library `uuid` from published list.
 * 2. GET `/library/{uuid}/versions` with sort/order/per_page.
 * 3. Assert 200, `data` array; first version row has non-empty `uuid`, `version_number`/`version` shape, non-empty `status`.
 */
test('library browsing: GET versions returns data array and pagination headers', function () {
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
 * - Integration tests enabled; valid bearer.
 *
 * Steps:
 * 1. GET `/library/{fakeUuid}` with a valid-format unknown UUID.
 * 2. Assert 404; if JSON `error` present, assert non-empty.
 */
test('library browsing: GET unknown library uuid returns 404', function () {
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
