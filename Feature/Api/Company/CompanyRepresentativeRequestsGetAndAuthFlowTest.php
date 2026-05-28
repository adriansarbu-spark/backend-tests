<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/CompanyRepresentativeApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no company representative-requests API calls run.
     */
    test('Company representative-requests - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak and `TEST_USER_1_*` are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    CompanyRepresentativeApiHelper::assertRepresentativeCompanyConfigOrSkip();
});

/**
 * Prerequisites:
 * - Anonymous HTTP client (no bearer).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representative-requests` without Authorization.
 * 2. Expect refusal (**HTTP 401** or **HTTP 404** `user_not_found`) and non-empty **`error`** when JSON is returned.
 */
test('Company representative-requests - listing without a token is refused', function () {
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::requestWithoutAuth(
        'GET',
        CompanyRepresentativeApiHelper::representativeRequestsUrl()
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 400);

    expect(in_array($status, [401, 404], true))->toBeTrue('Expected refusal without token. ' . $debug);
    if (is_array($json)) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Invalid bearer token.
 *
 * Steps:
 * 1. **GET** with bogus Authorization.
 * 2. Expect **HTTP 401** or **HTTP 403** and non-empty **`error`** when JSON is returned.
 */
test('Company representative-requests - listing with an invalid token is refused', function () {
    [$status, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        CompanyRepresentativeApiHelper::representativeRequestsUrl(),
        'Bearer invalid-token-for-integration-test'
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 500);

    expect(in_array($status, [401, 403], true))->toBeTrue($debug);
    if (is_array($json)) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in company member (`TEST_USER_1_*`) with valid company and role context.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representative-requests?page=1&per_page=20`.
 * 2. Expect **HTTP 200**, **`data.requests`** as an array, and **`pagination`** with **`total`** (integer).
 * 3. For each request row, assert a non-empty **`request_uuid`** and **`status`** when rows exist.
 */
test('Company representative-requests - member can list requests with pagination meta', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestsUrl() . '?page=1&per_page=20',
        $bearer
    );
    $debug = "Status={$status}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, $debug);
    expect(is_array($json))->toBeTrue($debug);
    expect(isset($json['data']['requests']))->toBeTrue($debug);
    expect($json['pagination'] ?? null)->toBeArray();
    expect(isset($json['pagination']['total']))->toBeTrue($debug);

    foreach ((array)($json['data']['requests'] ?? []) as $idx => $row) {
        expect(is_array($row))->toBeTrue("Row {$idx} must be an object. {$debug}");
        expect((string)($row['request_uuid'] ?? ''))->not->toBe('');
        expect((string)($row['status'] ?? ''))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in member (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. Send **DELETE** to the collection URL (unsupported).
 * 2. Expect **HTTP 405** (**method not allowed**).
 */
test('Company representative-requests - unsupported HTTP method returns 405', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$status, , $raw] = ApiAuthHelper::apiRequest(
        'DELETE',
        CompanyRepresentativeApiHelper::representativeRequestsUrl(),
        $bearer
    );
    expect($status)->toBe(405, 'raw=' . substr($raw, 0, 600));
});
