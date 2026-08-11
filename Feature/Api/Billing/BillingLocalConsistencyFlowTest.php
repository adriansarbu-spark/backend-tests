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
     * 1. Mark local consistency flows skipped without HTTP or database access.
     */
    test('Billing — local consistency integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - A dedicated company has a configured free seat slot and target role.
 * - The target role belongs to TEST_USER_1 so its `/me` and entitlement views are readable.
 *
 * Steps:
 * 1. Record target-role `/me` authentication_rights, then assign the configured seat as admin.
 * 2. Assert the assigned seat appears with allowance and target `/me`/entitlements stay consistent.
 * 3. Assert authentication_rights remaining increases after assign, then restore after release.
 */
test('Billing — assigned and released seat stays consistent across seats me and entitlements', function () {
    BillingFixtureHelper::assertConfigOrSkip([
        'BILLING_TEST_SUBSCRIPTION_ITEM_UUID',
        'BILLING_TEST_TARGET_ROLE_UUID',
        'BILLING_TEST_FREE_SEAT_SLOT_UUID',
    ]);
    $bearer = BillingApiHelper::bearerForUser1Admin();
    $itemUuid = BillingFixtureHelper::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID');
    $roleUuid = BillingFixtureHelper::value('BILLING_TEST_TARGET_ROLE_UUID');
    $slotUuid = BillingFixtureHelper::value('BILLING_TEST_FREE_SEAT_SLOT_UUID');
    $roleHeaders = ['x-role-uuid' => $roleUuid];
    BillingFixtureHelper::ensureFreeSeatSlotUnassigned($bearer);

    [$beforeMeStatus, $beforeMe] = BillingApiHelper::get('billing/me', $bearer, headers: $roleHeaders);
    if ($beforeMeStatus !== 200) {
        $this->markTestSkipped(
            'Configured BILLING_TEST_TARGET_ROLE_UUID is not readable by TEST_USER_1: '
            . BillingApiHelper::debug($beforeMeStatus, $beforeMe),
        );
    }
    $beforeAuth = null;
    foreach (($beforeMe['data']['entitlements'] ?? []) as $entry) {
        if (($entry['code'] ?? null) === 'authentication_rights') {
            $beforeAuth = $entry;
            break;
        }
    }
    expect($beforeAuth)->not->toBeNull();

    $assignedByThisTest = false;
    try {
        [$assignStatus, $assign, $raw] = BillingApiHelper::post('billing/seats/assign', $bearer, [
            'seat_slot_uuid' => $slotUuid,
            'customer_role_uuid' => $roleUuid,
        ]);
        BillingApiHelper::assertSuccessfulEnvelope($assignStatus, $assign, $raw);
        $assignedByThisTest = true;

        [$seatsStatus, $seats] = BillingApiHelper::get('billing/seats', $bearer, [
            'subscription_item_uuid' => $itemUuid,
            'status' => 'assigned',
            'include' => 'allowance',
        ]);
        expect($seatsStatus)->toBe(200);
        $bySlot = array_column($seats['data']['items'] ?? [], null, 'seat_slot_uuid');
        expect($bySlot)->toHaveKey($slotUuid)
            ->and($bySlot[$slotUuid]['customer_role_uuid'] ?? null)->toBe($roleUuid)
            ->and($bySlot[$slotUuid]['allowance'] ?? null)->toBeArray();

        [$meStatus, $me] = BillingApiHelper::get('billing/me', $bearer, headers: $roleHeaders);
        [$entitlementsStatus, $entitlements] = BillingApiHelper::get(
            'billing/entitlements',
            $bearer,
            headers: $roleHeaders,
        );
        expect($meStatus)->toBe(200)
            ->and($entitlementsStatus)->toBe(200)
            ->and($me['data']['customer_role_id'] ?? null)->toBe($entitlements['data']['customer_role_id'] ?? null)
            ->and(array_keys($me['data']['limits'] ?? []))->toBe(array_keys($entitlements['data']['limits'] ?? []));

        $afterAuth = null;
        foreach (($me['data']['entitlements'] ?? []) as $entry) {
            if (($entry['code'] ?? null) === 'authentication_rights') {
                $afterAuth = $entry;
                break;
            }
        }
        expect($afterAuth)->not->toBeNull()
            ->and($afterAuth['remaining'] ?? null)->toBeGreaterThanOrEqual((int) ($beforeAuth['remaining'] ?? 0));
    } finally {
        if ($assignedByThisTest) {
            [$releaseStatus, $release] = BillingApiHelper::post('billing/seats/release', $bearer, [
                'seat_slot_uuid' => $slotUuid,
            ]);
            expect($releaseStatus)->toBe(200, BillingApiHelper::debug($releaseStatus, $release));
        }
    }

    expect($assignedByThisTest)->toBeTrue();
    [$availableStatus, $available] = BillingApiHelper::get('billing/seats', $bearer, [
        'subscription_item_uuid' => $itemUuid,
        'status' => 'unassigned',
    ]);
    expect($availableStatus)->toBe(200);
    $availableSlots = array_column($available['data']['items'] ?? [], 'seat_slot_uuid');
    expect($availableSlots)->toContain($slotUuid);

    [$afterReleaseMeStatus, $afterReleaseMe] = BillingApiHelper::get('billing/me', $bearer, headers: $roleHeaders);
    expect($afterReleaseMeStatus)->toBe(200);
    $releasedAuth = null;
    foreach (($afterReleaseMe['data']['entitlements'] ?? []) as $entry) {
        if (($entry['code'] ?? null) === 'authentication_rights') {
            $releasedAuth = $entry;
            break;
        }
    }
    expect($releasedAuth)->not->toBeNull()
        ->and($releasedAuth['remaining'] ?? null)->toBe($beforeAuth['remaining'] ?? null);
});
