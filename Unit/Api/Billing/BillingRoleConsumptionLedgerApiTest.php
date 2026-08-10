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
 * @param array<string, mixed> $result
 *
 * @return array{0: TestableControllerPublicapiv1BillingLedgerRoleConsumption, 1: BillingModelStub, 2: BillingLoadStub}
 */
function billing_consumption_ledger_fixture(array $query = [], array $result = ['items' => [], 'total' => 0]): array
{
    $model = new BillingModelStub(['listUsageLedgerForRolePaged' => $result]);
    [$registry, $load] = billing_registry(
        models: ['billing/role_entitlement_usage' => $model],
        query: $query,
    );
    /** @var TestableControllerPublicapiv1BillingLedgerRoleConsumption $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingLedgerRoleConsumption::class, $registry, billing_all_permissions());

    return [$controller, $model, $load];
}

/**
 * Prerequisites:
 * - One required identity/context value is missing.
 *
 * Steps:
 * 1. Request the consumption ledger.
 * 2. Assert the exact status and error with empty data and no model access.
 */
test('Billing consumption ledger — authentication company and role context are required', function (
    BillingCustomerStub $customer,
    int $status,
    string $error,
) {
    [$registry, $load] = billing_registry($customer);
    $controller = billing_controller(TestableControllerPublicapiv1BillingLedgerRoleConsumption::class, $registry, billing_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toBe([$error])
        ->and($controller->json['data'])->toBe([])
        ->and($load->loadedModels)->toBe([]);
})->with([
    [new BillingCustomerStub(0, 20, 30), 401, 'authentication_required'],
    [new BillingCustomerStub(10, 0, 30), 400, 'billing_ledger_no_company_context'],
    [new BillingCustomerStub(10, 20, 0), 400, 'billing_ledger_no_role_context'],
]);

/**
 * Prerequisites:
 * - Either the method is unsupported or GET permission is absent.
 *
 * Steps:
 * 1. Request the consumption ledger.
 * 2. Assert rejection occurs without querying usage.
 */
test('Billing consumption ledger — method and permission failures never query usage', function (
    string $method,
    array $permissions,
    int $status,
) {
    billing_set_method($method);
    [$controller, $model] = billing_consumption_ledger_fixture();
    $controller->permission = (object) $permissions;
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($model->calls)->toBe([]);
})->with([
    ['POST', billing_all_permissions(), 405],
    ['GET', ['get' => [], 'post' => []], 403],
]);

/**
 * Prerequisites:
 * - Valid optional pagination values.
 *
 * Steps:
 * 1. Request the consumption ledger.
 * 2. Assert defaults and min/max clamping reach the model.
 */
test('Billing consumption ledger — pagination defaults and clamps', function (
    array $query,
    int $page,
    int $perPage,
) {
    [$controller, $model] = billing_consumption_ledger_fixture($query);
    $controller->index();

    expect(billing_calls($model, 'listUsageLedgerForRolePaged')[0]['args'])->toBe([
        30, null, null, null, 'occurred_at', 'DESC', $page, $perPage,
    ]);
})->with([
    [[], 1, 20],
    [['page' => -1, 'per_page' => 0], 1, 1],
    [['page' => 3, 'per_page' => 101], 3, 100],
]);

/**
 * Prerequisites:
 * - Valid sort, order, and search inputs.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert allowed sort values, uppercased order, and trimmed search reach the model.
 */
test('Billing consumption ledger — supported filters are normalized', function (string $sort) {
    [$controller, $model] = billing_consumption_ledger_fixture([
        'sort' => $sort,
        'order' => ' asc ',
        'search' => ' invoice ',
    ]);
    $controller->index();

    expect(billing_calls($model, 'listUsageLedgerForRolePaged')[0]['args'])->toBe([
        30, 'invoice', null, null, $sort, 'ASC', 1, 20,
    ]);
})->with(['occurred_at', 'entitlement_code', 'quantity', 'reference_type']);

/**
 * Prerequisites:
 * - Blank search text.
 *
 * Steps:
 * 1. Request the consumption ledger.
 * 2. Assert the model receives null rather than an empty search string.
 */
test('Billing consumption ledger — blank search becomes null', function () {
    [$controller, $model] = billing_consumption_ledger_fixture(['search' => '  ']);
    $controller->index();

    expect(billing_calls($model, 'listUsageLedgerForRolePaged')[0]['args'][1])->toBeNull();
});

/**
 * Prerequisites:
 * - Exactly one occurred-at range bound is present.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert HTTP 422 range_incomplete, empty data, and no model call.
 */
test('Billing consumption ledger — an occurred-at range requires both bounds', function (array $query) {
    [$controller, $model] = billing_consumption_ledger_fixture($query);
    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toBe(['billing_ledger_consumption_occurred_range_incomplete'])
        ->and($controller->json['data'])->toBe([])
        ->and($model->calls)->toBe([]);
})->with([
    [['occurred_start' => '2026-01-01T00:00:00Z']],
    [['occurred_end' => '2026-02-01T00:00:00Z']],
]);

/**
 * Prerequisites:
 * - A complete occurred-at range with one malformed bound.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert HTTP 422 invalid range before model access.
 */
test('Billing consumption ledger — malformed complete ranges are rejected', function (string $start, string $end) {
    [$controller, $model] = billing_consumption_ledger_fixture([
        'occurred_start' => $start,
        'occurred_end' => $end,
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toBe(['billing_ledger_consumption_invalid_occurred_range'])
        ->and($model->calls)->toBe([]);
})->with([
    ['bad', '2026-02-01T00:00:00Z'],
    ['2026-01-01T00:00:00Z', 'bad'],
]);

/**
 * Prerequisites:
 * - A complete occurred-at range with timezone offsets.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert exact SQL UTC bounds reach the usage model.
 */
test('Billing consumption ledger — complete ranges normalize to UTC', function () {
    [$controller, $model] = billing_consumption_ledger_fixture([
        'occurred_start' => '2026-01-01T02:00:00+02:00',
        'occurred_end' => '2026-02-01T02:00:00+02:00',
    ]);
    $controller->index();

    expect(billing_calls($model, 'listUsageLedgerForRolePaged')[0]['args'][2])->toBe('2026-01-01 00:00:00')
        ->and(billing_calls($model, 'listUsageLedgerForRolePaged')[0]['args'][3])->toBe('2026-02-01 00:00:00');
});

/**
 * Prerequisites:
 * - Equal or reversed but syntactically valid range bounds.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Characterize that the model still receives both values.
 */
test('Billing consumption ledger — equal and reversed valid bounds currently reach the model', function (
    string $start,
    string $end,
) {
    [$controller, $model] = billing_consumption_ledger_fixture([
        'occurred_start' => $start,
        'occurred_end' => $end,
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and(billing_calls($model, 'listUsageLedgerForRolePaged'))->toHaveCount(1);
})->with([
    ['2026-02-01T00:00:00Z', '2026-02-01T00:00:00Z'],
    ['2026-03-01T00:00:00Z', '2026-02-01T00:00:00Z'],
]);

/**
 * Prerequisites:
 * - One sort or order query value is outside its allowlist.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert HTTP 422, exact error, empty data, and no usage query.
 */
test('Billing consumption ledger — invalid sort and order are rejected before model access', function (
    array $query,
    string $error,
) {
    [$controller, $model] = billing_consumption_ledger_fixture($query);
    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toBe([$error])
        ->and($controller->json['data'])->toBe([])
        ->and($model->calls)->toBe([]);
})->with([
    [['sort' => 'secret'], 'billing_ledger_consumption_invalid_sort'],
    [['order' => 'sideways'], 'billing_ledger_invalid_order'],
]);

/**
 * Prerequisites:
 * - The model returns usage rows with present and absent reference types.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert integer quantities, Zulu timestamps, nullable references, and pagination.
 */
test('Billing consumption ledger — rows and pagination use the public response contract', function () {
    [$controller] = billing_consumption_ledger_fixture(['per_page' => 2], [
        'items' => [
            [
                'entitlement_code' => 'documents',
                'quantity' => '2',
                'occurred_at' => '2026-01-02 03:04:05',
                'reference_type' => 'document',
            ],
            [
                'entitlement_code' => 'signatures',
                'quantity' => 1,
                'occurred_at' => '2026-01-03 03:04:05',
                'reference_type' => '',
            ],
        ],
        'total' => '5',
    ]);
    $controller->index();

    expect($controller->json['data'][0])->toBe([
        'entitlement_code' => 'documents',
        'quantity' => 2,
        'occurred_at' => '2026-01-02T03:04:05Z',
        'reference_type' => 'document',
    ])->and($controller->json['data'][1]['reference_type'])->toBeNull()
        ->and($controller->json['pagination'])->toBe([
            'page' => 1,
            'per_page' => 2,
            'total' => 5,
            'total_pages' => 3,
        ]);
});

