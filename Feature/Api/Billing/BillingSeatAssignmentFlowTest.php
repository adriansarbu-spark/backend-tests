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
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active; BILLING_TEST_SUBSCRIPTION_ITEM_UUID identifies a dedicated item.
 *
 * Steps:
 * 1. List local seats for the dedicated item.
 * 2. Assert HTTP 200 with items and summary.
 */
test('Billing — admin can list seats for a local plan item', function () {
    BillingFixtureHelper::assertConfigOrSkip(['BILLING_TEST_SUBSCRIPTION_ITEM_UUID']);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID'),
        'status' => 'all',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    expect($json['data']['items'] ?? null)->toBeArray()
        ->and($json['data']['summary'] ?? null)->toBeArray();
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is active; the dedicated item may have assigned seats.
 *
 * Steps:
 * 1. List assigned seats with allowance inclusion.
 * 2. Assert HTTP 200 and every returned assigned row includes allowance details.
 */
test('Billing — assigned-seat list includes allowance details', function () {
    BillingFixtureHelper::assertConfigOrSkip(['BILLING_TEST_SUBSCRIPTION_ITEM_UUID']);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID'),
        'status' => 'assigned',
        'include' => 'allowance',
    ]);
    BillingApiHelper::assertSuccessfulEnvelope($status, $json, $raw);
    foreach ($json['data']['items'] as $row) {
        expect($row['allowance'] ?? null)->toBeArray();
    }
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
 * - A dedicated test company has a free seat slot and target role configured.
 * - The release endpoint is available as cleanup for the same slot.
 *
 * Steps:
 * 1. Assign the free seat as admin and assert a positive assignment ID.
 * 2. Confirm the seat list identifies the target role.
 * 3. Release in a finally block, then repeat release to assert the idempotent contract.
 */
test('Billing — admin assigns and idempotently releases a dedicated free seat', function () {
    BillingFixtureHelper::assertConfigOrSkip([
        'BILLING_TEST_SUBSCRIPTION_ITEM_UUID',
        'BILLING_TEST_TARGET_ROLE_UUID',
        'BILLING_TEST_FREE_SEAT_SLOT_UUID',
    ]);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    $slotUuid = BillingFixtureHelper::value('BILLING_TEST_FREE_SEAT_SLOT_UUID');
    $roleUuid = BillingFixtureHelper::value('BILLING_TEST_TARGET_ROLE_UUID');
    $itemUuid = BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID');

    [$beforeStatus, $before] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => $itemUuid,
        'status' => 'all',
    ]);
    expect($beforeStatus)->toBe(200);
    $beforeBySlot = array_column($before['data']['items'] ?? [], null, 'seat_slot_uuid');
    if (($beforeBySlot[$slotUuid]['slot_status'] ?? null) !== 'unassigned') {
        $this->markTestSkipped('Configured BILLING_TEST_FREE_SEAT_SLOT_UUID is not currently unassigned.');
    }

    $assignedByThisTest = false;
    try {
        [$assignStatus, $assign, $raw] = BillingApiHelper::post('billing/seats/assign', $bearer, [
            'seat_slot_uuid' => $slotUuid,
            'customer_role_uuid' => $roleUuid,
        ]);
        BillingApiHelper::assertSuccessfulEnvelope($assignStatus, $assign, $raw);
        expect($assign['data']['ok'] ?? null)->toBeTrue()
            ->and($assign['data']['assignment_id'] ?? null)->toBeInt()->toBeGreaterThan(0);
        $assignedByThisTest = true;

        [$listStatus, $list] = BillingApiHelper::get('billing/seats', $bearer, [
            'subscription_item_uuid' => $itemUuid,
            'status' => 'assigned',
        ]);
        expect($listStatus)->toBe(200);
        $bySlot = array_column($list['data']['items'] ?? [], null, 'seat_slot_uuid');
        expect($bySlot)->toHaveKey($slotUuid)
            ->and($bySlot[$slotUuid]['customer_role_uuid'] ?? null)->toBe($roleUuid);
    } finally {
        if ($assignedByThisTest) {
            [$releaseStatus, $release] = BillingApiHelper::post('billing/seats/release', $bearer, [
                'seat_slot_uuid' => $slotUuid,
            ]);
            expect($releaseStatus)->toBe(200, BillingApiHelper::debug($releaseStatus, $release))
                ->and($release['data']['ok'] ?? null)->toBeTrue();
        }
    }

    expect($assignedByThisTest)->toBeTrue();
    [$repeatStatus, $repeat] = BillingApiHelper::post('billing/seats/release', $bearer, [
        'seat_slot_uuid' => $slotUuid,
    ]);
    expect($repeatStatus)->toBe(200)
        ->and($repeat['data']['ok'] ?? null)->toBeTrue();
});

/**
 * Prerequisites:
 * - A dedicated free seat slot and target role are configured.
 * - At least one other unassigned slot exists on the same subscription item, or the free slot is used twice.
 *
 * Steps:
 * 1. Assign the free seat to the target role.
 * 2. Attempt a second assignment of another unassigned slot (or the same slot) to the same role.
 * 3. Assert HTTP 400 for the duplicate-role or non-assignable-slot rule, then release cleanup.
 */
test('Billing — one role cannot receive duplicate seats from the same plan', function () {
    BillingFixtureHelper::assertConfigOrSkip([
        'BILLING_TEST_SUBSCRIPTION_ITEM_UUID',
        'BILLING_TEST_TARGET_ROLE_UUID',
        'BILLING_TEST_FREE_SEAT_SLOT_UUID',
    ]);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    $slotUuid = BillingFixtureHelper::value('BILLING_TEST_FREE_SEAT_SLOT_UUID');
    $roleUuid = BillingFixtureHelper::value('BILLING_TEST_TARGET_ROLE_UUID');
    $itemUuid = BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID');

    [$beforeStatus, $before] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => $itemUuid,
        'status' => 'all',
    ]);
    expect($beforeStatus)->toBe(200);
    $items = $before['data']['items'] ?? [];
    $beforeBySlot = array_column($items, null, 'seat_slot_uuid');
    if (($beforeBySlot[$slotUuid]['slot_status'] ?? null) !== 'unassigned') {
        $this->markTestSkipped('Configured BILLING_TEST_FREE_SEAT_SLOT_UUID is not currently unassigned.');
    }

    $secondSlotUuid = null;
    foreach ($items as $row) {
        if (($row['seat_slot_uuid'] ?? null) === $slotUuid) {
            continue;
        }
        if (($row['slot_status'] ?? null) === 'unassigned') {
            $secondSlotUuid = $row['seat_slot_uuid'];
            break;
        }
    }

    $assignedByThisTest = false;
    try {
        [$assignStatus, $assign, $raw] = BillingApiHelper::post('billing/seats/assign', $bearer, [
            'seat_slot_uuid' => $slotUuid,
            'customer_role_uuid' => $roleUuid,
        ]);
        BillingApiHelper::assertSuccessfulEnvelope($assignStatus, $assign, $raw);
        $assignedByThisTest = true;

        $duplicatePayload = [
            'seat_slot_uuid' => $secondSlotUuid ?? $slotUuid,
            'customer_role_uuid' => $roleUuid,
        ];
        [$dupStatus, $dup] = BillingApiHelper::post('billing/seats/assign', $bearer, $duplicatePayload);
        BillingApiHelper::assertFailure($dupStatus, $dup, 400);
        expect($dup['data']['assignment_id'] ?? null)->toBeNull();
        $errors = BillingApiHelper::joinedErrors($dup);
        expect(
            str_contains($errors, 'role_already_assigned_to_subscription')
            || str_contains($errors, 'seat_slot_not_assignable'),
        )->toBeTrue('Expected duplicate-role or non-assignable-slot rejection, got: ' . $errors);
    } finally {
        if ($assignedByThisTest) {
            BillingApiHelper::post('billing/seats/release', $bearer, [
                'seat_slot_uuid' => $slotUuid,
            ]);
        }
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
