<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TemplatesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping library public browsing integration tests', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    TemplatesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Read-only library browsing (strategy §3.3): GET list, GET one, GET versions.
 * Configured test users are @simplifi.ro (internal); list still returns normalized rows and filters.
 * Server forces status=published for non-@simplifi.ro callers — not exercised here without a separate account.
 */

test('library browsing: GET list returns 200 with normalized items and pagination', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?page=1&per_page=20&sort=date_added&order=DESC',
        $bearer
    );
    $debug = "Status={$st}\n" . substr((string)$raw, 0, 1200);
    expect($st, "GET /library failed.\n{$debug}")->toBe(200);
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

test('library browsing: GET list accepts category_code and language_id query filters', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $libraryBase = TemplatesApiHelper::libraryApiBase();

    [$st, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        $libraryBase . '?category_code=general&language_id=1&page=1&per_page=10',
        $bearer
    );
    $debug = "Status={$st}\n" . substr((string)$raw, 0, 800);
    expect($st, "Filtered list failed.\n{$debug}")->toBe(200);
    expect(is_array($json))->toBeTrue();
    expect(is_array($json['data'] ?? null))->toBeTrue();
});

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
    $debug = "Status={$st}\n" . substr((string)$raw, 0, 1500);
    expect($st, "GET /library/{uuid} failed.\n{$debug}")->toBe(200);
    expect(is_array($json))->toBeTrue();
    expect((string)($json['data']['uuid'] ?? ''))->toBe($uuid);
    expect(isset($json['data']['parties']))->toBeTrue();
    expect(is_array($json['data']['parties']))->toBeTrue();
    expect(isset($json['data']['smartfields']))->toBeTrue();
    expect(is_array($json['data']['smartfields']))->toBeTrue();
    expect(is_bool($json['data']['can_archive'] ?? null))->toBeTrue();
});

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
    $debug = "Status={$st}\n" . substr((string)$raw, 0, 1200);
    expect($st, "GET /library/{uuid}/versions failed.\n{$debug}")->toBe(200);
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
