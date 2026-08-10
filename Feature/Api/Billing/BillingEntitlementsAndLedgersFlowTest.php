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
     * 1. Mark entitlement and ledger flows skipped without HTTP calls.
     */
    test('Billing — entitlement and ledger integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role with local billing grants.
 *
 * Steps:
 * 1. GET role entitlements.
 * 2. Assert company/role context, non-negative remaining, and no company pool by default.
 */
test('Billing — role entitlements agree with local grant and usage data', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get('billing/entitlements', $bearer);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    $data = $json['data'];
    expect($data['company_id'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($data['customer_role_id'] ?? null)->toBeInt()->toBeGreaterThan(0)
        ->and($data['limits'] ?? null)->toBeArray()
        ->and($data['entitlements'] ?? null)->toBeArray()
        ->and($data['spendable_across_roles'] ?? null)->toBeArray()
        ->and($data)->not->toHaveKey('company_entitlement_pool');
    foreach ($data['entitlements'] as $entry) {
        expect($entry['remaining'] ?? null)->toBeInt()->toBeGreaterThanOrEqual(0);
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role.
 *
 * Steps:
 * 1. Fetch all-time entitlements and the same endpoint for a known UTC period.
 * 2. Assert all-time usage is unchanged while used_in_period is added.
 */
test('Billing — usage period adds period usage without changing all-time usage', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$allStatus, $all] = BillingApiHelper::get('billing/entitlements', $bearer);
    expect($allStatus)->toBe(200);
    [$periodStatus, $period, $raw] = BillingApiHelper::get('billing/entitlements', $bearer, [
        'usage_period_start' => '2020-01-01T00:00:00Z',
        'usage_period_end' => '2099-01-01T00:00:00Z',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($periodStatus, $period, $raw);

    expect($period['data']['usage_period'] ?? null)->toBe([
        'start' => '2020-01-01 00:00:00',
        'end' => '2099-01-01 00:00:00',
    ]);
    $allByCode = array_column($all['data']['entitlements'], null, 'code');
    foreach ($period['data']['entitlements'] as $entry) {
        expect($entry['used'])->toBe($allByCode[$entry['code']]['used'])
            ->and($entry)->toHaveKey('used_in_period');
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role.
 *
 * Steps:
 * 1. Request entitlements with malformed complete date ranges.
 * 2. Assert HTTP 400 and a non-empty error.
 */
test('Billing — malformed entitlement dates are rejected', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    foreach ([
        ['usage_period_start' => 'bad', 'usage_period_end' => '2026-01-01T00:00:00Z'],
        ['usage_period_start' => '2026-01-01T00:00:00Z', 'usage_period_end' => 'bad'],
    ] as $query) {
        [$status, $json] = BillingApiHelper::get('billing/entitlements', $bearer, $query);
        BillingApiHelper::assertFailure($status, $json, 400);
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role.
 *
 * Steps:
 * 1. Request entitlements with only one period bound.
 * 2. Assert the characterized all-time HTTP 200 response without period fields.
 */
test('Billing — one-sided entitlement period follows current all-time behavior', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get('billing/entitlements', $bearer, [
        'usage_period_start' => '2026-01-01T00:00:00Z',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data'])->not->toHaveKey('usage_period');
    foreach ($json['data']['entitlements'] as $entry) {
        expect($entry)->not->toHaveKey('used_in_period');
    }
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_NON_ADMIN_UUID and BILLING_TEST_COMPANY_ADMIN_UUID are configured for TEST_USER_1.
 *
 * Steps:
 * 1. Request company entitlement pool as a non-admin and assert HTTP 403.
 * 2. Request it as admin and assert HTTP 200 with pool sections.
 */
test('Billing — only admin can include company entitlement pool', function () {
    $nonAdmin = BillingApiHelper::bearerForUser1NonAdminCompanyRole();
    [$deniedStatus, $denied] = BillingApiHelper::get('billing/entitlements', $nonAdmin, [
        'include_company_entitlement_pool' => '1',
    ]);
    BillingApiHelper::assertFailure($deniedStatus, $denied, 403);

    $admin = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/entitlements', $admin, [
        'include_company_entitlement_pool' => '1',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data']['company_entitlement_pool']['limits'] ?? null)->toBeArray()
        ->and($json['data']['company_entitlement_pool']['entitlements'] ?? null)->toBeArray();
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active role allowed to read its grant ledger.
 *
 * Steps:
 * 1. Request the grant ledger successfully.
 * 2. Request invalid sort/order/state and assert HTTP 422 with empty data.
 */
test('Billing — grant ledger is readable and rejects invalid filters', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get(
        'billing/ledger_role_grants',
        $bearer,
        BillingApiHelper::ledgerQuery(['page' => 1, 'per_page' => 5, 'state' => 'all']),
    );
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data'])->toBeArray();

    foreach ([['sort' => 'bad'], ['order' => 'bad'], ['state' => 'bad']] as $query) {
        [$invalidStatus, $invalid] = BillingApiHelper::get('billing/ledger_role_grants', $bearer, $query);
        BillingApiHelper::assertFailure($invalidStatus, $invalid, 422);
        expect($invalid['data'] ?? null)->toBe([]);
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active role allowed to read its consumption ledger.
 *
 * Steps:
 * 1. Request a complete UTC range successfully.
 * 2. Assert one-sided, malformed, and invalid-filter requests return HTTP 422 with empty data.
 */
test('Billing — consumption ledger validates ranges and rejects invalid filters', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get(
        'billing/ledger_role_consumption',
        $bearer,
        BillingApiHelper::ledgerQuery([
            'page' => 1,
            'per_page' => 5,
            'occurred_start' => '2020-01-01T00:00:00Z',
            'occurred_end' => '2099-01-01T00:00:00Z',
        ]),
    );
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data'])->toBeArray();

    foreach ([
        ['occurred_start' => '2026-01-01T00:00:00Z'],
        ['occurred_start' => 'bad', 'occurred_end' => '2026-01-01T00:00:00Z'],
        ['sort' => 'bad'],
        ['order' => 'bad'],
    ] as $query) {
        [$invalidStatus, $invalid] = BillingApiHelper::get('billing/ledger_role_consumption', $bearer, $query);
        BillingApiHelper::assertFailure($invalidStatus, $invalid, 422);
        expect($invalid['data'] ?? null)->toBe([]);
    }
});
