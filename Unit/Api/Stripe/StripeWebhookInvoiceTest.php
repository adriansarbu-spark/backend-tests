<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/StripeWebhookTestDoubles.php';

beforeEach(function () {
    stripe_webhook_reset();
});

/**
 * Prerequisites:
 * - Stripe invoice payloads use legacy top-level, modern line-parent, or modern invoice-parent subscription shapes.
 *
 * Steps:
 * 1. Resolve a subscription ID from each supported payload shape.
 * 2. Resolve an unrelated invoice with no subscription reference.
 * 3. Assert deterministic IDs without mutating billing state.
 */
test('Stripe webhook — invoice subscription IDs resolve across supported payload shapes', function () {
    [$controller] = stripe_webhook_fixture();
    $top = (object) ['subscription' => (object) ['id' => 'sub_top']];
    $line = (object) ['lines' => (object) ['data' => [(object) [
        'parent' => (object) [
            'type' => 'subscription_item_details',
            'subscription_item_details' => (object) ['subscription' => 'sub_line'],
        ],
    ]]]];
    $parent = (object) ['parent' => (object) [
        'type' => 'subscription_details',
        'subscription_details' => (object) ['subscription' => (object) ['id' => 'sub_parent']],
    ]];

    expect(stripe_webhook_invoke($controller, 'resolveStripeSubscriptionIdFromInvoice', [$top]))->toBe('sub_top')
        ->and(stripe_webhook_invoke($controller, 'resolveStripeSubscriptionIdFromInvoice', [$line]))->toBe('sub_line')
        ->and(stripe_webhook_invoke($controller, 'resolveStripeSubscriptionIdFromInvoice', [$parent]))->toBe('sub_parent')
        ->and(stripe_webhook_invoke($controller, 'resolveStripeSubscriptionIdFromInvoice', [(object) []]))->toBe('');
});

/**
 * Prerequisites:
 * - A modern subscription invoice line exposes price through pricing.price_details and an explicit period.
 *
 * Steps:
 * 1. Extract the first subscription plan line.
 * 2. Assert normalized price, minimum quantity, and integer period bounds.
 * 3. Assert non-subscription lines are ignored.
 */
test('Stripe webhook — invoice line extraction normalizes modern pricing and periods', function () {
    [$controller] = stripe_webhook_fixture();
    $invoice = (object) ['lines' => (object) ['data' => [
        (object) ['type' => 'invoiceitem', 'price' => 'price_ignore'],
        (object) [
            'parent' => (object) ['type' => 'subscription_item_details'],
            'pricing' => (object) ['price_details' => (object) ['price' => 'price_modern']],
            'quantity' => 0,
            'period' => (object) ['start' => 1767225600, 'end' => 1769904000],
        ],
    ]]];

    $line = stripe_webhook_invoke($controller, 'extractSubscriptionInvoiceLines', [$invoice]);

    expect($line)->toBe([[
        'stripe_price_id' => 'price_modern',
        'line_qty' => 1,
        'period_start' => 1767225600,
        'period_end' => 1769904000,
    ]]);
});

/**
 * Prerequisites:
 * - A paid monthly invoice grants a monthly entitlement for an owned subscription.
 *
 * Steps:
 * 1. Process the same invoice twice to simulate webhook retries.
 * 2. Assert both inserts use the same idempotency key, period, quantity, and company.
 * 3. Assert no secret or raw payment detail enters the grant payload.
 */
test('Stripe webhook — paid invoice retries preserve normalized idempotent grant rows', function () {
    $companySubscription = new BillingModelStub(['getByStripeSubscriptionId' => null]);
    $price = new BillingModelStub([
        'getPriceIdByStripePriceId' => 71,
        'getRecurringIntervalRowForPrice' => [
            'price_id' => 71,
            'product_id' => 81,
            'type' => 'recurring',
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
        ],
    ]);
    $upgrade = new BillingModelStub(['getPriceSubscriptionContext' => ['kind' => 'month']]);
    $resolver = new BillingModelStub([
        'getEntitlementsForProduct' => [[
            'code' => 'documents',
            'quantity' => 12,
            'per_seat' => false,
            'period' => 'month',
        ]],
        'normalizeProductEntitlementPeriod' => 'month',
    ]);
    $grant = new BillingModelStub(['getGrantByKey' => null]);
    $assignment = new BillingModelStub(['isCompanyPersonal' => false]);
    [$controller, $registry] = stripe_webhook_fixture([
        'billing/company_subscription' => $companySubscription,
        'billing/price' => $price,
        'billing/subscription_upgrade' => $upgrade,
        'billing/entitlement_resolver' => $resolver,
        'billing/entitlement_grant' => $grant,
        'billing/entitlement_assignment' => $assignment,
    ]);
    $registry->set('model_billing_company_subscription', $companySubscription);
    $registry->set('model_billing_price', $price);

    $fullInvoice = (object) [
        'id' => 'in_once',
        'status' => 'paid',
        'subscription' => 'sub_owned',
        'created' => 1767225601,
        'period_start' => 1767225600,
        'period_end' => 1769904000,
        'lines' => (object) ['data' => [(object) [
            'type' => 'subscription',
            'price' => (object) ['id' => 'price_monthly'],
            'quantity' => 1,
            'period' => (object) ['start' => 1767225600, 'end' => 1769904000],
        ]]],
    ];
    $subscription = (object) [
        'id' => 'sub_owned',
        'metadata' => (object) ['company_id' => '20'],
        'customer' => 'cus_owned',
        'status' => 'active',
        'items' => (object) ['data' => [(object) [
            'price' => (object) ['id' => 'price_monthly'],
            'quantity' => 1,
            'current_period_start' => 1767225600,
            'current_period_end' => 1769904000,
        ]]],
    ];
    \Stripe\Invoice::$retrieveQueue = [$fullInvoice, $fullInvoice];
    \Stripe\Subscription::$retrieveQueue = [$subscription, $subscription];

    stripe_webhook_invoke($controller, 'maybeInsertSubscriptionInvoiceGrants', [(object) ['id' => 'in_once', 'status' => 'paid']]);
    stripe_webhook_invoke($controller, 'maybeInsertSubscriptionInvoiceGrants', [(object) ['id' => 'in_once', 'status' => 'paid']]);

    $inserts = billing_calls($grant, 'insertGrantIgnoreDuplicate');
    expect($inserts)->toHaveCount(2)
        ->and($inserts[0]['args'][0])->toMatchArray([
            'grant_key' => 'invoice:in_once:entitlement:documents:m0',
            'company_id' => 20,
            'entitlement_code' => 'documents',
            'quantity' => 12,
            'stripe_invoice_id' => 'in_once',
            'starts_at' => '2026-01-01 00:00:00',
            'expires_at' => '2026-02-01 00:00:00',
        ])
        ->and($inserts[1]['args'][0]['grant_key'])->toBe($inserts[0]['args'][0]['grant_key'])
        ->and(json_encode($inserts[0]['args'][0]))->not->toContain('secret')
        ->and(json_encode($inserts[0]['args'][0]))->not->toContain('payment_method');
});

/**
 * Prerequisites:
 * - A newly synchronized yearly subscription supersedes a lower tier and the same product's monthly stream.
 *
 * Steps:
 * 1. Evaluate the active mirrored subscriptions.
 * 2. Assert only the lower tier and month-to-year sibling are cancelled.
 * 3. Assert the winner and unrelated higher tier remain untouched.
 */
test('Stripe webhook — superseded subscription cancellation targets only eligible mirrors', function () {
    $upgrade = new BillingModelStub([
        'getPriceSubscriptionContext' => [
            'product_id' => 81,
            'plan_tier' => 3,
            'kind' => 'year',
        ],
        'shouldAutoCancelSupersededSubscriptions' => true,
        'getActiveRecurringMirrorRows' => [
            ['stripe_subscription_id' => 'sub_winner', 'plan_tier' => 3, 'kind' => 'year', 'product_id' => 81],
            ['stripe_subscription_id' => 'sub_lower', 'plan_tier' => 2, 'kind' => 'year', 'product_id' => 82],
            ['stripe_subscription_id' => 'sub_month', 'plan_tier' => 3, 'kind' => 'month', 'product_id' => 81],
            ['stripe_subscription_id' => 'sub_higher', 'plan_tier' => 4, 'kind' => 'year', 'product_id' => 90],
        ],
    ]);
    [$controller] = stripe_webhook_fixture(['billing/subscription_upgrade' => $upgrade]);

    stripe_webhook_invoke($controller, 'maybeCancelLowerTierStripeSubscriptions', [[
        'company_id' => 20,
        'stripe_subscription_id' => 'sub_winner',
        'price_id' => 71,
    ]]);

    expect(array_column(\Stripe\Subscription::$updateCalls, 'id'))->toBe(['sub_lower', 'sub_month'])
        ->and(\Stripe\Subscription::$updateCalls[0]['params'])->toBe(['cancel_at_period_end' => true]);
});
