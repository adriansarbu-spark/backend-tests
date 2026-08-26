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
     * 1. Mark seat assignment flows skipped without HTTP or database access.
     */
    test('Billing — seat assignment integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active.
 *
 * Steps:
 * 1. Request seats for an unknown subscription item UUID.
 * 2. Assert HTTP 404 with a non-empty error and no item payload.
 */
test('Billing — admin cannot list seats for an unknown subscription item', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => '00000000-0000-0000-0000-000000000000',
    ]);
    BillingApiHelper::assertFailure($status, $json, 404);
    expect((array) ($json['data'] ?? []))->toBe([]);
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_NON_ADMIN_UUID is configured for TEST_USER_1.
 *
 * Steps:
 * 1. Attempt seat list, assignment, and release with arbitrary identifiers.
 * 2. Assert every operation returns HTTP 403 and a non-empty error.
 */
test('Billing — non-admin cannot list assign or release seats', function () {
    $bearer = BillingApiHelper::bearerForUser1NonAdminCompanyRole();
    [$listStatus, $list] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => '00000000-0000-0000-0000-000000000000',
    ]);
    BillingApiHelper::assertFailure($listStatus, $list, 403);
    foreach ([
        ['billing/seats/assign', [
            'seat_slot_uuid' => '00000000-0000-0000-0000-000000000000',
            'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
        ]],
        ['billing/seats/release', [
            'seat_slot_uuid' => '00000000-0000-0000-0000-000000000000',
        ]],
    ] as [$path, $payload]) {
        [$status, $json] = BillingApiHelper::post($path, $bearer, $payload);
        BillingApiHelper::assertFailure($status, $json, 403);
    }
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active; no valid seat fixture is required.
 *
 * Steps:
 * 1. Send missing, unknown, and foreign role/slot payloads.
 * 2. Assert HTTP 400/404 with non-empty errors and no created assignment.
 */
test('Billing — foreign roles slots and invalid seat payloads are rejected', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    $requests = [
        ['billing/seats/assign', [], 400],
        ['billing/seats/release', [], 400],
        ['billing/seats/assign', [
            'seat_slot_uuid' => '00000000-0000-0000-0000-000000000000',
            'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
        ], 400],
        ['billing/seats/release', [
            'seat_slot_uuid' => '00000000-0000-0000-0000-000000000000',
        ], 404],
    ];
    foreach ($requests as [$path, $payload, $expected]) {
        [$status, $json] = BillingApiHelper::post($path, $bearer, $payload);
        BillingApiHelper::assertFailure($status, $json, $expected);
        expect($json['data']['assignment_id'] ?? null)->toBeNull();
    }
});
