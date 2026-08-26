<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/BillingTestDoubles.php';

beforeEach(function () {
    $this->billingHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->billingSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    billing_set_method('POST');
    stripe_test_reset();
});

afterEach(function () {
    if ($this->billingHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->billingSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - An authorized billing manager has an owned Stripe customer and safe return URL.
 *
 * Steps:
 * 1. Request a payment-method-update portal flow.
 * 2. Assert the provider gets only the owned customer, return URL, and requested flow.
 * 3. Assert the public response contains no customer identifier or secret.
 */
test('Billing portal — owned customer creates a scoped payment-method session', function () {
    $assignment = new BillingModelStub(['canManageCompanyBilling' => true]);
    $redirect = new BillingModelStub(['resolvePortalReturnUrl' => 'https://app.test/billing']);
    $customer = new BillingModelStub(['getStripeCustomerId' => 'cus_owned']);
    $subscriptions = new BillingModelStub(['getLatestStripeCustomerIdForCompany' => 'cus_fallback']);
    [$registry] = billing_registry(models: [
        'billing/entitlement_assignment' => $assignment,
        'billing/checkout_redirect' => $redirect,
        'billing/company_stripe_customer' => $customer,
        'billing/company_subscription' => $subscriptions,
    ]);
    /** @var TestableControllerPublicapiv1BillingPortal $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingPortal::class, $registry, billing_all_permissions());
    $controller->setBillingPost(['return_url' => 'https://app.test/billing', 'flow' => 'payment_method_update']);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and(\Stripe\BillingPortal\Session::$createCalls[0])->toMatchArray([
            'customer' => 'cus_owned',
            'return_url' => 'https://app.test/billing',
            'flow_data' => ['type' => 'payment_method_update'],
        ])
        ->and($controller->json['data'])->toBe([
            'url' => 'https://billing.test/portal',
            'company_id' => 20,
        ])
        ->and(json_encode($controller->json))->not->toContain('cus_owned');
});

/**
 * Prerequisites:
 * - An authorized billing manager has no owned Stripe customer reference.
 *
 * Steps:
 * 1. Resolve both the direct and mirrored-customer lookups as empty.
 * 2. Request a portal session.
 * 3. Assert validation fails before the provider call.
 */
test('Billing portal — missing owned customer stops before Stripe', function () {
    [$registry] = billing_registry(models: [
        'billing/entitlement_assignment' => new BillingModelStub(['canManageCompanyBilling' => true]),
        'billing/checkout_redirect' => new BillingModelStub(['resolvePortalReturnUrl' => 'https://app.test/billing']),
        'billing/company_stripe_customer' => new BillingModelStub(['getStripeCustomerId' => '']),
        'billing/company_subscription' => new BillingModelStub(['getLatestStripeCustomerIdForCompany' => '']),
    ]);
    $controller = billing_controller(TestableControllerPublicapiv1BillingPortal::class, $registry, billing_all_permissions());
    $controller->setBillingPost(['return_url' => 'https://app.test/billing']);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->not->toBe([])
        ->and(\Stripe\BillingPortal\Session::$createCalls)->toBe([]);
});
