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
 * @param array<string, mixed>|object $post
 * @param array<string, object> $overrides
 *
 * @return array{0: TestableControllerPublicapiv1BillingEntitlementGrants, 1: BillingModelStub, 2: BillingModelStub, 3: BillingModelStub, 4: BillingLoadStub}
 */
function billing_entitlement_grants_fixture(
    array $query = [],
    array|object $post = [],
    array $overrides = [],
): array {
    $assignment = $overrides['assignment'] ?? new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'countTopupGrantPackagesForPrice' => 0,
        'getTopupGrantPackagesForPrice' => [],
        'getTopupGrantPackageSummaryForPrice' => ['assigned' => 0, 'unassigned' => 0],
        'isAssignableTopupGrant' => true,
        'sumAssignedActiveForGrant' => 0,
        'assignGrantToRole' => ['ok' => true, 'assignment_id' => 77],
    ]);
    $grant = $overrides['grant'] ?? new BillingModelStub([
        'getGrantByUuid' => [
            'grant_id' => 41,
            'company_id' => 20,
            'quantity' => '9',
        ],
    ]);
    $role = $overrides['role'] ?? new BillingModelStub([
        'getCustomerRoleIdByUuidForCompany' => 51,
    ]);
    [$registry, $load] = billing_registry(
        models: [
            'billing/entitlement_assignment' => $assignment,
            'billing/entitlement_grant' => $grant,
            'account/customer_role' => $role,
        ],
        query: $query,
    );
    /** @var TestableControllerPublicapiv1BillingEntitlementGrants $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingEntitlementGrants::class, $registry, billing_all_permissions());
    $controller->setBillingPost($post);

    return [$controller, $assignment, $grant, $role, $load];
}

/**
 * Prerequisites:
 * - An admin requests the package list without a usable price UUID.
 *
 * Steps:
 * 1. Request the list with a missing or blank price UUID.
 * 2. Assert HTTP 400 before count, list, or summary queries.
 */
test('Billing entitlement grants — package listing requires price UUID', function (array $query) {
    [$controller, $assignment] = billing_entitlement_grants_fixture($query);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['price_uuid is required'])
        ->and(billing_calls($assignment, 'countTopupGrantPackagesForPrice'))->toBe([])
        ->and(billing_calls($assignment, 'getTopupGrantPackagesForPrice'))->toBe([]);
})->with([[[]], [['price_uuid' => '   ']]]);

/**
 * Prerequisites:
 * - Valid package-list filters and deterministic empty model results.
 *
 * Steps:
 * 1. Request the list.
 * 2. Assert pagination clamps, status is accepted, and search is trimmed for each model call.
 */
test('Billing entitlement grants — list filters and pagination are normalized', function (
    array $query,
    string $status,
    int $page,
    int $perPage,
) {
    $query['price_uuid'] = ' price-1 ';
    $query['status'] = $status;
    $query['search'] = ' employee ';
    [$controller, $assignment] = billing_entitlement_grants_fixture($query);
    $controller->index();

    expect(billing_calls($assignment, 'countTopupGrantPackagesForPrice')[0]['args'])->toBe([
        20, 'price-1', $status, 'employee',
    ])->and(billing_calls($assignment, 'getTopupGrantPackagesForPrice')[0]['args'])->toBe([
        20, 'price-1', $status, 'employee', $page, $perPage,
    ])->and($controller->json['data']['pagination']['page'])->toBe($page)
        ->and($controller->json['data']['pagination']['per_page'])->toBe($perPage);
})->with([
    [[], 'all', 1, 50],
    [['page' => -1, 'per_page' => 0], 'assigned', 1, 1],
    [['page' => 3, 'per_page' => 500], 'unassigned', 3, 100],
]);

/**
 * Prerequisites:
 * - Blank status or a status outside the public allowlist.
 *
 * Steps:
 * 1. Request the package list.
 * 2. Assert blank status becomes all while an invalid status causes no list calls.
 */
test('Billing entitlement grants — blank status defaults to all', function () {
    [$controller, $assignment] = billing_entitlement_grants_fixture([
        'price_uuid' => 'price-1',
        'status' => '',
    ]);
    $controller->index();

    expect(billing_calls($assignment, 'countTopupGrantPackagesForPrice')[0]['args'][2])->toBe('all');
});

/**
 * Prerequisites:
 * - A package-list status outside the public allowlist.
 *
 * Steps:
 * 1. Request the list.
 * 2. Assert HTTP 400 and no count, list, or summary query.
 */
test('Billing entitlement grants — invalid list status is rejected before queries', function () {
    [$controller, $assignment] = billing_entitlement_grants_fixture([
        'price_uuid' => 'price-1',
        'status' => 'deleted',
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_status_filter'])
        ->and(billing_calls($assignment, 'countTopupGrantPackagesForPrice'))->toBe([])
        ->and(billing_calls($assignment, 'getTopupGrantPackageSummaryForPrice'))->toBe([]);
});

/**
 * Prerequisites:
 * - The model returns one unassigned and one assigned grant package.
 *
 * Steps:
 * 1. Request the package list.
 * 2. Assert identity, employee, quantity, assignment, date, summary, and pagination mapping.
 */
test('Billing entitlement grants — assigned and unassigned rows map to a stable response', function () {
    $assignment = new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'countTopupGrantPackagesForPrice' => 2,
        'getTopupGrantPackagesForPrice' => [
            [
                'grant_uuid' => 'grant-free',
                'entitlement_code' => 'documents',
                'quantity' => '5',
                'assignment_id' => null,
                'assigned_customer_role_uuid' => null,
                'firstname' => '',
                'lastname' => '',
                'employee_email' => '',
                'starts_at' => null,
                'expires_at' => '2099-01-01 00:00:00',
                'created_at' => '2026-01-01 00:00:00',
            ],
            [
                'grant_uuid' => 'grant-used',
                'entitlement_code' => 'signatures',
                'quantity' => 3,
                'assignment_id' => 8,
                'assigned_customer_role_uuid' => 'role-1',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'employee_email' => 'ada@example.test',
                'starts_at' => '2026-01-01 00:00:00',
                'expires_at' => null,
                'created_at' => '2026-01-01 00:00:00',
            ],
        ],
        'getTopupGrantPackageSummaryForPrice' => ['assigned' => 1, 'unassigned' => 1],
    ]);
    [$controller] = billing_entitlement_grants_fixture(
        ['price_uuid' => 'price-1', 'per_page' => 1],
        overrides: ['assignment' => $assignment],
    );
    $controller->index();

    expect($controller->json['data']['items'][0])->toMatchArray([
        'grant_uuid' => 'grant-free',
        'entitlement_code' => 'documents',
        'quantity' => 5,
        'is_assigned' => false,
        'assigned_to_customer_role_uuid' => null,
        'employee_name' => null,
        'employee_email' => null,
        'starts_at' => null,
        'expires_at' => '2099-01-01T00:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
    ])->and($controller->json['data']['items'][1])->toMatchArray([
        'grant_uuid' => 'grant-used',
        'is_assigned' => true,
        'assigned_to_customer_role_uuid' => 'role-1',
        'employee_name' => 'Ada Lovelace',
        'employee_email' => 'ada@example.test',
    ])->and($controller->json['data']['summary'])->toBe(['assigned' => 1, 'unassigned' => 1])
        ->and($controller->json['data']['pagination'])->toBe([
            'page' => 1,
            'per_page' => 1,
            'total' => 2,
            'total_pages' => 2,
        ]);
});

/**
 * Prerequisites:
 * - The assign subroute receives an array or object body with a missing UUID.
 *
 * Steps:
 * 1. POST the assignment payload.
 * 2. Assert both parsing forms produce HTTP 400 before grant lookup.
 */
test('Billing entitlement grants — assignment requires both UUIDs for array and object bodies', function (array|object $post) {
    billing_set_method('POST');
    [$controller, , $grant] = billing_entitlement_grants_fixture(
        ['route' => 'publicapi/v1/billing/entitlement_grants/assign'],
        $post,
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['grant_uuid_customer_role_uuid_required'])
        ->and($grant->calls)->toBe([]);
})->with([
    [['grant_uuid' => 'grant-1']],
    [(object) ['customer_role_uuid' => 'role-1']],
]);

/**
 * Prerequisites:
 * - POST is sent to the base route or an unknown action.
 *
 * Steps:
 * 1. Dispatch each non-assign POST route.
 * 2. Assert HTTP 405 and prove no grant mutation model is loaded.
 */
test('Billing entitlement grants — assignment dispatches only the exact assign subroute', function (string $route) {
    billing_set_method('POST');
    [$controller, , , , $load] = billing_entitlement_grants_fixture(['route' => $route]);
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment']);
})->with([
    'publicapi/v1/billing/entitlement_grants',
    'publicapi/v1/billing/entitlement_grants/unknown',
]);

/**
 * Prerequisites:
 * - An assign request references an unknown, foreign, or non-assignable grant.
 *
 * Steps:
 * 1. POST the assignment.
 * 2. Assert HTTP 404 hides grant existence and no assignment write occurs.
 */
test('Billing entitlement grants — unknown foreign and non-assignable grants are hidden', function (
    mixed $grantRow,
    bool $assignable,
) {
    billing_set_method('POST');
    $grant = new BillingModelStub(['getGrantByUuid' => $grantRow]);
    $assignment = new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'isAssignableTopupGrant' => $assignable,
    ]);
    [$controller] = billing_entitlement_grants_fixture(
        ['route' => 'publicapi/v1/billing/entitlement_grants/assign'],
        ['grant_uuid' => 'grant-1', 'customer_role_uuid' => 'role-1'],
        ['grant' => $grant, 'assignment' => $assignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['grant_not_found'])
        ->and(billing_calls($assignment, 'assignGrantToRole'))->toBe([]);
})->with([
    [null, true],
    [['grant_id' => 1, 'company_id' => 999, 'quantity' => 2], true],
    [['grant_id' => 1, 'company_id' => 20, 'quantity' => 2], false],
]);

/**
 * Prerequisites:
 * - The selected grant already has an active assignment.
 *
 * Steps:
 * 1. POST the assignment.
 * 2. Assert HTTP 409 and no duplicate write.
 */
test('Billing entitlement grants — an already assigned grant cannot be assigned twice', function () {
    billing_set_method('POST');
    $assignment = new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'isAssignableTopupGrant' => true,
        'sumAssignedActiveForGrant' => 1,
    ]);
    [$controller] = billing_entitlement_grants_fixture(
        ['route' => 'publicapi/v1/billing/entitlement_grants/assign'],
        ['grant_uuid' => 'grant-1', 'customer_role_uuid' => 'role-1'],
        ['assignment' => $assignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(409)
        ->and($controller->json['error'])->toBe(['grant_already_assigned'])
        ->and(billing_calls($assignment, 'assignGrantToRole'))->toBe([]);
});

/**
 * Prerequisites:
 * - The target role UUID does not belong to the active company.
 *
 * Steps:
 * 1. POST the assignment.
 * 2. Assert HTTP 400 and no assignment write.
 */
test('Billing entitlement grants — target role must belong to the active company', function () {
    billing_set_method('POST');
    $role = new BillingModelStub(['getCustomerRoleIdByUuidForCompany' => 0]);
    [$controller, $assignment] = billing_entitlement_grants_fixture(
        ['route' => 'publicapi/v1/billing/entitlement_grants/assign'],
        ['grant_uuid' => 'grant-1', 'customer_role_uuid' => 'foreign-role'],
        ['role' => $role],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['role_not_in_company'])
        ->and(billing_calls($assignment, 'assignGrantToRole'))->toBe([]);
});

/**
 * Prerequisites:
 * - Validation succeeds and the assignment model returns success or an error.
 *
 * Steps:
 * 1. POST the assignment.
 * 2. Assert success maps IDs, quantity, and assignment state; failures preserve the model error.
 */
test('Billing entitlement grants — assignment result maps success and model errors', function (
    array $result,
    int $status,
    array $expected,
) {
    billing_set_method('POST');
    $assignment = new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'isAssignableTopupGrant' => true,
        'sumAssignedActiveForGrant' => 0,
        'assignGrantToRole' => $result,
    ]);
    [$controller] = billing_entitlement_grants_fixture(
        ['route' => 'publicapi/v1/billing/entitlement_grants/assign'],
        (object) ['grant_uuid' => 'grant-1', 'customer_role_uuid' => 'role-1'],
        ['assignment' => $assignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe($status);
    if ($status === 200) {
        expect(billing_calls($assignment, 'assignGrantToRole')[0]['args'])->toBe([41, 51, 9, false])
            ->and($controller->json['data'])->toBe($expected);
    } else {
        expect($controller->json['error'])->toBe($expected);
    }
})->with([
    [
        ['ok' => true, 'assignment_id' => 88],
        200,
        [
            'ok' => true,
            'assignment_id' => 88,
            'grant_uuid' => 'grant-1',
            'customer_role_uuid' => 'role-1',
            'quantity' => 9,
            'is_assigned' => true,
        ],
    ],
    [['ok' => false, 'error' => 'allocation_locked'], 400, ['allocation_locked']],
    [['ok' => false], 400, ['assignment_failed']],
]);
