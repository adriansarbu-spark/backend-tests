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
 * @return array{0: TestableControllerPublicapiv1BillingSubscriptionItems, 1: array<string, BillingModelStub>}
 */
function billing_subscription_provider_fixture(): array
{
    $models = [
        'assignment' => new BillingModelStub(['isRoleAdminForCompany' => true]),
        'price' => new BillingModelStub(['getActiveCheckoutPriceRowByUuid' => [
            'price_id' => 71,
            'price_uuid' => '22222222-2222-4222-8222-222222222222',
            'stripe_price_id' => 'price_seat',
            'product_name' => 'Seat',
            'type' => 'recurring',
            'currency' => 'EUR',
            'billing_interval' => 'month',
            'unit_amount' => 1000,
        ]]),
        'currency' => new BillingModelStub(['resolveBillingCurrency' => 'EUR']),
        'subscription' => new BillingModelStub(['getActiveByCompanyAndIntervalGroup' => [
            'subscription_id' => 61,
            'company_id' => 20,
            'stripe_subscription_id' => 'sub_parent',
            'cancel_at_period_end' => false,
        ]]),
        'item' => new BillingModelStub(['getCancelPendingItemForSubscriptionAndPrice' => null]),
        'audit' => new BillingModelStub(['getDailyAppliedAmount' => 0]),
    ];
    [$registry] = billing_registry(
        models: [
            'billing/entitlement_assignment' => $models['assignment'],
            'billing/price' => $models['price'],
            'billing/customer_currency' => $models['currency'],
            'billing/company_subscription' => $models['subscription'],
            'billing/company_subscription_item' => $models['item'],
            'billing/subscription_change_audit' => $models['audit'],
        ],
        query: ['route' => 'publicapi/v1/billing/subscription_items'],
    );
    /** @var TestableControllerPublicapiv1BillingSubscriptionItems $controller */
    $controller = billing_controller(
        TestableControllerPublicapiv1BillingSubscriptionItems::class,
        $registry,
        billing_all_permissions(),
    );
    $controller->setBillingPost([
        'price_uuid' => '22222222-2222-4222-8222-222222222222',
        'quantity' => 2,
        'confirm' => true,
    ]);

    return [$controller, $models];
}

/**
 * Prerequisites:
 * - A confirmed low-risk seat change targets an existing Stripe price line.
 * - The provider reports the immediate invoice as paid.
 *
 * Steps:
 * 1. Apply the quantity change through the controller and real schedule helper.
 * 2. Assert provider parameters require immediate invoicing and rollback-on-incomplete payment.
 * 3. Assert local line sync and one applied audit include the resulting invoice ID.
 */
test('Billing subscription change — provider success syncs lines and records one applied audit', function () {
    [$controller, $models] = billing_subscription_provider_fixture();
    $line = (object) [
        'id' => 'si_existing',
        'quantity' => 3,
        'price' => (object) ['id' => 'price_seat'],
    ];
    $providerSub = (object) [
        'id' => 'sub_parent',
        'items' => (object) ['data' => [$line]],
        'latest_invoice' => (object) [
            'id' => 'in_paid',
            'status' => 'paid',
            'amount_due' => 2000,
        ],
    ];
    \Stripe\Subscription::$retrieveQueue = [$providerSub, $providerSub, $providerSub];
    $controller->index();

    $audits = billing_calls($models['audit'], 'insert');
    expect($controller->statusCode)->toBe(200)
        ->and(\Stripe\SubscriptionItem::$updateCalls)->toBe([[
            'id' => 'si_existing',
            'params' => [
                'quantity' => 5,
                'proration_behavior' => 'always_invoice',
                'payment_behavior' => 'error_if_incomplete',
            ],
        ]])
        ->and(billing_calls($models['item'], 'syncLineItemsFromStripeSubscription'))->toHaveCount(1)
        ->and($audits)->toHaveCount(1)
        ->and($audits[0]['args'][0])->toMatchArray([
            'status' => 'applied',
            'stripe_invoice_id' => 'in_paid',
            'failure_reason' => '',
        ]);
});

/**
 * Prerequisites:
 * - Stripe accepts the line update but the immediate invoice remains open with an amount due.
 *
 * Steps:
 * 1. Return the unpaid invoice from the provider double.
 * 2. Submit the confirmed seat change.
 * 3. Assert payment_required mapping, one failed audit, and no local line synchronization.
 */
test('Billing subscription change — unpaid invoice maps to payment required without local sync', function () {
    [$controller, $models] = billing_subscription_provider_fixture();
    $line = (object) [
        'id' => 'si_existing',
        'quantity' => 3,
        'price' => (object) ['id' => 'price_seat'],
    ];
    $applySub = (object) ['id' => 'sub_parent', 'items' => (object) ['data' => [$line]]];
    $unpaidSub = (object) [
        'id' => 'sub_parent',
        'latest_invoice' => (object) [
            'id' => 'in_open',
            'status' => 'open',
            'amount_due' => 2000,
            'hosted_invoice_url' => 'https://billing.test/invoice/in_open',
        ],
    ];
    \Stripe\Subscription::$retrieveQueue = [$applySub, $unpaidSub];
    $controller->index();

    $audits = billing_calls($models['audit'], 'insert');
    expect($controller->statusCode)->toBe(402)
        ->and($controller->json['data'])->toMatchArray([
            'error_code' => 'payment_required',
            'invoice_status' => 'open',
        ])
        ->and($audits)->toHaveCount(1)
        ->and($audits[0]['args'][0]['status'])->toBe('failed')
        ->and(billing_calls($models['item'], 'syncLineItemsFromStripeSubscription'))->toBe([]);
});

/**
 * Prerequisites:
 * - Stripe rejects an otherwise valid line mutation with an API error.
 *
 * Steps:
 * 1. Queue a deterministic provider exception at the subscription-item update.
 * 2. Submit the confirmed change.
 * 3. Assert stable provider-error status, one terminal audit, and no local sync.
 */
test('Billing subscription change — provider API error records one failed audit without local sync', function () {
    [$controller, $models] = billing_subscription_provider_fixture();
    $providerSub = (object) ['items' => (object) ['data' => [(object) [
        'id' => 'si_existing',
        'quantity' => 3,
        'price' => (object) ['id' => 'price_seat'],
    ]]]];
    \Stripe\Subscription::$retrieveQueue = [$providerSub];
    \Stripe\SubscriptionItem::$updateQueue = [
        new \Stripe\Exception\ApiErrorException('provider unavailable', 500, 'api_error'),
    ];
    $controller->index();

    $audits = billing_calls($models['audit'], 'insert');
    expect($controller->statusCode)->toBe(502)
        ->and($controller->json['error'])->not->toBe([])
        ->and($audits)->toHaveCount(1)
        ->and($audits[0]['args'][0])->toMatchArray([
            'status' => 'failed',
            'failure_reason' => 'provider unavailable',
        ])
        ->and(billing_calls($models['item'], 'syncLineItemsFromStripeSubscription'))->toBe([]);
});
