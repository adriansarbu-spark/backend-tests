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
 * @param array<string, mixed>|object $post
 * @param array<string, object>      $overrides Optional model stubs plus `customer` (BillingCustomerStub).
 *
 * @return array{0: TestableControllerPublicapiv1BillingSubscriptionItems, 1: array<string, BillingModelStub>}
 */
function billing_subscription_items_fixture(array|object $post, string $action = 'preview', array $overrides = []): array
{
    $models = [
        'assignment' => $overrides['assignment'] ?? new BillingModelStub(['isRoleAdminForCompany' => true]),
        'price' => $overrides['price'] ?? new BillingModelStub([
            'getActiveCheckoutPriceRowByUuid' => [
                'price_id' => 71,
                'price_uuid' => '22222222-2222-4222-8222-222222222222',
                'stripe_price_id' => 'price_seat',
                'product_name' => 'Seat',
                'type' => 'recurring',
                'currency' => 'EUR',
                'billing_interval' => 'month',
                'unit_amount' => 15000,
            ],
        ]),
        'currency' => $overrides['currency'] ?? new BillingModelStub(['resolveBillingCurrency' => 'EUR']),
        'subscription' => $overrides['subscription'] ?? new BillingModelStub([
            'getActiveByCompanyAndIntervalGroup' => [
                'subscription_id' => 61,
                'company_id' => 20,
                'stripe_subscription_id' => 'sub_parent',
                'cancel_at_period_end' => false,
            ],
        ]),
        'item' => $overrides['item'] ?? new BillingModelStub([
            'getCancelPendingItemForSubscriptionAndPrice' => null,
        ]),
        'audit' => $overrides['audit'] ?? new BillingModelStub(['getDailyAppliedAmount' => 0]),
    ];
    $route = 'publicapi/v1/billing/subscription_items' . ($action === '' ? '' : '/' . $action);
    [$registry] = billing_registry(
        customer: $overrides['customer'] ?? null,
        models: [
            'billing/entitlement_assignment' => $models['assignment'],
            'billing/price' => $models['price'],
            'billing/customer_currency' => $models['currency'],
            'billing/company_subscription' => $models['subscription'],
            'billing/company_subscription_item' => $models['item'],
            'billing/subscription_change_audit' => $models['audit'],
        ],
        query: ['route' => $route],
    );
    /** @var TestableControllerPublicapiv1BillingSubscriptionItems $controller */
    $controller = billing_controller(
        TestableControllerPublicapiv1BillingSubscriptionItems::class,
        $registry,
        billing_all_permissions(),
    );
    $controller->setBillingPost($post);

    return [$controller, $models];
}

/**
 * Prerequisites:
 * - A company admin previews an active recurring seat price on the matching subscription stream.
 *
 * Steps:
 * 1. Submit a valid preview with an amount at the single-change TOTP threshold.
 * 2. Assert normalized interval, currency, amount, confirmation, and TOTP risk fields.
 * 3. Prove the preview makes no Stripe mutation.
 */
test('Billing subscription change — preview computes deterministic confirmation and TOTP risk', function () {
    [$controller] = billing_subscription_items_fixture([
        'price_uuid' => '22222222-2222-4222-8222-222222222222',
        'quantity' => 2,
        'interval_group' => 'month',
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toMatchArray([
            'billing_currency' => 'EUR',
            'interval_group' => 'month',
            'quantity' => 2,
            'recurring_delta_amount' => 30000,
            'confirm_required' => true,
            'totp_required' => true,
        ])
        ->and(\Stripe\Subscription::$updateCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - A subscription-item request fails one local price/subscription invariant.
 *
 * Steps:
 * 1. Resolve the add/preview context for malformed, mismatched, or cancel-pending state.
 * 2. Assert the stable application error.
 * 3. Prove no provider mutation occurs.
 */
test('Billing subscription change — local context rejects invalid price and subscription state before Stripe', function (
    array $post,
    array $overrides,
    int $expectedStatus,
    string $expectedError,
) {
    [$controller] = billing_subscription_items_fixture($post, 'preview', $overrides);
    $controller->index();

    expect($controller->statusCode)->toBe($expectedStatus)
        ->and($controller->json['error'])->toContain($expectedError)
        ->and(\Stripe\Subscription::$updateCalls)->toBe([]);
})->with([
    'invalid UUID' => [
        ['price_uuid' => 'not-a-uuid', 'quantity' => 1],
        [],
        400,
        'invalid_price_uuid',
    ],
    'one-time price' => [
        ['price_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 1],
        ['price' => new BillingModelStub(['getActiveCheckoutPriceRowByUuid' => [
            'stripe_price_id' => 'price_pack',
            'type' => 'one_time',
        ]])],
        400,
        'price_must_be_recurring',
    ],
    'currency mismatch' => [
        ['price_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 1],
        ['currency' => new BillingModelStub(['resolveBillingCurrency' => 'RON'])],
        400,
        'price_currency_not_allowed',
    ],
    'posted interval mismatch' => [
        ['price_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 1, 'interval_group' => 'year'],
        [],
        400,
        'price_billing_interval_mismatch',
    ],
    'subscription cancel pending' => [
        ['price_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 1],
        ['subscription' => new BillingModelStub(['getActiveByCompanyAndIntervalGroup' => [
            'subscription_id' => 61,
            'stripe_subscription_id' => 'sub_parent',
            'cancel_at_period_end' => true,
        ]])],
        409,
        'subscription_cancel_pending',
    ],
    'matching item cancel pending' => [
        ['price_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 1],
        ['item' => new BillingModelStub(['getCancelPendingItemForSubscriptionAndPrice' => [
            'subscription_item_uuid' => 'item-cancel-pending',
        ]])],
        409,
        'subscription_item_cancel_pending',
    ],
]);

/**
 * Prerequisites:
 * - A valid add-seat context is submitted without explicit confirmation.
 *
 * Steps:
 * 1. Submit the add action using an object payload.
 * 2. Assert HTTP 409 confirmation_required and the same preview contract.
 * 3. Prove neither Stripe nor the audit insert is called.
 */
test('Billing subscription change — add requires explicit confirmation before mutation', function () {
    [$controller, $models] = billing_subscription_items_fixture((object) [
        'price_uuid' => '22222222-2222-4222-8222-222222222222',
        'quantity' => 1,
        'confirm' => 'off',
    ], '');
    $controller->index();

    expect($controller->statusCode)->toBe(409)
        ->and($controller->json['error'])->toBe(['confirmation_required'])
        ->and($controller->json['data']['confirm_required'])->toBeTrue()
        ->and(\Stripe\Subscription::$updateCalls)->toBe([])
        ->and(billing_calls($models['audit'], 'insert'))->toBe([]);
});

/**
 * Prerequisites:
 * - Billing risk has crossed a TOTP threshold but no valid local proof is available.
 *
 * Steps:
 * 1. Verify missing code and code-without-enrollment payloads.
 * 2. Assert deterministic application codes.
 * 3. Assert no TOTP value is persisted in the subscription audit payload.
 */
test('Billing subscription change — TOTP prerequisites and audit payload remain deterministic and secret-free', function () {
    [$controller, $models] = billing_subscription_items_fixture([
        'price_uuid' => '22222222-2222-4222-8222-222222222222',
    ]);
    $missing = billing_invoke_private($controller, 'verifyBillingTotp', [[]]);
    $unenrolled = billing_invoke_private($controller, 'verifyBillingTotp', [['totp' => '123456']]);
    $context = [
        'price_uuid' => '22222222-2222-4222-8222-222222222222',
        'target_currency' => 'EUR',
        'quantity' => 2,
        'price_row' => ['price_id' => 71],
        'parent' => ['stripe_subscription_id' => 'sub_parent'],
    ];
    $risk = ['risk_amount' => 30000, 'daily_amount_before' => 1000];
    billing_invoke_private($controller, 'recordSubscriptionChangeAudit', [$context, $risk, true, false, 'failed', 'invalid_totp']);
    $auditPayload = billing_calls($models['audit'], 'insert')[0]['args'][0] ?? [];

    expect($missing)->toMatchArray(['ok' => false, 'status_code' => 422, 'error' => 'totp_required'])
        ->and($unenrolled)->toMatchArray(['ok' => false, 'status_code' => 403, 'error' => 'totp_not_configured'])
        ->and($auditPayload)->toMatchArray([
            'status' => 'failed',
            'failure_reason' => 'invalid_totp',
            'requires_totp' => true,
            'totp_verified' => false,
        ])
        ->and($auditPayload)->not->toHaveKey('totp')
        ->and($auditPayload)->not->toHaveKey('totp_code');
});

/**
 * Prerequisites:
 * - A company admin has TOTP enrolled. The billing TOTP gate is exercised in
 *   isolation (no Stripe session or line mutation).
 *
 * Steps:
 * 1. Generate a current code from the enrolled secret.
 * 2. Verify through both accepted body fields (`totp` and `totp_code`).
 * 3. Assert the gate succeeds, the lockout counter is cleared, one success
 *    audit is recorded per attempt, and neither the code nor the secret is stored.
 */
test('Billing subscription change — valid TOTP on a local mutation succeeds without exposing the secret', function () {
    $secret = 'JBSWY3DPEHPK3PXP';
    $code = (new \RobThree\Auth\TwoFactorAuth())->getCode($secret);
    [$controller] = billing_subscription_items_fixture(
        ['price_uuid' => '22222222-2222-4222-8222-222222222222'],
        'preview',
        ['customer' => new BillingCustomerStub(10, 20, 30, 'admin', '', '', $secret)],
    );

    $viaTotp = billing_invoke_private($controller, 'verifyBillingTotp', [['totp' => $code]]);
    $viaAlias = billing_invoke_private($controller, 'verifyBillingTotp', [['totp_code' => $code]]);
    $auditJson = json_encode($controller->totpAudits);

    expect($viaTotp)->toMatchArray(['ok' => true, 'status_code' => 200, 'error' => ''])
        ->and($viaAlias)->toMatchArray(['ok' => true, 'status_code' => 200, 'error' => ''])
        ->and($controller->totpFailClears)->toBe([10, 10])
        ->and($controller->totpAudits)->toHaveCount(2)
        ->and($controller->totpAudits[0])->toMatchArray([
            'customer_id' => 10,
            'context' => 'billing_subscription_change',
            'success' => true,
            'extra' => [],
        ])
        ->and($controller->totpAudits[1])->toMatchArray([
            'customer_id' => 10,
            'context' => 'billing_subscription_change',
            'success' => true,
            'extra' => [],
        ])
        ->and($auditJson)->not->toContain($code)
        ->and($auditJson)->not->toContain($secret)
        ->and(\Stripe\Subscription::$updateCalls)->toBe([])
        ->and(\Stripe\SubscriptionItem::$createCalls)->toBe([])
        ->and(\Stripe\SubscriptionItem::$updateCalls)->toBe([]);
});
