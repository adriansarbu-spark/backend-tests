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
 * @return array{0: TestableControllerPublicapiv1BillingLedgerRoleGrants, 1: BillingModelStub, 2: BillingLoadStub}
 */
function billing_grant_ledger_fixture(array $query = [], array $result = ['items' => [], 'total' => 0]): array
{
    $model = new BillingModelStub(['listGrantsLedgerForRolePaged' => $result]);
    [$registry, $load] = billing_registry(
        models: ['billing/role_entitlement_grant' => $model],
        query: $query,
    );
    /** @var TestableControllerPublicapiv1BillingLedgerRoleGrants $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingLedgerRoleGrants::class, $registry, billing_all_permissions());

    return [$controller, $model, $load];
}

/**
 * Prerequisites:
 * - One required identity/context value is missing.
 *
 * Steps:
 * 1. Request the grant ledger.
 * 2. Assert the exact status and error with empty data and no model access.
 */
test('Billing grant ledger — authentication company and role context are required', function (
    BillingCustomerStub $customer,
    int $status,
    string $error,
) {
    [$registry, $load] = billing_registry($customer);
    $controller = billing_controller(TestableControllerPublicapiv1BillingLedgerRoleGrants::class, $registry, billing_all_permissions());
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
 * 1. Request the grant ledger.
 * 2. Assert the request is rejected and the ledger model is untouched.
 */
test('Billing grant ledger — method and permission failures never query ledger data', function (
    string $method,
    array $permissions,
    int $status,
    string $error,
) {
    billing_set_method($method);
    [$controller, $model] = billing_grant_ledger_fixture();
    $controller->permission = (object) $permissions;
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toBe([$error])
        ->and($model->calls)->toBe([]);
})->with([
    ['POST', billing_all_permissions(), 405, 'method_not_allowed'],
    ['GET', ['get' => [], 'post' => []], 403, 'access_denied'],
]);

/**
 * Prerequisites:
 * - A valid role ledger request with optional pagination values.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert defaults and boundary clamping reach the model exactly.
 */
test('Billing grant ledger — pagination defaults and clamps to supported bounds', function (
    array $query,
    int $page,
    int $perPage,
) {
    [$controller, $model] = billing_grant_ledger_fixture($query);
    $controller->index();

    expect(billing_calls($model, 'listGrantsLedgerForRolePaged')[0]['args'])->toBe([
        30, null, 'all', 'expires_at', 'DESC', $page, $perPage,
    ])->and($controller->json['pagination']['page'])->toBe($page)
        ->and($controller->json['pagination']['per_page'])->toBe($perPage);
})->with([
    [[], 1, 20],
    [['page' => -5, 'per_page' => 0], 1, 1],
    [['page' => 4, 'per_page' => 999], 4, 100],
]);

/**
 * Prerequisites:
 * - Valid sort, order, state, and search query values.
 *
 * Steps:
 * 1. Request the grant ledger.
 * 2. Assert sort/state are accepted, order is uppercased, and search is trimmed.
 */
test('Billing grant ledger — allowed filters are normalized and forwarded', function (string $sort, string $state) {
    [$controller, $model] = billing_grant_ledger_fixture([
        'sort' => $sort,
        'order' => ' asc ',
        'state' => $state,
        'search' => ' docs ',
    ]);
    $controller->index();

    expect(billing_calls($model, 'listGrantsLedgerForRolePaged')[0]['args'])->toBe([
        30, 'docs', $state, $sort, 'ASC', 1, 20,
    ]);
})->with([
    ['entitlement_code', 'all'],
    ['quantity', 'active'],
    ['starts_at', 'expired'],
    ['expires_at', 'future'],
]);

/**
 * Prerequisites:
 * - Blank search and state query values.
 *
 * Steps:
 * 1. Request the grant ledger.
 * 2. Assert blank search becomes null and blank state becomes all.
 */
test('Billing grant ledger — blank search and state use stable defaults', function () {
    [$controller, $model] = billing_grant_ledger_fixture(['search' => ' ', 'state' => '']);
    $controller->index();

    expect(billing_calls($model, 'listGrantsLedgerForRolePaged')[0]['args'][1])->toBeNull()
        ->and(billing_calls($model, 'listGrantsLedgerForRolePaged')[0]['args'][2])->toBe('all');
});

/**
 * Prerequisites:
 * - One grant-ledger filter is outside its allowlist.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert HTTP 422, the exact error, empty data, and no model call.
 */
test('Billing grant ledger — invalid filters are rejected before model access', function (array $query, string $error) {
    [$controller, $model] = billing_grant_ledger_fixture($query);
    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toBe([$error])
        ->and($controller->json['data'])->toBe([])
        ->and($model->calls)->toBe([]);
})->with([
    [['sort' => 'secret'], 'billing_ledger_grants_invalid_sort'],
    [['order' => 'sideways'], 'billing_ledger_invalid_order'],
    [['state' => 'deleted'], 'billing_ledger_grants_invalid_state'],
]);

/**
 * Prerequisites:
 * - The model returns active, future, and expired grant rows.
 *
 * Steps:
 * 1. Request the ledger.
 * 2. Assert scalar/date mapping and the time-relative active flag.
 */
test('Billing grant ledger — rows map dates types and activity state', function () {
    [$controller] = billing_grant_ledger_fixture([], [
        'items' => [
            [
                'entitlement_code' => 'documents',
                'quantity' => '5',
                'starts_at' => '2020-01-01 00:00:00',
                'expires_at' => '2099-01-01 00:00:00',
            ],
            [
                'entitlement_code' => 'future',
                'quantity' => 2,
                'starts_at' => '2098-01-01 00:00:00',
                'expires_at' => '2099-01-01 00:00:00',
            ],
            [
                'entitlement_code' => 'expired',
                'quantity' => 1,
                'starts_at' => null,
                'expires_at' => '2020-01-01 00:00:00',
            ],
        ],
        'total' => '3',
    ]);
    $controller->index();

    expect($controller->json['data'][0])->toBe([
        'entitlement_code' => 'documents',
        'quantity' => 5,
        'starts_at' => '2020-01-01T00:00:00Z',
        'expires_at' => '2099-01-01T00:00:00Z',
        'is_active' => true,
    ])->and($controller->json['data'][1]['is_active'])->toBeFalse()
        ->and($controller->json['data'][2]['is_active'])->toBeFalse()
        ->and($controller->json['data'][2]['starts_at'])->toBeNull();
});

/**
 * Prerequisites:
 * - A model result with a known total and page size.
 *
 * Steps:
 * 1. Request a populated and an empty ledger page.
 * 2. Assert top-level pagination remains correctly typed.
 */
test('Billing grant ledger — pagination is stable for populated and empty pages', function (
    int $total,
    int $perPage,
    int $totalPages,
) {
    [$controller] = billing_grant_ledger_fixture(['per_page' => $perPage], [
        'items' => [],
        'total' => $total,
    ]);
    $controller->index();

    expect($controller->json['data'])->toBe([])
        ->and($controller->json['pagination'])->toBe([
            'page' => 1,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ]);
})->with([[0, 20, 0], [21, 10, 3]]);

