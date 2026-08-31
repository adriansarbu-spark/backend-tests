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
 * @param list<array<string, mixed>> $dbRows
 * @param array<string, object>      $overrides
 *
 * @return array{0: TestableControllerPublicapiv1BillingMe, 1: array<string, BillingModelStub>, 2: BillingDbStub, 3: BillingLoadStub}
 */
function billing_me_fixture(array $dbRows = [], array $overrides = []): array
{
    $models = [
        'resolver' => $overrides['resolver'] ?? new BillingModelStub([
            'getSnapshotForCompany' => [
                'entitlements' => [['code' => 'zeta', 'unit' => 'credits', 'limit_period' => 'month']],
            ],
            'enrichEntitlementMetaForRole' => static fn (array $meta): array => $meta,
        ]),
        'grant' => $overrides['grant'] ?? new BillingModelStub([
            'getActiveGrantTotalsByRole' => ['zeta' => '5', 'alpha' => '10'],
            'computeFifoAvailableRemaining' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 7 : 2,
            'getActiveGrantTotalsByRolePooled' => ['alpha' => 1, 'pooled_only' => 1],
            'computeFifoAvailableRemainingPooled' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 12 : 4,
            'getPooledActiveRoleIds' => [30],
        ]),
        'usage' => $overrides['usage'] ?? new BillingModelStub([
            'sumForRoleEntitlementAllTime' => static fn (int $roleId, string $code): int => 3,
        ]),
        'assignment' => $overrides['assignment'] ?? new BillingModelStub([
            'roleHasAuthenticationRights' => true,
            'rolesWithAuthenticationRights' => [],
        ]),
        'sponsorship' => $overrides['sponsorship'] ?? new BillingModelStub([
            'getActiveSponsorshipsForRole' => [],
        ]),
        'price' => $overrides['price'] ?? new BillingModelStub([
            'getPriceProductDisplayRow' => static fn (int $priceId): array => [
                'product_name' => 'Team',
                'price_minor' => 1999,
                'currency' => 'EUR',
            ],
        ]),
    ];
    $db = new BillingDbStub($dbRows);
    [$registry, $load] = billing_registry(
        models: [
            'billing/entitlement_resolver' => $models['resolver'],
            'billing/role_entitlement_grant' => $models['grant'],
            'billing/role_entitlement_usage' => $models['usage'],
            'billing/entitlement_assignment' => $models['assignment'],
            'partner/sponsorship' => $models['sponsorship'],
            'billing/price' => $models['price'],
        ],
        db: $db,
    );
    /** @var TestableControllerPublicapiv1BillingMe $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingMe::class, $registry, billing_all_permissions());

    return [$controller, $models, $db, $load];
}

/**
 * Prerequisites:
 * - The caller is unauthenticated or lacks company/role context.
 *
 * Steps:
 * 1. Request the current-role billing snapshot.
 * 2. Assert the exact status/error and no model or DB access.
 */
test('Billing me — authentication company and role context are required', function (
    BillingCustomerStub $customer,
    int $status,
    string $error,
) {
    $db = new BillingDbStub();
    [$registry, $load] = billing_registry($customer, db: $db);
    $controller = billing_controller(TestableControllerPublicapiv1BillingMe::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toBe([$error])
        ->and($load->loadedModels)->toBe([])
        ->and($db->queries)->toBe([]);
})->with([
    [new BillingCustomerStub(0, 20, 30), 401, 'Authentication required'],
    [new BillingCustomerStub(10, 0, 30), 400, 'No company context'],
    [new BillingCustomerStub(10, 20, 0), 400, 'No company context'],
]);

/**
 * Prerequisites:
 * - The caller uses an unsupported method or lacks GET permission.
 *
 * Steps:
 * 1. Request the snapshot.
 * 2. Assert rejection occurs before model or DB access.
 */
test('Billing me — method and permission failures precede snapshot lookup', function (
    string $method,
    array $permissions,
    int $status,
) {
    billing_set_method($method);
    [$controller, , $db, $load] = billing_me_fixture();
    $controller->permission = (object) $permissions;
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($load->loadedModels)->toBe([])
        ->and($db->queries)->toBe([]);
    if ($status === 405) {
        expect($controller->allowedHeaders)->toBe(['GET', 'OPTIONS']);
    }
})->with([
    ['POST', billing_all_permissions(), 405],
    ['GET', ['get' => [], 'post' => []], 403],
]);

/**
 * Prerequisites:
 * - Deterministic entitlement limits, metadata, usage, and authentication rights.
 *
 * Steps:
 * 1. Request the current-role snapshot.
 * 2. Assert entitlements are sorted and mapped with defaults and bool rights.
 * 3. Assert cross-role spendability includes pooled-only codes.
 */
test('Billing me — effective entitlements and cross-role spendability are stable', function (bool $hasAuthRights) {
    $assignment = new BillingModelStub([
        'roleHasAuthenticationRights' => $hasAuthRights,
        'rolesWithAuthenticationRights' => [],
    ]);
    [$controller] = billing_me_fixture(overrides: ['assignment' => $assignment]);
    $controller->index();

    $items = $controller->json['data']['entitlements'];
    expect(array_column($items, 'code'))->toBe(['alpha', 'authentication_rights', 'zeta'])
        ->and($items[0])->toBe([
            'code' => 'alpha',
            'unit' => 'count',
            'limit' => 10,
            'limit_period' => null,
            'used' => 3,
            'remaining' => 7,
        ])
        ->and($items[1])->toBe([
            'code' => 'authentication_rights',
            'unit' => 'bool',
            'limit' => 1,
            'limit_period' => null,
            'used' => 0,
            'remaining' => $hasAuthRights ? 1 : 0,
        ])
        ->and($items[2]['unit'])->toBe('credits')
        ->and($items[2]['limit_period'])->toBe('month')
        ->and($controller->json['data']['spendable_across_roles'])->toBe([
            'alpha' => ['current_role' => 7, 'other_roles' => 5, 'total' => 12],
            'pooled_only' => ['current_role' => 2, 'other_roles' => 2, 'total' => 4],
            'authentication_rights' => [
                'current_role' => $hasAuthRights ? 1 : 0,
                'other_roles' => 0,
                'total' => $hasAuthRights ? 1 : 0,
            ],
        ]);
})->with([true, false]);

/**
 * Prerequisites:
 * - A role has a local assigned-plan row with item and parent fallback values.
 *
 * Steps:
 * 1. Request the current-role snapshot.
 * 2. Assert item values win, display metadata is merged, and dates become Zulu.
 * 3. Assert current_plan derives from the first assignment period.
 */
test('Billing me — assigned plan rows map item-preferred values and current plan period', function () {
    $rows = [[
        'subscription_id' => '61',
        'status' => 'active',
        'quantity' => '4',
        'subscription_price_id' => '70',
        'stripe_subscription_id' => 'sub_local_1',
        'stripe_customer_id' => '',
        'subscription_period_start' => '2026-01-01 00:00:00',
        'subscription_period_end' => '2027-01-01 00:00:00',
        'cancel_at_period_end' => '1',
        'subscription_item_id' => '51',
        'subscription_item_uuid' => 'item-1',
        'item_price_id' => '71',
        'item_quantity' => '2',
        'item_period_start' => '2026-02-01 00:00:00',
        'item_period_end' => '2026-12-01 00:00:00',
        'assignment_id' => '91',
        'seat_slot_uuid' => 'slot-1',
        'assigned_at' => '2026-02-02 03:04:05',
    ]];
    [$controller, $models, $db] = billing_me_fixture($rows);
    $controller->index();
    $entry = $controller->json['data']['subscriptions'][0];

    expect($db->queries)->toHaveCount(1)
        ->and($db->queries[0])->toContain("sa.`company_id` = '20'")
        ->and($db->queries[0])->toContain("sa.`customer_role_id` = '30'")
        ->and(billing_calls($models['price'], 'getPriceProductDisplayRow')[0]['args'])->toBe([71])
        ->and($entry)->toMatchArray([
            'subscription_id' => 61,
            'subscription_item_id' => 51,
            'subscription_item_uuid' => 'item-1',
            'assignment_id' => 91,
            'seat_slot_uuid' => 'slot-1',
            'status' => 'active',
            'quantity' => 2,
            'price_id' => 71,
            'stripe_subscription_id' => 'sub_local_1',
            'stripe_customer_id' => null,
            'current_period_start' => '2026-02-01T00:00:00Z',
            'current_period_end' => '2026-12-01T00:00:00Z',
            'cancel_at_period_end' => true,
            'assigned_at' => '2026-02-02T03:04:05Z',
            'product_name' => 'Team',
            'price_minor' => 1999,
            'currency' => 'EUR',
        ])->and($controller->json['data']['current_plan'])->toBe([
            'billing_period_starts_at' => '2026-02-01T00:00:00Z',
            'billing_period_ends_at' => '2026-12-01T00:00:00Z',
        ]);
});

/**
 * Prerequisites:
 * - A role has an assignment row without item price, quantity, or period values.
 *
 * Steps:
 * 1. Request the snapshot.
 * 2. Assert parent values are used and optional identifiers/dates remain nullable.
 */
test('Billing me — assigned plan rows safely fall back to parent and nullable values', function () {
    $rows = [[
        'subscription_id' => 61,
        'status' => 'trialing',
        'quantity' => 4,
        'subscription_price_id' => 70,
        'stripe_subscription_id' => 'sub_1',
        'stripe_customer_id' => null,
        'subscription_period_start' => '2026-01-01 00:00:00',
        'subscription_period_end' => '2027-01-01 00:00:00',
        'cancel_at_period_end' => 0,
        'subscription_item_id' => null,
        'subscription_item_uuid' => null,
        'item_price_id' => null,
        'item_quantity' => null,
        'item_period_start' => null,
        'item_period_end' => null,
        'assignment_id' => 91,
        'seat_slot_uuid' => null,
        'assigned_at' => null,
    ]];
    [$controller, $models] = billing_me_fixture($rows);
    $controller->index();
    $entry = $controller->json['data']['subscriptions'][0];

    expect(billing_calls($models['price'], 'getPriceProductDisplayRow')[0]['args'])->toBe([70])
        ->and($entry['quantity'])->toBe(4)
        ->and($entry['price_id'])->toBe(70)
        ->and($entry['subscription_item_id'])->toBeNull()
        ->and($entry['subscription_item_uuid'])->toBeNull()
        ->and($entry['seat_slot_uuid'])->toBeNull()
        ->and($entry['assigned_at'])->toBeNull()
        ->and($entry['current_period_start'])->toBe('2026-01-01T00:00:00Z');
});

/**
 * Prerequisites:
 * - No role grants and no local assignment rows exist.
 *
 * Steps:
 * 1. Request the current-role snapshot.
 * 2. Assert stable IDs, synthetic rights, empty limits/subscriptions, authentication spendability, and null current plan.
 */
test('Billing me — an empty role still receives a stable snapshot', function () {
    $resolver = new BillingModelStub([
        'getSnapshotForCompany' => ['entitlements' => []],
        'enrichEntitlementMetaForRole' => [],
    ]);
    $grant = new BillingModelStub([
        'getActiveGrantTotalsByRole' => [],
        'getActiveGrantTotalsByRolePooled' => [],
        'getPooledActiveRoleIds' => [30],
    ]);
    $assignment = new BillingModelStub([
        'roleHasAuthenticationRights' => false,
        'rolesWithAuthenticationRights' => [],
    ]);
    [$controller] = billing_me_fixture(overrides: [
        'resolver' => $resolver,
        'grant' => $grant,
        'assignment' => $assignment,
    ]);
    $controller->index();

    expect($controller->json['data'])->toBe([
        'company_id' => 20,
        'customer_role_id' => 30,
        'entitlements' => [[
            'code' => 'authentication_rights',
            'unit' => 'bool',
            'limit' => 1,
            'limit_period' => null,
            'used' => 0,
            'remaining' => 0,
        ]],
        'limits' => [],
        'spendable_across_roles' => [
            'authentication_rights' => [
                'current_role' => 0,
                'other_roles' => 0,
                'total' => 0,
            ],
        ],
        'subscriptions' => [],
        'current_plan' => null,
        'sponsorships' => [],
    ]);
});

