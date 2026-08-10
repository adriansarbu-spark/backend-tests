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
 * - An isolated catalog controller with no network or database access.
 *
 * Steps:
 * 1. Call the catalog with an unsupported HTTP method.
 * 2. Assert HTTP 405, the exact allowed methods, and no model lookup.
 */
test('Billing catalog — only GET is accepted', function () {
    billing_set_method('POST');
    [$registry, $load] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'OPTIONS'])
        ->and($controller->json['error'])->toBe(['Method not allowed'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - The active role has no GET permission for the billing catalog.
 *
 * Steps:
 * 1. Request the catalog.
 * 2. Assert the standard permission response wins before currency or catalog lookup.
 */
test('Billing catalog — route permission is enforced before model access', function () {
    [$registry, $load] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry);
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated personal role and deterministic currency/catalog models.
 *
 * Steps:
 * 1. Request the catalog.
 * 2. Assert personal audience and exact identity context reach the models.
 * 3. Assert currency and an empty products array are returned.
 */
test('Billing catalog — personal role maps to the personal audience', function () {
    $currency = new BillingModelStub(['resolveBillingCurrency' => 'EUR']);
    $catalog = new BillingModelStub(['getActiveProductsWithPricesForAudience' => []]);
    [$registry] = billing_registry(
        new BillingCustomerStub(11, 22, 33, 'personal'),
        ['billing/customer_currency' => $currency, 'billing/public_catalog' => $catalog],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();

    expect(billing_calls($currency, 'resolveBillingCurrency')[0]['args'])->toBe(['personal', 22, 11])
        ->and(billing_calls($catalog, 'getActiveProductsWithPricesForAudience')[0]['args'])->toBe(['personal', 'EUR'])
        ->and($controller->json['data'])->toBe(['billing_currency' => 'EUR', 'products' => []]);
});

/**
 * Prerequisites:
 * - An authenticated company-admin role and deterministic catalog models.
 *
 * Steps:
 * 1. Request the catalog.
 * 2. Assert the role maps to the admin audience and its billing currency is forwarded.
 */
test('Billing catalog — admin role maps to the admin audience', function () {
    $currency = new BillingModelStub(['resolveBillingCurrency' => 'RON']);
    $catalog = new BillingModelStub(['getActiveProductsWithPricesForAudience' => []]);
    [$registry] = billing_registry(
        new BillingCustomerStub(7, 8, 9, 'admin'),
        ['billing/customer_currency' => $currency, 'billing/public_catalog' => $catalog],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();

    expect(billing_calls($currency, 'resolveBillingCurrency')[0]['args'])->toBe(['admin', 8, 7])
        ->and(billing_calls($catalog, 'getActiveProductsWithPricesForAudience')[0]['args'])->toBe(['admin', 'RON']);
});

/**
 * Prerequisites:
 * - A signed-in role whose code is not supported by billing catalog audiences.
 *
 * Steps:
 * 1. Request the catalog for each unsupported role code.
 * 2. Assert HTTP 403 and prove no currency or product lookup occurred.
 */
test('Billing catalog — unsupported roles cannot inspect products', function (?string $roleCode) {
    $currency = new BillingModelStub();
    $catalog = new BillingModelStub();
    [$registry, $load] = billing_registry(
        new BillingCustomerStub(1, 2, 3, $roleCode),
        ['billing/customer_currency' => $currency, 'billing/public_catalog' => $catalog],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['Access denied'])
        ->and($load->loadedModels)->toBe([])
        ->and($catalog->calls)->toBe([]);
})->with(['employee', 'representative', '', null, 'unknown']);

/**
 * Prerequisites:
 * - The catalog model returns a complete product with scalar-looking values.
 *
 * Steps:
 * 1. Request the catalog.
 * 2. Assert product content is preserved while tiers and flags use API scalar types.
 */
test('Billing catalog — product content and scalar types are mapped consistently', function () {
    $product = [
        'name' => 'Team',
        'internal_code' => 'team',
        'description' => 'For companies',
        'audience' => 'admin',
        'plan_tier' => '2',
        'sort_order' => '10',
        'has_recurring' => '1',
        'has_one_time' => 0,
        'feature_bullets' => ['Five seats'],
        'entitlements_summary' => [['code' => 'documents', 'quantity' => 100]],
        'prices' => [['uuid' => 'price-1', 'unit_amount' => 1999, 'currency' => 'EUR']],
    ];
    $currency = new BillingModelStub(['resolveBillingCurrency' => 'EUR']);
    $catalog = new BillingModelStub(['getActiveProductsWithPricesForAudience' => [$product]]);
    [$registry] = billing_registry(
        new BillingCustomerStub(),
        ['billing/customer_currency' => $currency, 'billing/public_catalog' => $catalog],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();
    $mapped = $controller->json['data']['products'][0];

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['billing_currency'])->toBe('EUR')
        ->and($mapped['name'])->toBe('Team')
        ->and($mapped['internal_code'])->toBe('team')
        ->and($mapped['description'])->toBe('For companies')
        ->and($mapped['audience'])->toBe('admin')
        ->and($mapped['plan_tier'])->toBe(2)
        ->and($mapped['sort_order'])->toBe(10)
        ->and($mapped['has_recurring'])->toBeTrue()
        ->and($mapped['has_one_time'])->toBeFalse()
        ->and($mapped['feature_bullets'])->toBe(['Five seats'])
        ->and($mapped['entitlements_summary'])->toBe($product['entitlements_summary'])
        ->and($mapped['prices'])->toBe($product['prices']);
});

/**
 * Prerequisites:
 * - The catalog model omits all optional product fields.
 *
 * Steps:
 * 1. Request the catalog.
 * 2. Assert every documented optional field receives a stable default.
 */
test('Billing catalog — omitted optional product fields receive stable defaults', function () {
    $currency = new BillingModelStub(['resolveBillingCurrency' => 'EUR']);
    $catalog = new BillingModelStub(['getActiveProductsWithPricesForAudience' => [[
        'name' => 'Basic',
        'internal_code' => 'basic',
        'description' => '',
        'prices' => [],
    ]]]);
    [$registry] = billing_registry(
        new BillingCustomerStub(),
        ['billing/customer_currency' => $currency, 'billing/public_catalog' => $catalog],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCatalog::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->json['data']['products'][0])->toMatchArray([
        'audience' => 'both',
        'plan_tier' => 0,
        'sort_order' => 0,
        'has_recurring' => false,
        'has_one_time' => false,
        'feature_bullets' => [],
        'entitlements_summary' => [],
        'prices' => [],
    ]);
});

