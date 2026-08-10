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
 * @param array<string, mixed> $query
 * @param array<string, mixed> $snapshot
 *
 * @return array{0: TestableControllerPublicapiv1BillingEntitlements, 1: BillingModelStub, 2: BillingModelStub, 3: BillingModelStub, 4: BillingLoadStub}
 */
function billing_entitlements_fixture(array $query = [], array $snapshot = []): array
{
    $snapshot = $snapshot ?: [
        'current_plan' => null,
        'subscriptions' => [],
        'limits' => [],
        'entitlements' => [
            ['code' => 'zeta', 'unit' => 'credits', 'limit_period' => 'month'],
        ],
    ];
    $resolver = new BillingModelStub([
        'getSnapshotForCompany' => $snapshot,
        'enrichEntitlementMetaForRole' => static fn (array $meta): array => $meta,
    ]);
    $grant = new BillingModelStub([
        'getActiveGrantTotalsByRole' => ['zeta' => '5', 'alpha' => '10'],
        'computeFifoAvailableRemaining' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 7 : 2,
        'getActiveGrantTotalsByRolePooled' => ['alpha' => 10, 'pooled_only' => 4],
        'computeFifoAvailableRemainingPooled' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 12 : 4,
    ]);
    $usage = new BillingModelStub([
        'sumForRoleEntitlementAllTime' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 3 : 3,
        'sumForRoleEntitlementBetween' => static fn (int $roleId, string $code): int => $code === 'alpha' ? 1 : 2,
    ]);
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => true]);
    [$registry, $load] = billing_registry(
        models: [
            'billing/entitlement_resolver' => $resolver,
            'billing/role_entitlement_grant' => $grant,
            'billing/role_entitlement_usage' => $usage,
            'billing/entitlement_assignment' => $assignment,
        ],
        query: $query,
    );
    /** @var TestableControllerPublicapiv1BillingEntitlements $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $registry, billing_all_permissions());

    return [$controller, $resolver, $grant, $usage, $load];
}

/**
 * Prerequisites:
 * - An unauthenticated caller and isolated model doubles.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert HTTP 401 and no resolver access.
 */
test('Billing entitlements — authentication is required', function () {
    [$registry, $load] = billing_registry(new BillingCustomerStub(0, 20, 30));
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['Authentication required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated caller using an unsupported method.
 *
 * Steps:
 * 1. Call role entitlements with POST.
 * 2. Assert HTTP 405, exact allowed methods, and no resolver access.
 */
test('Billing entitlements — only GET is accepted', function () {
    billing_set_method('POST');
    [$registry, $load] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'OPTIONS'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated caller without company context.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert HTTP 400 before permissions or model access.
 */
test('Billing entitlements — company context is required', function () {
    [$registry, $load] = billing_registry(new BillingCustomerStub(10, 0, 30));
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $registry);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'][0])->toContain('No company context')
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated company role without route permission.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert the permission response occurs before models are loaded.
 */
test('Billing entitlements — route permission is enforced before resolution', function () {
    [$registry, $load] = billing_registry();
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $registry);
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Resolver metadata and role grant/usage totals are deterministic.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert entries are sorted, typed, and combine limit, usage, remaining, and metadata.
 * 3. Assert a code without metadata receives stable defaults.
 */
test('Billing entitlements — role limits usage remaining and metadata form a sorted snapshot', function () {
    [$controller] = billing_entitlements_fixture();
    $controller->index();

    $items = $controller->json['data']['entitlements'];
    expect(array_column($items, 'code'))->toBe(['alpha', 'zeta'])
        ->and($items[0])->toBe([
            'code' => 'alpha',
            'unit' => 'count',
            'limit' => 10,
            'limit_period' => null,
            'used' => 3,
            'remaining' => 7,
        ])
        ->and($items[1])->toBe([
            'code' => 'zeta',
            'unit' => 'credits',
            'limit' => 5,
            'limit_period' => 'month',
            'used' => 3,
            'remaining' => 2,
        ])
        ->and($controller->json['data']['limits'])->toBe(['zeta' => '5', 'alpha' => '10'])
        ->and($controller->json['data'])->not->toHaveKey('company_entitlement_pool')
        ->and($controller->json['data'])->not->toHaveKey('usage_period');
});

/**
 * Prerequisites:
 * - A complete ISO-8601 usage period with timezone offsets.
 *
 * Steps:
 * 1. Request role entitlements for that period.
 * 2. Assert UTC SQL bounds reach both resolver and usage models.
 * 3. Assert period usage and normalized bounds appear in the response.
 */
test('Billing entitlements — a complete usage period is normalized and adds period usage', function () {
    [$controller, $resolver, , $usage] = billing_entitlements_fixture([
        'usage_period_start' => '2026-01-01T02:00:00+02:00',
        'usage_period_end' => '2026-02-01T02:00:00+02:00',
    ]);
    $controller->index();

    expect(billing_calls($resolver, 'getSnapshotForCompany')[0]['args'])->toBe([
        20,
        '2026-01-01 00:00:00',
        '2026-02-01 00:00:00',
    ])->and(billing_calls($usage, 'sumForRoleEntitlementBetween'))->toHaveCount(2)
        ->and($controller->json['data']['usage_period'])->toBe([
            'start' => '2026-01-01 00:00:00',
            'end' => '2026-02-01 00:00:00',
        ])
        ->and($controller->json['data']['entitlements'][0]['used_in_period'])->toBe(1);
});

/**
 * Prerequisites:
 * - Both usage-period values are present but one is malformed.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert HTTP 400 and no snapshot lookup.
 */
test('Billing entitlements — malformed complete periods are rejected before lookup', function (string $start, string $end) {
    [$controller, $resolver] = billing_entitlements_fixture([
        'usage_period_start' => $start,
        'usage_period_end' => $end,
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['Invalid usage_period_start or usage_period_end'])
        ->and($resolver->calls)->toBe([]);
})->with([
    ['not-a-date', '2026-02-01T00:00:00Z'],
    ['2026-01-01T00:00:00Z', 'not-a-date'],
]);

/**
 * Prerequisites:
 * - Only one usage-period bound is supplied.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Characterize the current all-time behavior: both resolver bounds are null and no period fields appear.
 */
test('Billing entitlements — a one-sided period is currently ignored', function (array $query) {
    [$controller, $resolver, , $usage] = billing_entitlements_fixture($query);
    $controller->index();

    expect(billing_calls($resolver, 'getSnapshotForCompany')[0]['args'])->toBe([20, null, null])
        ->and(billing_calls($usage, 'sumForRoleEntitlementBetween'))->toBe([])
        ->and($controller->json['data'])->not->toHaveKey('usage_period');
})->with([
    [['usage_period_start' => '2026-01-01T00:00:00Z']],
    [['usage_period_end' => '2026-02-01T00:00:00Z']],
]);

/**
 * Prerequisites:
 * - Syntactically valid period bounds that are equal or reversed.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Characterize that chronological ordering is not currently rejected.
 */
test('Billing entitlements — equal and reversed valid period bounds reach the model', function (string $start, string $end) {
    [$controller, $resolver] = billing_entitlements_fixture([
        'usage_period_start' => $start,
        'usage_period_end' => $end,
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and(billing_calls($resolver, 'getSnapshotForCompany')[0]['args'][1])->not->toBeNull()
        ->and(billing_calls($resolver, 'getSnapshotForCompany')[0]['args'][2])->not->toBeNull();
})->with([
    ['2026-02-01T00:00:00Z', '2026-02-01T00:00:00Z'],
    ['2026-03-01T00:00:00Z', '2026-02-01T00:00:00Z'],
]);

/**
 * Prerequisites:
 * - Role-owned and pooled-only entitlement codes with deterministic availability.
 *
 * Steps:
 * 1. Request role entitlements.
 * 2. Assert current, other-role, and total spendability for every pooled code.
 */
test('Billing entitlements — cross-role spendability includes codes owned only by other roles', function () {
    [$controller] = billing_entitlements_fixture();
    $controller->index();

    expect($controller->json['data']['spendable_across_roles'])->toBe([
        'alpha' => ['current_role' => 7, 'other_roles' => 5, 'total' => 12],
        'pooled_only' => ['current_role' => 2, 'other_roles' => 2, 'total' => 4],
    ]);
});

/**
 * Prerequisites:
 * - A non-admin asks to include the company entitlement pool.
 *
 * Steps:
 * 1. Request the pool with each documented truthy value.
 * 2. Assert HTTP 403 and no pool data is exposed.
 */
test('Billing entitlements — every documented truthy pool value requires admin', function (mixed $truthy) {
    [$controller, , , , $load] = billing_entitlements_fixture([
        'include_company_entitlement_pool' => $truthy,
    ]);
    $registry = (new ReflectionObject($controller))->getParentClass();
    unset($registry);

    // Replace the already-registered authorization model response.
    $assignment = new BillingModelStub(['isRoleAdminForCompany' => false]);
    [$newRegistry] = billing_registry(
        models: [
            'billing/entitlement_resolver' => new BillingModelStub([
                'getSnapshotForCompany' => [
                    'current_plan' => null,
                    'subscriptions' => [],
                    'limits' => [],
                    'entitlements' => [],
                ],
                'enrichEntitlementMetaForRole' => [],
            ]),
            'billing/role_entitlement_grant' => new BillingModelStub([
                'getActiveGrantTotalsByRole' => [],
                'getActiveGrantTotalsByRolePooled' => [],
            ]),
            'billing/role_entitlement_usage' => new BillingModelStub(),
            'billing/entitlement_assignment' => $assignment,
        ],
        query: ['include_company_entitlement_pool' => $truthy],
    );
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlements::class, $newRegistry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['Admin role required'])
        ->and($controller->json['data'])->toBe([])
        ->and(billing_calls($assignment, 'isRoleAdminForCompany')[0]['args'])->toBe([30, 20]);
})->with(['1', 1, true, 'true']);

/**
 * Prerequisites:
 * - An admin requests company-pool reconciliation data.
 *
 * Steps:
 * 1. Request role entitlements with pool inclusion.
 * 2. Assert company limits and entitlements are added without replacing role values.
 */
test('Billing entitlements — admin can include company pool without replacing role data', function () {
    $snapshot = [
        'current_plan' => ['name' => 'Company'],
        'subscriptions' => [['id' => 1]],
        'limits' => ['documents' => 100],
        'entitlements' => [['code' => 'documents', 'remaining' => 80]],
    ];
    [$controller] = billing_entitlements_fixture(
        ['include_company_entitlement_pool' => '1'],
        $snapshot,
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['company_entitlement_pool'])->toBe([
            'limits' => ['documents' => 100],
            'entitlements' => [['code' => 'documents', 'remaining' => 80]],
        ])
        ->and($controller->json['data']['limits'])->toBe(['zeta' => '5', 'alpha' => '10']);
});

