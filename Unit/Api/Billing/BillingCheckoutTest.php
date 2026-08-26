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
 * @param array<string, mixed>  $priceRow
 * @param array<string, object> $modelOverrides
 *
 * @return array{0: TestableControllerPublicapiv1BillingCheckout, 1: array<string, BillingModelStub>}
 */
function billing_checkout_fixture(
    ?BillingCustomerStub $customer = null,
    array $priceRow = [],
    array $modelOverrides = [],
): array {
    $priceRow = array_replace([
        'price_id' => 71,
        'price_uuid' => '11111111-1111-4111-8111-111111111111',
        'stripe_price_id' => 'price_monthly',
        'product_id' => 81,
        'product_audience' => 'admin',
        'plan_tier' => 2,
        'type' => 'recurring',
        'billing_interval' => 'month',
        'billing_interval_count' => 1,
        'currency' => 'EUR',
    ], $priceRow);
    $models = [
        'price' => $modelOverrides['price'] ?? new BillingModelStub([
            'getActiveCheckoutPriceRowByUuid' => $priceRow,
        ]),
        'redirect' => $modelOverrides['redirect'] ?? new BillingModelStub([
            'resolveCheckoutStyleUrl' => static fn ($value, string $kind): string => 'https://app.test/' . $kind,
        ]),
        'currency' => $modelOverrides['currency'] ?? new BillingModelStub([
            'resolveBillingCurrency' => 'EUR',
        ]),
        'upgrade' => $modelOverrides['upgrade'] ?? new BillingModelStub([
            'evaluateRecurringUpgradeCheckout' => ['ok' => true],
        ]),
        'subscription' => $modelOverrides['subscription'] ?? new BillingModelStub([
            'getActiveByCompanyAndIntervalGroup' => null,
            'getLatestStripeCustomerIdForCompany' => '',
        ]),
        'assignment' => $modelOverrides['assignment'] ?? new BillingModelStub([
            'isCompanyPersonal' => false,
        ]),
        'stripe_customer' => $modelOverrides['stripe_customer'] ?? new BillingModelStub([
            'getStripeCustomerId' => '',
        ]),
    ];
    [$registry] = billing_registry(
        $customer,
        [
            'billing/price' => $models['price'],
            'billing/checkout_redirect' => $models['redirect'],
            'billing/customer_currency' => $models['currency'],
            'billing/subscription_upgrade' => $models['upgrade'],
            'billing/company_subscription' => $models['subscription'],
            'billing/entitlement_assignment' => $models['assignment'],
            'billing/company_stripe_customer' => $models['stripe_customer'],
        ],
    );
    /** @var TestableControllerPublicapiv1BillingCheckout $controller */
    $controller = billing_controller(
        TestableControllerPublicapiv1BillingCheckout::class,
        $registry,
        billing_all_permissions(),
    );
    $controller->setBillingPost([
        'price_uuid' => $priceRow['price_uuid'],
        'quantity' => 2,
        'success_url' => 'https://app.test/success',
        'cancel_url' => 'https://app.test/cancel',
    ]);

    return [$controller, $models];
}

/**
 * Prerequisites:
 * - An authenticated company admin selects an active recurring EUR price.
 * - Stripe is represented by a deterministic test-only SDK double.
 *
 * Steps:
 * 1. Submit checkout with valid redirects and quantity.
 * 2. Assert the provider receives the permitted price, subscription mode, and company metadata.
 * 3. Assert the response exposes only the checkout contract fields.
 */
test('Billing checkout — permitted role price interval and currency build the expected session', function () {
    [$controller] = billing_checkout_fixture(new BillingCustomerStub(10, 20, 30, 'admin'));
    $controller->index();

    $params = \Stripe\Checkout\Session::$createCalls[0] ?? [];
    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toMatchArray([
            'checkout_session_id' => 'cs_test',
            'company_id' => 20,
            'billing_currency' => 'EUR',
            'quantity' => 2,
            'checkout_mode' => 'subscription',
        ])
        ->and($params['mode'] ?? null)->toBe('subscription')
        ->and($params['line_items'][0] ?? null)->toBe(['price' => 'price_monthly', 'quantity' => 2])
        ->and($params['metadata'] ?? null)->toBe(['company_id' => '20'])
        ->and($params)->not->toHaveKey('customer')
        ->and($params)->not->toHaveKey('customer_email');
});

/**
 * Prerequisites:
 * - Checkout receives a locally resolvable price but the caller/price/currency combination is not permitted.
 *
 * Steps:
 * 1. Exercise unsupported role, product-audience mismatch, and currency mismatch branches.
 * 2. Assert the stable status/application code.
 * 3. Prove no Stripe checkout session is created.
 */
test('Billing checkout — audience and currency guards run before Stripe', function (
    BillingCustomerStub $customer,
    array $priceOverrides,
    ?BillingModelStub $currency,
    int $expectedStatus,
    string $expectedError,
) {
    [$controller] = billing_checkout_fixture(
        $customer,
        $priceOverrides,
        $currency === null ? [] : ['currency' => $currency],
    );
    $controller->index();

    expect($controller->statusCode)->toBe($expectedStatus)
        ->and($controller->json['error'])->toContain($expectedError)
        ->and(\Stripe\Checkout\Session::$createCalls)->toBe([]);
})->with([
    'unsupported company role' => [
        new BillingCustomerStub(10, 20, 30, 'employee'),
        [],
        null,
        403,
        'Access denied',
    ],
    'personal caller selecting company-only price' => [
        new BillingCustomerStub(10, 20, 30, 'personal'),
        ['product_audience' => 'admin'],
        null,
        403,
        'Selected price is not available for this account type',
    ],
    'resolved billing currency differs from price' => [
        new BillingCustomerStub(10, 20, 30, 'admin'),
        ['currency' => 'EUR'],
        new BillingModelStub(['resolveBillingCurrency' => 'RON']),
        400,
        'price_currency_not_allowed',
    ],
]);

/**
 * Prerequisites:
 * - Personal billing identity data contains Unicode names, an issuing country, and an address.
 *
 * Steps:
 * 1. Build the Stripe customer parameters through the real private transformation.
 * 2. Assert normalized name, address, locale, and internal metadata.
 * 3. Prove personal tax IDs are not attached to the Stripe customer.
 */
test('Billing checkout — personal customer parameters normalize identity without tax IDs', function () {
    $account = new BillingModelStub([
        'getCustomer' => [
            'firstname' => 'Ștefan',
            'lastname' => 'Ionescu',
            'id_number' => 'identity-reference',
        ],
        'getCustomerIdentityAddressAndDocument' => [
            'document_country' => 'ROU',
            'address' => 'Strada Exemplu 1',
            'address_loc_denumire' => 'București',
        ],
    ]);
    [$registry] = billing_registry(
        new BillingCustomerStub(10, 20, 30, 'personal'),
        ['account/customer' => $account],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCheckout::class, $registry, billing_all_permissions());

    $params = billing_invoke_private($controller, 'buildStripePersonalCustomerCreateParams', [20, '20', 10, 'person@example.test']);

    expect($params['name'])->toBe('Ștefan Ionescu')
        ->and($params['preferred_locales'])->toBe(['ro'])
        ->and($params['address'])->toMatchArray([
            'country' => 'RO',
            'line1' => 'Strada Exemplu 1',
            'city' => 'București',
        ])
        ->and($params['metadata'])->toMatchArray([
            'company_id' => '20',
            'customer_id' => '10',
            'billing_entity' => 'personal',
        ])
        ->and($params)->not->toHaveKey('tax_id_data');
});

/**
 * Prerequisites:
 * - A Romanian company has CUI, VAT, trade-register, and headquarters fields.
 *
 * Steps:
 * 1. Build corporate Stripe customer parameters through the controller transformation.
 * 2. Assert normalized address, EUID metadata, locale, and managed tax IDs.
 * 3. Assert no storage or unrelated tenant data enters the provider payload.
 */
test('Billing checkout — corporate customer parameters normalize address and tax identifiers', function () {
    $company = new BillingModelStub([
        'getCompany' => [
            'uuid' => 'company-public-uuid',
            'country' => 'ROU',
            'cui' => '12345678',
            'vat_number' => 'RO 12345678',
            'recom' => 'J40/123/2024',
        ],
    ]);
    $db = new BillingDbStub([[
        'denumire_Strada' => 'Victoriei',
        'numar_Strada' => '10',
        'other_details' => 'Etaj 2',
        'city' => 'București',
        'state' => 'București',
    ]]);
    [$registry] = billing_registry(
        new BillingCustomerStub(10, 20, 30, 'admin'),
        ['account/company' => $company],
        db: $db,
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingCheckout::class, $registry, billing_all_permissions());

    $params = billing_invoke_private($controller, 'buildStripeCorporateCustomerCreateParams', [20, '20', 'billing@example.test', 'Companie']);

    expect($params['preferred_locales'])->toBe(['ro'])
        ->and($params['metadata']['euid'])->toBe('ROONRC.J202400012340')
        ->and($params['address'])->toMatchArray([
            'country' => 'RO',
            'line1' => 'Victoriei 10, Etaj 2',
            'city' => 'București',
        ])
        ->and($params['tax_id_data'])->toBe([
            ['type' => 'ro_tin', 'value' => '12345678'],
            ['type' => 'eu_vat', 'value' => 'RO12345678'],
        ])
        ->and(json_encode($params))->not->toContain('relative_path');
});

/**
 * Prerequisites:
 * - Stripe rejects customer creation only because optional tax IDs are present.
 *
 * Steps:
 * 1. Queue one provider failure followed by a successful customer response.
 * 2. Create through the controller retry helper.
 * 3. Assert the retry is bounded to two calls and removes only tax_id_data.
 */
test('Billing checkout — tax ID retry is bounded and preserves the remaining customer payload', function () {
    [$registry] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingCheckout::class, $registry, billing_all_permissions());
    \Stripe\Customer::$createQueue = [
        new \Stripe\Exception\ApiErrorException('unsupported tax id'),
        (object) ['id' => 'cus_retry_ok'],
    ];
    $params = [
        'email' => 'billing@example.test',
        'metadata' => ['company_id' => '20'],
        'tax_id_data' => [['type' => 'ro_tin', 'value' => '12345678']],
    ];

    $customerId = billing_invoke_private($controller, 'createStripeCustomerWithOptionalTaxIdRetry', [$params]);

    expect($customerId)->toBe('cus_retry_ok')
        ->and(\Stripe\Customer::$createCalls)->toHaveCount(2)
        ->and(\Stripe\Customer::$createCalls[0])->toHaveKey('tax_id_data')
        ->and(\Stripe\Customer::$createCalls[1])->not->toHaveKey('tax_id_data')
        ->and(\Stripe\Customer::$createCalls[1]['metadata'])->toBe(['company_id' => '20']);
});
