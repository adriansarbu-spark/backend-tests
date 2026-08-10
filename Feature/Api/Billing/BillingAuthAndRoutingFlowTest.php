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
     * 1. Mark billing authentication and routing flows skipped without making HTTP calls.
     */
    test('Billing — authentication and routing integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Billing API is reachable; no Authorization header is sent.
 *
 * Steps:
 * 1. GET every included local billing read route anonymously.
 * 2. POST grant/seat assign and seat release anonymously.
 * 3. Assert every call fails with a non-empty error and no billing data envelope.
 */
test('Billing — anonymous callers cannot read or mutate local billing data', function () {
    foreach (BillingApiHelper::includedGetPaths() as $path) {
        [$status, $json] = BillingApiHelper::get($path);
        BillingApiHelper::assertFailure($status, $json, [400, 401, 403, 404]);
        expect((array) ($json['data'] ?? []))->toBe([], "Anonymous {$path} exposed billing data.");
    }
    foreach ([
        ['billing/entitlement_grants/assign', ['grant_uuid' => 'unknown', 'customer_role_uuid' => 'unknown']],
        ['billing/seats/assign', ['seat_slot_uuid' => 'unknown', 'customer_role_uuid' => 'unknown']],
        ['billing/seats/release', ['seat_slot_uuid' => 'unknown']],
    ] as [$path, $payload]) {
        [$status, $json] = BillingApiHelper::post($path, null, $payload);
        BillingApiHelper::assertFailure($status, $json, [400, 401, 403, 404]);
        expect($json['data']['assignment_id'] ?? null)->toBeNull();
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role (not company admin).
 *
 * Steps:
 * 1. GET admin-only local routes and POST grant/seat assign under the personal role.
 * 2. Assert HTTP 403 with a non-empty error for each call.
 * 3. Use identifiers that cannot mutate local state.
 */
test('Billing — personal role cannot use company-admin billing routes', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    foreach ([
        ['GET', 'billing/company_overview', null, []],
        ['GET', 'billing/entitlement_grants', null, ['price_uuid' => '00000000-0000-0000-0000-000000000000']],
        ['GET', 'billing/seats', null, ['subscription_item_uuid' => '00000000-0000-0000-0000-000000000000']],
        ['POST', 'billing/entitlement_grants/assign', [
            'grant_uuid' => '00000000-0000-0000-0000-000000000000',
            'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
        ], []],
        ['POST', 'billing/seats/assign', [
            'seat_slot_uuid' => '00000000-0000-0000-0000-000000000000',
            'customer_role_uuid' => '00000000-0000-0000-0000-000000000000',
        ], []],
    ] as [$method, $path, $payload, $query]) {
        [$status, $json] = BillingApiHelper::request($method, $path, $bearer, $payload, $query);
        BillingApiHelper::assertFailure($status, $json, 403);
        expect($json['data']['assignment_id'] ?? null)->toBeNull();
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 can sign in and has a configured personal role.
 *
 * Steps:
 * 1. Switch explicitly to the personal role and GET the catalog.
 * 2. Assert HTTP 200 and every product audience is personal or both.
 */
test('Billing — personal active role sees only the personal catalog', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json, $raw] = BillingApiHelper::get('billing/catalog', $bearer);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);

    expect($json['data']['products'] ?? null)->toBeArray();
    foreach ($json['data']['products'] as $product) {
        expect($product['audience'] ?? null)->toBeIn(['personal', 'both']);
    }
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_NON_ADMIN_UUID is configured for TEST_USER_1.
 *
 * Steps:
 * 1. Switch to that role and request overview, grants, seats, and catalog.
 * 2. Assert HTTP 403 for every unauthorized route with a non-empty error.
 */
test('Billing — unsupported company role cannot use admin or catalog billing routes', function () {
    $bearer = BillingApiHelper::bearerForUser1NonAdminCompanyRole();
    foreach ([
        ['billing/catalog', []],
        ['billing/company_overview', []],
        ['billing/entitlement_grants', ['price_uuid' => 'unknown']],
        ['billing/seats', ['subscription_item_uuid' => 'unknown']],
    ] as [$path, $query]) {
        [$status, $json] = BillingApiHelper::get($path, $bearer, $query);
        BillingApiHelper::assertFailure($status, $json, 403);
        expect((array) ($json['data'] ?? []))->toBe([]);
    }
});

/**
 * Prerequisites:
 * - TEST_USER_2 can sign in with a personal role that is not a member of BILLING_TEST_COMPANY_UUID.
 * - BILLING_TEST_COMPANY_ADMIN_UUID / TARGET_ROLE_UUID belong to TEST_USER_1.
 *
 * Steps:
 * 1. As USER_2, request USER_1 admin seats/grants identifiers and USER_1 role on /me.
 * 2. Assert foreign admin resources are not readable (403/404) and no assignment is created.
 * 3. Assert USER_2 cannot adopt USER_1's role UUID via x-role-uuid.
 */
test('Billing — second user cannot read first user company billing resources', function () {
    require_once __DIR__ . '/../../../Support/BillingFixtureHelper.php';
    BillingFixtureHelper::assertConfigOrSkip([
        'BILLING_TEST_SUBSCRIPTION_ITEM_UUID',
        'BILLING_TEST_PRICE_UUID',
        'BILLING_TEST_TARGET_ROLE_UUID',
    ]);

    $foreign = BillingApiHelper::bearerForUser2Personal();
    $itemUuid = BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID');
    $priceUuid = BillingFixtureHelper::value('BILLING_TEST_PRICE_UUID');
    $roleUuid = BillingFixtureHelper::value('BILLING_TEST_TARGET_ROLE_UUID');

    [$seatsStatus, $seats] = BillingApiHelper::get('billing/seats', $foreign, [
        'subscription_item_uuid' => $itemUuid,
    ]);
    BillingApiHelper::assertFailure($seatsStatus, $seats, [403, 404]);
    expect((array) ($seats['data'] ?? []))->toBe([]);

    [$grantsStatus, $grants] = BillingApiHelper::get('billing/entitlement_grants', $foreign, [
        'price_uuid' => $priceUuid,
    ]);
    BillingApiHelper::assertFailure($grantsStatus, $grants, [403, 404]);
    expect((array) ($grants['data'] ?? []))->toBe([]);

    [$meStatus, $me] = BillingApiHelper::get('billing/me', $foreign, headers: [
        'x-role-uuid' => $roleUuid,
    ]);
    expect($meStatus)->toBe(200);
    // Role UUID resolution is customer-scoped; foreign UUID must not switch USER_2 onto USER_1's role.
    $user1Admin = BillingApiHelper::bearerForUser1Admin();
    [$user1MeStatus, $user1Me] = BillingApiHelper::get('billing/me', $user1Admin, headers: [
        'x-role-uuid' => $roleUuid,
    ]);
    expect($user1MeStatus)->toBe(200)
        ->and($me['data']['customer_role_id'] ?? null)->not->toBe($user1Me['data']['customer_role_id'] ?? null)
        ->and($me['data']['company_id'] ?? null)->not->toBe($user1Me['data']['company_id'] ?? null);
});
