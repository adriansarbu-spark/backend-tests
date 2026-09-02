<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/BillingTestDoubles.php';

beforeEach(function () {
    $this->billingHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->billingSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    billing_set_method('POST');
});

afterEach(function () {
    if ($this->billingHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->billingSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * @param array<string, object>       $models
 * @param array<string, list<string>> $permissions
 *
 * @return array{0: TestableControllerPublicapiv1BillingPortal, 1: BillingLoadStub}
 */
function billing_portal_guard_controller(
    ?BillingCustomerStub $customer = null,
    array $models = [],
    ?array $permissions = null,
): array {
    [$registry, $load] = billing_registry($customer, $models);
    /** @var TestableControllerPublicapiv1BillingPortal $controller */
    $controller = billing_controller(
        TestableControllerPublicapiv1BillingPortal::class,
        $registry,
        $permissions ?? billing_all_permissions(),
    );

    return [$controller, $load];
}

/**
 * Prerequisites:
 * - The billing portal is called without a signed-in customer.
 *
 * Steps:
 * 1. POST the portal controller with customer id 0.
 * 2. Assert authentication is refused (HTTP 401) with a non-empty error.
 * 3. Prove no billing model was loaded.
 */
test('Billing portal — unauthenticated caller is refused', function () {
    [$controller, $load] = billing_portal_guard_controller(new BillingCustomerStub(0, 20, 30));
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['Authentication required'])
        ->and($load->loadedModels)->toBe([])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - An authenticated company member is not allowed to manage company billing.
 *
 * Steps:
 * 1. POST the portal as a signed-in non-admin role.
 * 2. Assert admin access is refused (HTTP 403) with a non-empty error.
 * 3. Prove the admin check ran for the active role/company and that return-URL
 *    resolution never starts.
 */
test('Billing portal — non-admin role cannot open the portal', function () {
    $assignment = new BillingModelStub(['canManageCompanyBilling' => false]);
    $redirect = new BillingModelStub(['resolvePortalReturnUrl' => 'https://app.test/billing']);
    [$controller, $load] = billing_portal_guard_controller(models: [
        'billing/entitlement_assignment' => $assignment,
        'billing/checkout_redirect' => $redirect,
    ]);
    $controller->setBillingPost(['return_url' => 'https://app.test/billing']);
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['Admin role required'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment'])
        ->and(billing_calls($assignment, 'canManageCompanyBilling')[0]['args'])->toBe([30, 20])
        ->and(billing_calls($redirect, 'resolvePortalReturnUrl'))->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated caller uses a verb other than POST.
 *
 * Steps:
 * 1. Call the portal with each unsupported method.
 * 2. Assert the method is refused (HTTP 405) with POST and OPTIONS allowed.
 * 3. Prove no billing model was loaded.
 */
test('Billing portal — wrong HTTP method is refused', function (string $method) {
    billing_set_method($method);
    [$controller, $load] = billing_portal_guard_controller();
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['Method not allowed'])
        ->and($controller->allowedHeaders)->toBe(['POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe([]);
})->with(['GET', 'PUT', 'DELETE']);

/**
 * Prerequisites:
 * - A company billing admin posts a return URL that the redirect model rejects.
 *
 * Steps:
 * 1. Allow the admin check and stub portal return-URL resolution as invalid.
 * 2. POST the portal with that return URL.
 * 3. Assert validation fails (HTTP 400) with a non-empty return_url error.
 * 4. Prove Stripe customer lookup never starts.
 */
test('Billing portal — invalid return URL is rejected locally', function () {
    $assignment = new BillingModelStub(['canManageCompanyBilling' => true]);
    $redirect = new BillingModelStub(['resolvePortalReturnUrl' => null]);
    $stripeCustomer = new BillingModelStub(['getStripeCustomerId' => 'cus_should_not_load']);
    [$controller, $load] = billing_portal_guard_controller(models: [
        'billing/entitlement_assignment' => $assignment,
        'billing/checkout_redirect' => $redirect,
        'billing/company_stripe_customer' => $stripeCustomer,
    ]);
    $controller->setBillingPost(['return_url' => 'http://not-https.example/billing']);
    $controller->index();

    $errors = (array) $controller->json['error'];
    expect($controller->statusCode)->toBe(400)
        ->and($errors)->not->toBe([])
        ->and($errors[0])->toContain('return_url')
        ->and(billing_calls($redirect, 'resolvePortalReturnUrl')[0]['args'])->toBe(['http://not-https.example/billing'])
        ->and($load->loadedModels)->toBe([
            'billing/entitlement_assignment',
            'billing/checkout_redirect',
        ])
        ->and(billing_calls($stripeCustomer, 'getStripeCustomerId'))->toBe([]);
});
