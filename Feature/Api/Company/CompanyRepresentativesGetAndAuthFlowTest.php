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
     * 1. Mark this placeholder as skipped so no company representatives API calls run.
     */
    test('Company representatives - integration tests are turned off for this run', function () {
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
 * - Anonymous HTTP client.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representatives` without Authorization.
 * 2. Expect **HTTP 401** or **HTTP 404** (`user_not_found`) and non-empty **`error`** when JSON is returned.
 */
test('Company representatives - listing without a token is refused', function () {
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::requestWithoutAuth(
        'GET',
        CompanyRepresentativeApiHelper::representativesUrl()
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 400);

    expect(in_array($status, [401, 404], true))->toBeTrue($debug);
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
test('Company representatives - listing with an invalid token is refused', function () {
    [$status, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        CompanyRepresentativeApiHelper::representativesUrl(),
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
 * - Signed-in company member (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representatives?page=1&per_page=20`.
 * 2. Expect **HTTP 200**, **`data.representatives`** as an array, and **`pagination.total`** present.
 * 3. For each row, assert non-empty **`representative_uuid`** and **`status`**.
 */
test('Company representatives - member can list representatives with pagination meta', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativesUrl() . '?page=1&per_page=20',
        $bearer
    );
    $debug = "Status={$status}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, $debug);
    expect(is_array($json))->toBeTrue($debug);
    expect(isset($json['data']['representatives']))->toBeTrue($debug);
    expect($json['pagination'] ?? null)->toBeArray();
    expect(isset($json['pagination']['total']))->toBeTrue($debug);

    foreach ((array)($json['data']['representatives'] ?? []) as $idx => $row) {
        expect(is_array($row))->toBeTrue("Row {$idx} must be an object. {$debug}");
        expect((string)($row['representative_uuid'] ?? ''))->not->toBe('');
        expect((string)($row['status'] ?? ''))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in company member (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representatives/eligibility`.
 * 2. Expect **HTTP 200** and **`data`** as an associative array (eligibility payload from the model).
 */
test('Company representatives - member can read eligibility payload', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativesEligibilityUrl(),
        $bearer
    );
    $debug = "Status={$status}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, $debug);
    expect(is_array($json))->toBeTrue($debug);
    expect(isset($json['data']) && is_array($json['data']))->toBeTrue($debug);
});

/**
 * Prerequisites:
 * - Signed-in member (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **PUT** `/publicapi/v1/company/representatives` (unsupported).
 * 2. Expect **HTTP 405**.
 */
test('Company representatives - unsupported HTTP method on list route returns 405', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$status, $json, $raw] = ApiAuthHelper::apiRequest(
        'PUT',
        CompanyRepresentativeApiHelper::representativesUrl(),
        $bearer
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(405, $debug);
    if (is_array($json)) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('method_not_allowed');
    }
});
