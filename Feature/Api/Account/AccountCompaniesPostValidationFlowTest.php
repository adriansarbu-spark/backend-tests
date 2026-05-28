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
 * - Integration tests are on; Keycloak and `TEST_USER_1_*` are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    AccountCompaniesApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** JSON missing **`name`** (empty string after trim semantics from server).
 * 2. Expect **HTTP 400** and **`error`** containing **`name_required`**.
 */
test('Account companies - POST without a name is rejected', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    $body['name'] = '   ';

    [$status, $json, $raw] = AccountCompaniesApiHelper::postJson($bearer, $body);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);
    expect($status)->toBe(400, $debug);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('name_required');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** with a three-letter **`country`** code.
 * 2. Expect **HTTP 400** and **`country_invalid`** (only ISO-2 is accepted).
 */
test('Account companies - POST rejects non ISO-2 country codes', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    $body['country'] = 'ROU';

    [$status, $json, $raw] = AccountCompaniesApiHelper::postJson($bearer, $body);
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);
    expect($status)->toBe(400, $debug);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('country_invalid');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** with blank **`country`** after trim.
 * 2. Expect **HTTP 400** and **`country_required`**.
 */
test('Account companies - POST rejects missing country', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    $body['country'] = '  ';

    [$status, $json] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($status)->toBe(400);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('country_required');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** with **`country`** **RO** and a **`tin`** that becomes empty after digit stripping (letters only).
 * 2. Expect **HTTP 400** and **`tin_required`**.
 */
test('Account companies - POST rejects empty TIN after RO normalization', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    $body['tin'] = 'abc';

    [$status, $json] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($status)->toBe(400);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('tin_required');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** with blank **`registration_number`**.
 * 2. Expect **HTTP 400** and **`registration_number_required`**.
 */
test('Account companies - POST rejects missing registration number', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    $body['registration_number'] = '';

    [$status, $json] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($status)->toBe(400);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('registration_number_required');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** without a **`fiscal_address`** object (omit key).
 * 2. Expect **HTTP 400** and **`fiscal_address_required`**.
 */
test('Account companies - POST rejects missing fiscal address', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    unset($body['fiscal_address']);

    [$status, $json] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($status)->toBe(400);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('fiscal_address_required');
});

/**
 * Prerequisites:
 * - Signed-in user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** without **`headquarters_address`**.
 * 2. Expect **HTTP 400** and **`headquarters_address_required`**.
 */
test('Account companies - POST rejects missing headquarters address', function () {
    $bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $body = AccountCompaniesApiHelper::validCreatePayload(gmdate('YmdHis'));
    unset($body['headquarters_address']);

    [$status, $json] = AccountCompaniesApiHelper::postJson($bearer, $body);
    expect($status)->toBe(400);
    expect(AccountCompaniesApiHelper::joinedErrors($json))->toContain('headquarters_address_required');
});
