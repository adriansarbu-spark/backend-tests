<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/StripeWebhookTestDoubles.php';

beforeEach(function () {
    stripe_webhook_reset();
});

/**
 * Prerequisites:
 * - A paid one-time checkout contains two units of a valid entitlement pack.
 * - The company is a personal workspace with one assignable role.
 *
 * Steps:
 * 1. Process the same session twice as a webhook retry.
 * 2. Assert both attempts use the identical DB-enforced grant key and aggregate quantity.
 * 3. Assert personal auto-assignment and customer synchronization remain company scoped.
 */
test('Stripe webhook — payment-pack retries preserve idempotent grant and assignment keys', function () {
    $price = new BillingModelStub([
        'getActivePriceProductByStripePriceId' => [
            'price_id' => 71,
            'product_id' => 81,
            'type' => 'one_time',
        ],
    ]);
    $resolver = new BillingModelStub([
        'getEntitlementsForProduct' => [
            ['code' => 'documents', 'quantity' => 5],
            ['code' => '', 'quantity' => 999],
        ],
    ]);
    $grant = new BillingModelStub(['getGrantByKey' => ['grant_id' => 91]]);
    $assignment = new BillingModelStub([
        'isCompanyPersonal' => true,
        'getPersonalRoleIdForCompany' => 31,
    ]);
    $stripeCustomer = new BillingModelStub();
    [$controller] = stripe_webhook_fixture([
        'billing/price' => $price,
        'billing/entitlement_resolver' => $resolver,
        'billing/entitlement_grant' => $grant,
        'billing/entitlement_assignment' => $assignment,
        'billing/company_stripe_customer' => $stripeCustomer,
    ]);
    $full = (object) ['customer' => (object) ['id' => 'cus_owned']];
    $page = new \Stripe\Checkout\LineItemsPage([
        (object) ['quantity' => 2, 'price' => (object) ['id' => 'price_pack']],
    ]);
    \Stripe\Checkout\Session::$retrieveQueue = [$full, $full];
    \Stripe\Checkout\Session::$lineItemsQueue = [$page, $page];
    $session = (object) [
        'id' => 'cs_pack_once',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'metadata' => (object) ['company_id' => '20'],
    ];

    stripe_webhook_invoke($controller, 'handleCheckoutSessionCompleted', [$session]);
    stripe_webhook_invoke($controller, 'handleCheckoutSessionCompleted', [$session]);

    $inserts = billing_calls($grant, 'insertGrantIgnoreDuplicate');
    $assignments = billing_calls($assignment, 'assignGrantToRole');
    expect($inserts)->toHaveCount(2)
        ->and($inserts[0]['args'][0])->toMatchArray([
            'grant_key' => 'checkout:cs_pack_once:documents',
            'company_id' => 20,
            'entitlement_code' => 'documents',
            'quantity' => 10,
        ])
        ->and($inserts[1]['args'][0]['grant_key'])->toBe($inserts[0]['args'][0]['grant_key'])
        ->and($assignments)->toHaveCount(2)
        ->and($assignments[0]['args'])->toBe([91, 31, 10])
        ->and(billing_calls($stripeCustomer, 'upsert')[0]['args'])->toBe([20, 'cus_owned']);
});

/**
 * Prerequisites:
 * - A payment checkout is unpaid, missing company metadata, or missing its session identifier.
 *
 * Steps:
 * 1. Process each incomplete payment-session shape.
 * 2. Assert no line-item retrieval or grant write.
 * 3. Assert no response or log surface contains a secret.
 */
test('Stripe webhook — incomplete payment-pack sessions do not create grants', function (object $session) {
    $grant = new BillingModelStub();
    [$controller] = stripe_webhook_fixture(['billing/entitlement_grant' => $grant]);
    stripe_webhook_invoke($controller, 'handleCheckoutSessionPaymentPack', [$session]);

    expect(\Stripe\Checkout\Session::$lineItemsCalls)->toBe([])
        ->and(billing_calls($grant, 'insertGrantIgnoreDuplicate'))->toBe([]);
})->with([
    'unpaid' => [(object) [
        'id' => 'cs_unpaid',
        'payment_status' => 'unpaid',
        'metadata' => (object) ['company_id' => '20'],
    ]],
    'missing company metadata' => [(object) [
        'id' => 'cs_no_company',
        'payment_status' => 'paid',
        'metadata' => (object) [],
    ]],
    'missing session id' => [(object) [
        'payment_status' => 'paid',
        'metadata' => (object) ['company_id' => '20'],
    ]],
]);
