<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/AccountCompaniesApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no account companies API calls run.
     */
    test('Account companies - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak and test users in `tests_config.php` match the API under test.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file so later tests do not fail mid-run.
 */
beforeAll(function () {
    AccountCompaniesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - File guard passed (`beforeAll`); anonymous HTTP client (no bearer token).
 *
 * Steps:
 * 1. Call **GET** `/publicapi/v1/account/companies` without an Authorization header.
 * 2. Expect the stack to refuse access before returning a company list: either **HTTP 401** with **`unauthenticated`** from the controller, or **HTTP 404** with **`user_not_found`** when the gateway resolves “no user” first (both mean “not signed in” for this environment).
 * 3. When JSON is present, assert **`error`** is non-empty so we do not pass on an empty body.
 */
test('Account companies - listing without a token is refused', function () {
    [$status, $json, $raw] = AccountCompaniesApiHelper::requestWithoutAuth('GET');
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 400);

    expect(in_array($status, [401, 404], true))->toBeTrue(
        'Expected refusal for GET without token (401 from controller or 404 user_not_found from gateway). ' . $debug
    );
    if (is_array($json)) {
        $err = AccountCompaniesApiHelper::joinedErrors($json);
        expect($err)->not->toBe('');
        if ($status === 401) {
            expect($err)->toContain('unauthenticated');
        }
        if ($status === 404) {
            expect($err)->toContain('user_not_found');
        }
    }
});

/**
 * Prerequisites:
 * - File guard passed; a deliberately invalid bearer string (not a Keycloak access token).
 *
 * Steps:
 * 1. Call **GET** `/publicapi/v1/account/companies` with a bogus Authorization header.
 * 2. Expect a refusal (**HTTP 401** or **HTTP 403** from gateway or app) and, when JSON is present, a non-empty **`error`** payload so we do not pass on an empty body.
 */
test('Account companies - listing with an invalid token is refused', function () {
    [$status, $json, $raw] = ApiAuthHelper::apiRequest(
        'GET',
        AccountCompaniesApiHelper::url(),
        'Bearer invalid-token-for-integration-test'
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 500);

    expect(in_array($status, [401, 403], true))->toBeTrue('Expected auth failure for invalid bearer. ' . $debug);
    if (is_array($json)) {
        expect(AccountCompaniesApiHelper::joinedErrors($json))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`); file guard passed.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/account/companies` with a real bearer token.
 * 2. Expect **HTTP 200** and **`data.companies`** as a list where each company has a non-empty **`company_uuid`**, keys **`company_name`** and **`country`** (name may be blank in legacy data), **`is_personal`**, and a **`roles`** array whose entries include **`role_uuid`** and **`role_code`**.
 */
test('Account companies - signed-in user can list their companies with UUIDs and roles', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, $json, $raw] = AccountCompaniesApiHelper::get($bearer);

    $jsonText = is_array($json) ? (string)json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'null';
    $debug = "Status={$status}\nJSON:\n{$jsonText}\nRAW:\n" . substr($raw, 0, 1200);

    expect($status)->toBe(200, "GET companies failed.\n{$debug}");
    expect(is_array($json))->toBeTrue("Expected JSON list response.\n{$debug}");

    $companies = (array)($json['data']['companies'] ?? []);
    expect($companies)->toBeArray();

    foreach ($companies as $idx => $company) {
        expect(is_array($company))->toBeTrue("Company row {$idx} must be an object.\n{$debug}");
        expect((string)($company['company_uuid'] ?? ''))->not->toBe('');
        expect($company)->toHaveKey('company_name');
        expect($company)->toHaveKey('country');
        expect((string)($company['country'] ?? ''))->toMatch('/^[A-Z]{2}$/');
        expect($company)->toHaveKey('is_personal');
        $roles = (array)($company['roles'] ?? []);
        expect($roles)->toBeArray();
        foreach ($roles as $rIdx => $role) {
            expect(is_array($role))->toBeTrue("Role {$rIdx} under company {$idx} must be an object.\n{$debug}");
            expect((string)($role['role_uuid'] ?? ''))->not->toBe('');
            expect((string)($role['role_code'] ?? ''))->not->toBe('');
        }
    }
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. Send **DELETE** to `/publicapi/v1/account/companies` (unsupported method for this controller).
 * 2. Expect **HTTP 405** (method not allowed) so routing stays limited to **GET** and **POST**.
 */
test('Account companies - unsupported HTTP method returns 405', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$status, , $raw] = ApiAuthHelper::apiRequest('DELETE', AccountCompaniesApiHelper::url(), $bearer);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(405, 'DELETE should not be allowed on companies collection. ' . $debug);
});
