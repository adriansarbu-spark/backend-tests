<?php

declare(strict_types=1);

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
}
