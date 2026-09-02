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
     * 1. Mark billing portal HTTP-guard flows skipped without making HTTP calls.
     */
    test('Billing — portal HTTP-guard integration tests are disabled', function () {
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
 * 1. POST the billing portal route anonymously with an invalid return URL (so a
 *    later local guard cannot create a Stripe session either).
 * 2. Assert access is refused (**HTTP 401** at the controller, or **HTTP 404**
 *    `user_not_found` at the public API gateway) with a non-empty error.
 * 3. Assert the response does not include a Stripe portal URL.
 */
test('Billing — anonymous callers cannot open the billing portal', function () {
    [$status, $json] = BillingApiHelper::post('billing/portal', null, [
        'return_url' => 'not-a-url',
    ]);
    BillingApiHelper::assertLocalGuardFailure($status, $json, [401, 404]);
});


/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_NON_ADMIN_UUID is configured for TEST_USER_1 (employee
 *   or representative on the dedicated billing test company).
 * - Stripe SDK/config may be required before the admin gate; **HTTP 503** skips.
 *
 * Steps:
 * 1. Switch to that non-admin company role and POST the portal with an invalid
 *    return URL.
 * 2. Skip if Stripe bootstrap blocks the request.
 * 3. Assert access is refused (**HTTP 403**) with a non-empty error and no portal URL.
 */
test('Billing — a company employee cannot open the billing portal', function () {
    $bearer = BillingApiHelper::bearerForUser1NonAdminCompanyRole();
    [$status, $json, $raw] = BillingApiHelper::post('billing/portal', $bearer, [
        'return_url' => 'not-a-url',
    ]);
    BillingApiHelper::skipIfStripeBootstrapBlocks($status, $json, $raw);
    BillingApiHelper::assertLocalGuardFailure($status, $json, 403);
});

/**
 * Prerequisites:
 * - TEST_USER_1 can sign in (personal role is enough; the method check runs
 *   after authentication and before Stripe).
 *
 * Steps:
 * 1. GET the billing portal route with a valid session (POST is required).
 * 2. Assert the method is refused (**HTTP 405**) with a non-empty error.
 * 3. Assert the response does not include a Stripe portal URL.
 */
test('Billing — the billing portal rejects the wrong HTTP method', function () {
    $bearer = BillingApiHelper::bearerForUser1Personal();
    [$status, $json] = BillingApiHelper::get('billing/portal', $bearer);
    BillingApiHelper::assertLocalGuardFailure($status, $json, 405);
});

/**
 * Prerequisites:
 * - BILLING_TEST_COMPANY_ADMIN_UUID is configured for TEST_USER_1.
 * - Stripe SDK/config must be present so the request can reach return-URL
 *   validation; **HTTP 503** skips.
 * - STRIPE_PORTAL_RETURN_URL must not override the body with a valid URL (that
 *   would reach Stripe customer/session creation). Those outcomes skip.
 *
 * Steps:
 * 1. Switch to the company-admin role and POST the portal with a non-URL return
 *    address.
 * 2. Skip if Stripe bootstrap blocks, if a session URL is returned, or if the
 *    **HTTP 400** is not the return-URL guard.
 * 3. Assert validation fails (**HTTP 400**), the error mentions `return_url`,
 *    and no Stripe portal URL is present.
 */
test('Billing — the billing portal rejects an invalid return URL', function () {
    $bearer = BillingApiHelper::bearerForUser1Admin();
    [$status, $json, $raw] = BillingApiHelper::post('billing/portal', $bearer, [
        'return_url' => 'not-a-url',
    ]);
    BillingApiHelper::skipIfStripeBootstrapBlocks($status, $json, $raw);

    $debug = BillingApiHelper::debug($status, $json, $raw);
    $errors = BillingApiHelper::joinedErrors($json);
    $url = is_array($json) ? ($json['data']['url'] ?? null) : null;

    if (is_string($url) && $url !== '') {
        test()->markTestSkipped(
            'A configured portal return URL was accepted and a Stripe session URL was returned; this Feature test does not call Stripe. '
            . $debug,
        );
    }
    if ($status === 502) {
        test()->markTestSkipped(
            'Portal request reached Stripe (HTTP 502); invalid return_url did not fail locally. ' . $debug,
        );
    }
    if ($status === 400 && !str_contains($errors, 'return_url')) {
        test()->markTestSkipped(
            'HTTP 400 was not the return_url local guard (likely STRIPE_PORTAL_RETURN_URL overrode the body). '
            . $debug,
        );
    }

    BillingApiHelper::assertLocalGuardFailure($status, $json, 400);
    expect($errors)->toContain('return_url');
});
