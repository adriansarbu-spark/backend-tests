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
     * 1. Mark billing subscription-item HTTP-guard flows skipped without making HTTP calls.
     */
    test('Billing — subscription-item HTTP-guard integration tests are disabled', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    BillingApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Local subscription-item routes whose auth, admin, and method guards run before Stripe.
 *
 * @return list<string>
 */
function billingSubscriptionItemGuardPaths(): array
{
    return [
        'billing/subscription_items',
        'billing/subscription_items/preview',
        'billing/subscription_items/cancel',
    ];
}

/**
 * Prerequisites:
 * - Billing API is reachable; no Authorization header is sent.
 *
 * Steps:
 * 1. POST every local subscription-item route anonymously with an empty body.
 * 2. Assert each call is refused (**HTTP 401** at the controller, or **HTTP 404**
 *    `user_not_found` at the public API gateway) with a non-empty error.
 * 3. Assert no Stripe portal URL and no successful mutation envelope.
 */
test('Billing — anonymous callers cannot change subscription items', function () {
    foreach (billingSubscriptionItemGuardPaths() as $path) {
        [$status, $json] = BillingApiHelper::post($path, null, []);
        BillingApiHelper::assertLocalGuardFailure($status, $json, [401, 404]);
        expect($json['data']['ok'] ?? null)->not->toBeTrue("Anonymous {$path} reported a successful change.");
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 has an active personal role (Pest Feature baseline).
 * - Subscription-item admin check (`isRoleAdminForCompany`) runs before Stripe.
 *
 * Steps:
 * 1. POST every local subscription-item route under the personal role.
 * 2. Assert each call is refused (**HTTP 403**) with a non-empty error.
 * 3. Assert no Stripe portal URL and no successful mutation envelope.
 */
test('Billing — a personal role cannot change subscription items', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    foreach (billingSubscriptionItemGuardPaths() as $path) {
        [$status, $json] = BillingApiHelper::post($path, $bearer, []);
        BillingApiHelper::assertLocalGuardFailure($status, $json, 403);
        expect($json['data']['ok'] ?? null)->not->toBeTrue("Personal role {$path} reported a successful change.");
    }
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is configured for TEST_USER_1.
 * - The method check runs after the admin gate, so a company admin is required
 *   to observe **HTTP 405** (a personal role is refused as **HTTP 403** first).
 *
 * Steps:
 * 1. Switch to the company-admin role.
 * 2. GET every local subscription-item route (POST is required).
 * 3. Assert each call is refused (**HTTP 405**) with a non-empty error and no
 *    Stripe portal URL.
 */
test('Billing — subscription item routes reject the wrong HTTP method', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    foreach (billingSubscriptionItemGuardPaths() as $path) {
        [$status, $json] = BillingApiHelper::get($path, $bearer);
        BillingApiHelper::assertLocalGuardFailure($status, $json, 405);
        expect($json['data']['ok'] ?? null)->not->toBeTrue("GET {$path} reported a successful change.");
    }
});
