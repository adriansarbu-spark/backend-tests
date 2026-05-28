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
 * - Integration tests are on; Keycloak and two test users are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    AccountCompaniesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in owner (`TEST_USER_1_*`); file guard passed.
 *
 * Steps:
 * 1. **POST** a new non-personal company with Romanian (**RO**) structured addresses and a TIN typed with spaces and letters that normalize to digits.
 * 2. Expect **HTTP 200** and **`data.company_uuid`** plus **`data.role_uuid`** as non-empty strings.
 * 3. **GET** the same user’s company list and find that **`company_uuid`**: expect **`tin`** on the wire to be digits-only (normalization) and at least one **`roles`** entry with **`role_code`** **`admin`**.
 */
test('Account companies - owner can create a company and see it in their list with normalized TIN', function () {
    $token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload($token);

    [$postStatus, $postJson, $postRaw] = AccountCompaniesApiHelper::postJson($bearer, $body);
    $postDebug = 'status=' . $postStatus . ' raw=' . substr($postRaw, 0, 1200);
    expect($postStatus)->toBe(200, 'POST create company failed. ' . $postDebug);
    expect(is_array($postJson))->toBeTrue('POST response should be JSON. ' . $postDebug);

    $companyUuid = (string)($postJson['data']['company_uuid'] ?? '');
    $roleUuid = (string)($postJson['data']['role_uuid'] ?? '');
    expect($companyUuid)->not->toBe('', 'Expected data.company_uuid. ' . $postDebug);
    expect($roleUuid)->not->toBe('', 'Expected data.role_uuid. ' . $postDebug);

    [$getStatus, $getJson, $getRaw] = AccountCompaniesApiHelper::get($bearer);
    $getDebug = 'status=' . $getStatus . ' raw=' . substr($getRaw, 0, 1200);
    expect($getStatus)->toBe(200, 'GET companies after create failed. ' . $getDebug);
    expect(is_array($getJson))->toBeTrue();

    $companies = (array)($getJson['data']['companies'] ?? []);
    $found = AccountCompaniesApiHelper::findCompanyByUuid($companies, $companyUuid);
    expect($found)->not->toBeNull('New company_uuid should appear in GET list. ' . $getDebug);

    $tin = (string)($found['tin'] ?? '');
    expect($tin)->toMatch('/^\d+$/', 'RO tin should be digits-only in API payload. tin=' . $tin);

    $adminRole = null;
    foreach ((array)($found['roles'] ?? []) as $role) {
        if (is_array($role) && ($role['role_code'] ?? '') === 'admin') {
            $adminRole = $role;
            break;
        }
    }
    expect($adminRole)->not->toBeNull('Expected an admin role on the new company.');
    expect((string)($adminRole['role_uuid'] ?? ''))->toBe($roleUuid);
});

/**
 * Prerequisites:
 * - Two distinct accounts (`TEST_USER_1_*`, `TEST_USER_2_*`).
 *
 * Steps:
 * 1. User A creates a company with a unique name and identifiers (**HTTP 200**).
 * 2. User B lists their own companies (**HTTP 200**).
 * 3. Assert User B’s list does **not** contain User A’s new **`company_uuid`** (each customer only sees their own memberships).
 */
test('Account companies - another user’s list does not include your new company', function () {
    $token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $bearer1 = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$postStatus, $postJson, $postRaw] = AccountCompaniesApiHelper::postJson(
        $bearer1,
        AccountCompaniesApiHelper::validCreatePayload($token)
    );
    expect($postStatus)->toBe(200, 'User1 create failed: ' . substr($postRaw, 0, 800));
    $companyUuid = (string)($postJson['data']['company_uuid'] ?? '');
    expect($companyUuid)->not->toBe('');

    [$get2Status, $get2Json, $get2Raw] = AccountCompaniesApiHelper::get($bearer2);
    expect($get2Status)->toBe(200, 'User2 GET failed: ' . substr($get2Raw, 0, 800));
    $list2 = (array)($get2Json['data']['companies'] ?? []);
    expect(AccountCompaniesApiHelper::findCompanyByUuid($list2, $companyUuid))->toBeNull(
        'User2 should not see user1-only company_uuid in their companies list.'
    );
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** create with optional **`vat_number`** set beside TIN.
 * 2. Expect **HTTP 200**; then **GET** and open that company: **`vat_number`** in JSON should echo the trimmed value when present.
 */
test('Account companies - create accepts optional VAT number and surfaces it on GET', function () {
    $token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload($token);
    $body['vat_number'] = 'RO' . substr(preg_replace('/\D/', '', $token), 0, 8);

    [$postStatus, $postJson, $postRaw] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($postStatus)->toBe(200, 'POST with vat_number failed: ' . substr($postRaw, 0, 800));
    $companyUuid = (string)($postJson['data']['company_uuid'] ?? '');
    expect($companyUuid)->not->toBe('');

    [, $getJson] = AccountCompaniesApiHelper::get($bearer);
    $companies = (array)($getJson['data']['companies'] ?? []);
    $found = AccountCompaniesApiHelper::findCompanyByUuid($companies, $companyUuid);
    expect($found)->not->toBeNull();
    expect((string)($found['vat_number'] ?? ''))->toBe((string)$body['vat_number']);
});
