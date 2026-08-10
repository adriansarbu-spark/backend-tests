<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/BillingApiHelper.php';
require_once __DIR__ . '/../../../Support/BillingFixtureHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are disabled in test configuration.
     *
     * Steps:
     * 1. Mark top-up grant flows skipped without HTTP access.
     */
    test('Billing — top-up grant integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active; BILLING_TEST_PRICE_UUID identifies a dedicated test price.
 *
 * Steps:
 * 1. List top-up grant packages for that price.
 * 2. Assert HTTP 200 with items and summary.
 */
test('Billing — admin can list top-up packages for a price', function () {
    BillingFixtureHelper::assertConfigOrSkip(['BILLING_TEST_PRICE_UUID']);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/entitlement_grants', $bearer, [
        'price_uuid' => BillingFixtureHelper::value('BILLING_TEST_PRICE_UUID'),
        'status' => 'all',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data']['items'] ?? null)->toBeArray()
        ->and($json['data']['summary'] ?? null)->toBeArray();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_NON_ADMIN_UUID is configured for TEST_USER_1.
 *
 * Steps:
 * 1. Request grant packages and attempt assign with arbitrary identifiers.
 * 2. Assert HTTP 403 with a non-empty error and no package/assignment payload.
 */
test('Billing — non-admin cannot list or assign grant packages', function () {
    $bearer = BillingApiHelper::bearerForUser1NonAdminCompanyRole();
    [$listStatus, $list] = BillingApiHelper::get('billing/entitlement_grants', $bearer, [
        'price_uuid' => '00000000-0000-0000-0000-000000000000',
    ]);
    BillingApiHelper::assertFailure($listStatus, $list, 403);
    expect((array) ($list['data'] ?? []))->toBe([]);

    [$status, $json] = BillingApiHelper::post('billing/entitlement_grants/assign', $bearer, [
        'grant_uuid' => '00000000-0000-0000-0000-000000000000',
        'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
    ]);
    BillingApiHelper::assertFailure($status, $json, 403);
    expect($json['data']['assignment_id'] ?? null)->toBeNull();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active; no valid mutation fixture is needed.
 *
 * Steps:
 * 1. Send missing UUIDs, unknown UUIDs, and invalid list filters.
 * 2. Assert HTTP 400/404 with non-empty errors and no created assignment.
 */
test('Billing — invalid grant filters and payloads cause no writes', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    $requests = [
        ['GET', 'billing/entitlement_grants', null, [], 400],
        ['GET', 'billing/entitlement_grants', null, ['price_uuid' => 'x', 'status' => 'bad'], 400],
        ['POST', 'billing/entitlement_grants/assign', [], [], 400],
        [
            'POST',
            'billing/entitlement_grants/assign',
            [
                'grant_uuid' => '00000000-0000-0000-0000-000000000000',
                'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
            ],
            [],
            404,
        ],
    ];
    foreach ($requests as [$method, $path, $payload, $query, $expected]) {
        [$status, $json] = BillingApiHelper::request($method, $path, $bearer, $payload, $query);
        BillingApiHelper::assertFailure($status, $json, $expected);
        expect($json['data']['assignment_id'] ?? null)->toBeNull();
    }
});
