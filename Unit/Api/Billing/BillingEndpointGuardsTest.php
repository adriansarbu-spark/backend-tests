<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/BillingTestDoubles.php';

beforeEach(function () {
    $this->billingHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->billingSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    billing_set_method('GET');
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
 * - An unauthenticated customer calls an included authenticated billing controller.
 *
 * Steps:
 * 1. Request each endpoint through its controller harness.
 * 2. Assert HTTP 401 with a non-empty error.
 * 3. Prove no read or write model was loaded.
 */
test('Billing guards — authenticated billing routes reject an unauthenticated customer', function (string $class) {
    [$registry, $load] = billing_registry(new BillingCustomerStub(0, 20, 30));
    $controller = billing_controller($class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and((array) $controller->json['error'])->not->toBe([])
        ->and($load->loadedModels)->toBe([]);
})->with([
    TestableControllerPublicapiv1BillingCompanyOverview::class,
    TestableControllerPublicapiv1BillingEntitlements::class,
    TestableControllerPublicapiv1BillingEntitlementGrants::class,
    TestableControllerPublicapiv1BillingLedgerRoleConsumption::class,
    TestableControllerPublicapiv1BillingLedgerRoleGrants::class,
    TestableControllerPublicapiv1BillingMe::class,
    TestableControllerPublicapiv1BillingSeats::class,
]);

/**
 * Prerequisites:
 * - An authenticated caller lacks the required route permission.
 *
 * Steps:
 * 1. Request each included read or exact write action.
 * 2. Assert HTTP 403 access_denied.
 * 3. Prove authorization wins before model reads or writes.
 */
test('Billing guards — public API permissions precede billing model access', function (
    string $class,
    string $method,
    string $route,
) {
    billing_set_method($method);
    [$registry, $load] = billing_registry(query: ['route' => $route]);
    $controller = billing_controller($class, $registry);
    if (method_exists($controller, 'setBillingPost')) {
        $controller->setBillingPost([]);
    }
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
})->with([
    [TestableControllerPublicapiv1BillingCatalog::class, 'GET', 'publicapi/v1/billing/catalog'],
    [TestableControllerPublicapiv1BillingCompanyOverview::class, 'GET', 'publicapi/v1/billing/company_overview'],
    [TestableControllerPublicapiv1BillingEntitlements::class, 'GET', 'publicapi/v1/billing/entitlements'],
    [TestableControllerPublicapiv1BillingEntitlementGrants::class, 'GET', 'publicapi/v1/billing/entitlement_grants'],
    [TestableControllerPublicapiv1BillingEntitlementGrants::class, 'POST', 'publicapi/v1/billing/entitlement_grants/assign'],
    [TestableControllerPublicapiv1BillingLedgerRoleConsumption::class, 'GET', 'publicapi/v1/billing/ledger_role_consumption'],
    [TestableControllerPublicapiv1BillingLedgerRoleGrants::class, 'GET', 'publicapi/v1/billing/ledger_role_grants'],
    [TestableControllerPublicapiv1BillingMe::class, 'GET', 'publicapi/v1/billing/me'],
    [TestableControllerPublicapiv1BillingSeats::class, 'GET', 'publicapi/v1/billing/seats'],
    [TestableControllerPublicapiv1BillingSeats::class, 'POST', 'publicapi/v1/billing/seats/assign'],
    [TestableControllerPublicapiv1BillingSeats::class, 'POST', 'publicapi/v1/billing/seats/release'],
]);

/**
 * Prerequisites:
 * - An authenticated company role is not an administrator.
 *
 * Steps:
 * 1. Call every included admin-only controller route.
 * 2. Assert HTTP 403 admin-required.
 * 3. Prove no list or mutation model is loaded after the admin check.
 */
test('Billing guards — admin-only routes reject non-admin roles before downstream work', function (
    string $class,
    string $method,
    string $route,
) {
    billing_set_method($method);
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => false]);
    [$registry, $load] = billing_registry(
        models: ['billing/entitlement_assignment' => $assignment],
        query: ['route' => $route],
    );
    $controller = billing_controller($class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['Admin role required'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment'])
        ->and(billing_calls($assignment, 'isRoleAdminForCompany')[0]['args'])->toBe([30, 20]);
})->with([
    [TestableControllerPublicapiv1BillingCompanyOverview::class, 'GET', 'publicapi/v1/billing/company_overview'],
    [TestableControllerPublicapiv1BillingEntitlementGrants::class, 'GET', 'publicapi/v1/billing/entitlement_grants'],
    [TestableControllerPublicapiv1BillingEntitlementGrants::class, 'POST', 'publicapi/v1/billing/entitlement_grants/assign'],
    [TestableControllerPublicapiv1BillingSeats::class, 'GET', 'publicapi/v1/billing/seats'],
    [TestableControllerPublicapiv1BillingSeats::class, 'POST', 'publicapi/v1/billing/seats/assign'],
    [TestableControllerPublicapiv1BillingSeats::class, 'POST', 'publicapi/v1/billing/seats/release'],
]);

