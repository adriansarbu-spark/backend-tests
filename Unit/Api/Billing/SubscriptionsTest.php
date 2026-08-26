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
 * @return array{0: TestableControllerPublicapiv1BillingSubscriptions, 1: BillingModelStub}
 */
function billing_subscriptions_fixture(?array $subscription): array
{
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => true]);
    $model = new BillingModelStub(['getActiveByCompanyAndIntervalGroup' => $subscription]);
    [$registry] = billing_registry(
        models: [
            'billing/entitlement_assignment' => $assignment,
            'billing/company_subscription' => $model,
        ],
        query: ['route' => 'publicapi/v1/billing/subscriptions/cancel'],
    );
    /** @var TestableControllerPublicapiv1BillingSubscriptions $controller */
    $controller = billing_controller(
        TestableControllerPublicapiv1BillingSubscriptions::class,
        $registry,
        billing_all_permissions(),
    );
    $controller->setBillingPost(['interval_group' => 'month']);

    return [$controller, $model];
}

/**
 * Prerequisites:
 * - A company admin owns an active mirrored monthly subscription.
 *
 * Steps:
 * 1. Request cancellation at period end.
 * 2. Assert the exact Stripe subscription and cancellation flag.
 * 3. Assert the local mirror is synchronized once with company ownership preserved.
 */
test('Billing subscription cancellation — owned active subscription is cancelled and mirrored once', function () {
    [$controller, $model] = billing_subscriptions_fixture([
        'subscription_id' => 61,
        'company_id' => 20,
        'stripe_subscription_id' => 'sub_owned',
        'stripe_customer_id' => 'cus_owned',
        'status' => 'active',
        'price_id' => 71,
        'quantity' => 2,
    ]);
    $controller->index();

    $upserts = billing_calls($model, 'upsertSubscription');
    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toBe(['ok' => true, 'cancel_at_period_end' => true])
        ->and(\Stripe\Subscription::$updateCalls)->toBe([[
            'id' => 'sub_owned',
            'params' => ['cancel_at_period_end' => true],
        ]])
        ->and($upserts)->toHaveCount(1)
        ->and($upserts[0]['args'][0])->toMatchArray([
            'company_id' => 20,
            'stripe_subscription_id' => 'sub_owned',
            'cancel_at_period_end' => true,
            'interval_group' => 'month',
        ]);
});

/**
 * Prerequisites:
 * - The active-company lookup cannot resolve an owned subscription.
 *
 * Steps:
 * 1. Submit a valid cancellation route and interval.
 * 2. Assert existence-hiding not-found behavior.
 * 3. Prove no Stripe call or local upsert occurs.
 */
test('Billing subscription cancellation — missing or foreign subscription stops before provider mutation', function () {
    [$controller, $model] = billing_subscriptions_fixture(null);
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['subscription_not_found'])
        ->and(\Stripe\Subscription::$updateCalls)->toBe([])
        ->and(billing_calls($model, 'upsertSubscription'))->toBe([]);
});

/**
 * Prerequisites:
 * - Stripe rejects cancellation of an otherwise owned subscription.
 *
 * Steps:
 * 1. Queue a deterministic provider failure.
 * 2. Request cancellation.
 * 3. Assert provider-error status and prove the local mirror is not changed.
 */
test('Billing subscription cancellation — provider failure does not update the local mirror', function () {
    [$controller, $model] = billing_subscriptions_fixture([
        'subscription_id' => 61,
        'company_id' => 20,
        'stripe_subscription_id' => 'sub_owned',
    ]);
    \Stripe\Subscription::$updateQueue = [new RuntimeException('provider unavailable')];
    $controller->index();

    expect($controller->statusCode)->toBe(502)
        ->and($controller->json['error'])->not->toBe([])
        ->and(billing_calls($model, 'upsertSubscription'))->toBe([]);
});
