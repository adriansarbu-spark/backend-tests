<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/BillingApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are disabled in test configuration.
     *
     * Steps:
     * 1. Mark billing catalog and snapshot flows skipped without HTTP calls.
     */
    test('Billing — catalog and snapshot integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is configured for TEST_USER_1.
 *
 * Steps:
 * 1. GET the admin catalog.
 * 2. Assert currency is present and every product audience is admin or both.
 */
test('Billing — admin catalog matches company currency and audience', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/catalog', $bearer);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);

    $currency = $json['data']['billing_currency'] ?? null;
    expect($currency)->toBeString()->not->toBe('')
        ->and($json['data']['products'] ?? null)->toBeArray();
    foreach ($json['data']['products'] as $product) {
        expect($product['audience'] ?? null)->toBeIn(['admin', 'both']);
        foreach ((array) ($product['prices'] ?? []) as $price) {
            expect($price['currency'] ?? null)->toBe($currency);
        }
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 can switch between personal and BILLING_TEST_COMPANY_ADMIN_UUID roles.
 *
 * Steps:
 * 1. Fetch both catalogs under explicit active roles.
 * 2. Assert audience-specific products do not bleed into the other catalog.
 */
test('Billing — catalog does not expose products for another audience', function () {
    $personalBearer = BillingApiHelper::bearerForUser1Personal();
    [$personalStatus, $personal] = BillingApiHelper::get('billing/catalog', $personalBearer);
    expect($personalStatus)->toBe(200);

    $adminBearer = BillingApiHelper::bearerForUser1Admin();
    [$adminStatus, $admin] = BillingApiHelper::get('billing/catalog', $adminBearer);
    expect($adminStatus)->toBe(200);

    foreach ((array) ($personal['data']['products'] ?? []) as $product) {
        expect($product['audience'] ?? null)->not->toBe('admin');
    }
    foreach ((array) ($admin['data']['products'] ?? []) as $product) {
        expect($product['audience'] ?? null)->not->toBe('personal');
    }
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is configured for TEST_USER_1.
 *
 * Steps:
 * 1. GET the company billing overview.
 * 2. Assert HTTP 200 with company identity and subscription/top-up sections.
 */
test('Billing — company admin can view local billing overview', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/company_overview', $bearer);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);

    expect($json['data']['company_id'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($json['data']['subscriptions'] ?? null)->toBeArray()
        ->and($json['data']['topups'] ?? null)->toBeArray();
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role with local billing context.
 *
 * Steps:
 * 1. GET the effective billing snapshot.
 * 2. Assert role identity, authentication_rights, and core snapshot sections are present.
 */
test('Billing — current role can view effective billing snapshot', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get('billing/me', $bearer);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    $data = $json['data'];

    expect($data['company_id'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($data['customer_role_id'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($data['entitlements'] ?? null)->toBeArray()
        ->and($data['limits'] ?? null)->toBeArray()
        ->and($data['spendable_across_roles'] ?? null)->toBeArray()
        ->and($data['subscriptions'] ?? null)->toBeArray()
        ->and(array_column($data['entitlements'], 'code'))->toContain('authentication_rights');
});

/**
 * Prerequisites:
 * - TEST_USER_1 can switch between personal and BILLING_TEST_COMPANY_ADMIN_UUID roles.
 *
 * Steps:
 * 1. GET `/billing/me` under both roles.
 * 2. Assert the effective role identity changes.
 */
test('Billing — switching active roles changes the effective snapshot', function () {
    $personalBearer = BillingApiHelper::bearerForUser1Personal();
    [$personalStatus, $personal] = BillingApiHelper::get('billing/me', $personalBearer);
    expect($personalStatus)->toBe(200);

    $adminBearer = BillingApiHelper::bearerForUser1Admin();
    [$adminStatus, $admin] = BillingApiHelper::get('billing/me', $adminBearer);
    expect($adminStatus)->toBe(200)
        ->and($admin['data']['customer_role_id'])->not->toBe($personal['data']['customer_role_id']);
});
