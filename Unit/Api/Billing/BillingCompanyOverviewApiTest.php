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
 * - An unauthenticated customer in the unit controller harness.
 *
 * Steps:
 * 1. Request the company billing overview.
 * 2. Assert HTTP 401 and prove no authorization or snapshot model was loaded.
 */
test('Billing overview — authentication is required before model access', function () {
    [$registry, $load] = billing_registry(new BillingCustomerStub(0, 20, 30));
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['Authentication required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated caller missing either company or active-role context.
 *
 * Steps:
 * 1. Request the overview for each missing context value.
 * 2. Assert HTTP 400 and no downstream model access.
 */
test('Billing overview — company and role context are both required', function (int $companyId, int $roleId) {
    [$registry, $load] = billing_registry(new BillingCustomerStub(10, $companyId, $roleId));
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['No company context'])
        ->and($load->loadedModels)->toBe([]);
})->with([[0, 30], [20, 0]]);

/**
 * Prerequisites:
 * - A caller without GET permission for the company overview.
 *
 * Steps:
 * 1. Request the overview.
 * 2. Assert the permission denial happens before the admin lookup.
 */
test('Billing overview — route permission precedes the admin check', function () {
    [$registry, $load] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry);
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated company role that is not an administrator.
 *
 * Steps:
 * 1. Request the overview.
 * 2. Assert HTTP 403 and prove the overview model was never loaded.
 */
test('Billing overview — a non-admin role cannot read the company snapshot', function () {
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => false]);
    [$registry, $load] = billing_registry(
        models: ['billing/entitlement_assignment' => $assignment],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['Admin role required'])
        ->and(billing_calls($assignment, 'isRoleAdminForCompany')[0]['args'])->toBe([30, 20])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment']);
});

/**
 * Prerequisites:
 * - An authenticated admin whose request uses an unsupported method.
 *
 * Steps:
 * 1. Call the endpoint with POST.
 * 2. Assert the current guard order performs the admin check before returning HTTP 405.
 */
test('Billing overview — method validation currently follows the admin check', function () {
    billing_set_method('POST');
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => true]);
    [$registry, $load] = billing_registry(models: ['billing/entitlement_assignment' => $assignment]);
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'OPTIONS'])
        ->and($controller->json['error'])->toBe(['Method not allowed'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment']);
});

/**
 * Prerequisites:
 * - An authenticated company admin and a deterministic overview payload.
 *
 * Steps:
 * 1. Request the overview.
 * 2. Assert the active company ID is delegated exactly once.
 * 3. Assert the model payload is preserved unchanged under data.
 */
test('Billing overview — admin receives the active company model payload unchanged', function (array $payload) {
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => true]);
    $overview = new BillingModelStub(['getOverviewForCompany' => $payload]);
    [$registry] = billing_registry(
        new BillingCustomerStub(10, 44, 55),
        [
            'billing/entitlement_assignment' => $assignment,
            'billing/company_overview' => $overview,
        ],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCompanyOverview::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and(billing_calls($overview, 'getOverviewForCompany'))->toHaveCount(1)
        ->and(billing_calls($overview, 'getOverviewForCompany')[0]['args'])->toBe([44])
        ->and($controller->json['data'])->toBe($payload);
})->with([
    'full payload' => [[
        'company_id' => 44,
        'subscriptions' => [['status' => 'active']],
        'seat_summary' => ['assigned' => 2],
    ]],
    'empty payload' => [[]],
]);

