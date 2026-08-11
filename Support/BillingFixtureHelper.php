<?php

declare(strict_types=1);

require_once __DIR__ . '/BillingApiHelper.php';

/**
 * Guardrails and identifiers for billing integration fixtures.
 *
 * Mutation tests deliberately require explicit, dedicated fixture constants;
 * they never infer permission to modify whichever company happens to be active.
 *
 * Feature billing tests arrange state only through public API endpoints (and
 * pre-seeded config UUIDs). They must not insert, update, or delete via direct DB access.
 */
final class BillingFixtureHelper
{
    public static function assertDedicatedMutationConfigOrSkip(): void
    {
        self::assertConfigOrSkip([
            'BILLING_TEST_COMPANY_ADMIN_UUID',
            'BILLING_TEST_COMPANY_UUID',
            'BILLING_TEST_PRICE_UUID',
            'BILLING_TEST_SUBSCRIPTION_ITEM_UUID',
            'BILLING_TEST_TARGET_ROLE_UUID',
            'BILLING_TEST_FREE_SEAT_SLOT_UUID',
        ]);
    }

    /** @param list<string> $required */
    public static function assertConfigOrSkip(array $required): void
    {
        $missing = [];
        foreach ($required as $key) {
            if (!isTestConfigDefined($key) || trim(resolvedTestConfigValue($key)) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            test()->markTestSkipped(
                'Billing fixtures are not configured for a dedicated company: ' . implode(', ', $missing),
            );
        }
    }

    public static function value(string $key): string
    {
        $value = isTestConfigDefined($key) ? trim(resolvedTestConfigValue($key)) : '';
        if ($value === '') {
            test()->markTestSkipped('Missing billing fixture config: ' . $key);
        }

        return $value;
    }

    /**
     * Ensure BILLING_TEST_FREE_SEAT_SLOT_UUID is unassigned before mutation flows.
     * Releases via public API when a prior run left the slot assigned.
     *
     * @return array<string, mixed> seat-list JSON after ensure (status=all)
     */
    public static function ensureFreeSeatSlotUnassigned(string $adminBearer): array
    {
        $slotUuid = self::value('BILLING_TEST_FREE_SEAT_SLOT_UUID');
        $itemUuid = self::value('BILLING_TEST_SUBSCRIPTION_ITEM_UUID');

        [$status, $json, $raw] = BillingApiHelper::get('billing/seats', $adminBearer, [
            'subscription_item_uuid' => $itemUuid,
            'status' => 'all',
        ]);
        expect($status)->toBe(200, BillingApiHelper::debug($status, $json, $raw));
        $bySlot = array_column($json['data']['items'] ?? [], null, 'seat_slot_uuid');
        if (!isset($bySlot[$slotUuid])) {
            test()->markTestSkipped('Configured BILLING_TEST_FREE_SEAT_SLOT_UUID was not found on the subscription item.');
        }
        if (($bySlot[$slotUuid]['slot_status'] ?? null) === 'unassigned') {
            return $json;
        }

        [$releaseStatus, $release, $releaseRaw] = BillingApiHelper::post('billing/seats/release', $adminBearer, [
            'seat_slot_uuid' => $slotUuid,
        ]);
        expect($releaseStatus)->toBe(200, BillingApiHelper::debug($releaseStatus, $release, $releaseRaw))
            ->and($release['data']['ok'] ?? null)->toBeTrue();

        [$afterStatus, $after, $afterRaw] = BillingApiHelper::get('billing/seats', $adminBearer, [
            'subscription_item_uuid' => $itemUuid,
            'status' => 'all',
        ]);
        expect($afterStatus)->toBe(200, BillingApiHelper::debug($afterStatus, $after, $afterRaw));
        $afterBySlot = array_column($after['data']['items'] ?? [], null, 'seat_slot_uuid');
        if (($afterBySlot[$slotUuid]['slot_status'] ?? null) !== 'unassigned') {
            test()->markTestSkipped(
                'Configured BILLING_TEST_FREE_SEAT_SLOT_UUID could not be released to unassigned before the test.'
            );
        }

        return $after;
    }
}
