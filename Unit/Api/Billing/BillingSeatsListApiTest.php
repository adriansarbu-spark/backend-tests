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
 * Prerequisites:
 * - An admin requests seats without a usable subscription item UUID.
 *
 * Steps:
 * 1. Request the seat list.
 * 2. Assert HTTP 400 before item lookup.
 */
test('Billing seats — listing requires subscription item UUID', function (array $query) {
    [$controller, $models] = billing_seats_fixture($query);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['subscription_item_uuid is required'])
        ->and($models['item']->calls)->toBe([]);
})->with([[[]], [['subscription_item_uuid' => '   ']]]);

/**
 * Prerequisites:
 * - The subscription item is absent or its parent belongs to another company.
 *
 * Steps:
 * 1. Request the seat list.
 * 2. Assert HTTP 404 hides item existence and no seat query runs.
 */
test('Billing seats — missing and foreign subscription items are hidden', function (
    mixed $itemRow,
    mixed $parentRow,
) {
    $item = new BillingModelStub(['getByUuid' => $itemRow]);
    $subscription = new BillingModelStub(['getById' => $parentRow]);
    [$controller, $models] = billing_seats_fixture(
        ['subscription_item_uuid' => 'item-1'],
        overrides: ['item' => $item, 'subscription' => $subscription],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['subscription_item_not_found'])
        ->and($models['slot']->calls)->toBe([]);
})->with([
    [null, null],
    [['subscription_item_id' => 51, 'subscription_id' => 61], ['company_id' => 999]],
]);

/**
 * Prerequisites:
 * - A valid seat-list request with optional pagination and filters.
 *
 * Steps:
 * 1. Request seats.
 * 2. Assert pagination clamps and trimmed filters reach count, list, and summary models.
 */
test('Billing seats — listing normalizes filters and pagination', function (
    array $query,
    string $status,
    int $page,
    int $perPage,
) {
    $query += [
        'subscription_item_uuid' => ' item-1 ',
        'status' => $status,
        'search' => ' ada ',
    ];
    [$controller, $models] = billing_seats_fixture($query);
    $controller->index();

    expect(billing_calls($models['slot'], 'countSeatsForSubscriptionItem')[0]['args'])->toBe([
        20, 51, $status, 'ada',
    ])->and(billing_calls($models['slot'], 'getSeatsForSubscriptionItem')[0]['args'])->toBe([
        20, 51, $status, 'ada', $page, $perPage,
    ])->and(billing_calls($models['slot'], 'getSeatSummaryForSubscriptionItem')[0]['args'])->toBe([20, 51]);
})->with([
    [[], 'all', 1, 50],
    [['page' => -1, 'per_page' => 0], 'assigned', 1, 1],
    [['page' => 4, 'per_page' => 200], 'unassigned', 4, 100],
    [[], 'cancel_pending', 1, 50],
]);

/**
 * Prerequisites:
 * - Blank or invalid seat-list status.
 *
 * Steps:
 * 1. Request the list.
 * 2. Assert blank status defaults to all and invalid status causes no item or seat queries.
 */
test('Billing seats — blank status defaults to all', function () {
    [$controller, $models] = billing_seats_fixture([
        'subscription_item_uuid' => 'item-1',
        'status' => '',
    ]);
    $controller->index();

    expect(billing_calls($models['slot'], 'countSeatsForSubscriptionItem')[0]['args'][2])->toBe('all');
});

/**
 * Prerequisites:
 * - A status outside the seat-list allowlist.
 *
 * Steps:
 * 1. Request the seat list.
 * 2. Assert HTTP 400 before item, count, list, and summary access.
 */
test('Billing seats — invalid status is rejected before list queries', function () {
    [$controller, $models] = billing_seats_fixture([
        'subscription_item_uuid' => 'item-1',
        'status' => 'deleted',
    ]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['invalid_status_filter'])
        ->and($models['item']->calls)->toBe([])
        ->and($models['slot']->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - The seat model returns one unassigned and one assigned row.
 *
 * Steps:
 * 1. Request seats without allowance inclusion.
 * 2. Assert identity, employee, cancellation, dates, summary, and pagination mapping.
 * 3. Prove no per-row allowance lookup occurs.
 */
test('Billing seats — assigned and unassigned rows map without unnecessary allowance lookups', function () {
    $slot = new BillingModelStub([
        'countSeatsForSubscriptionItem' => '2',
        'getSeatsForSubscriptionItem' => [
            [
                'seat_slot_uuid' => 'slot-free',
                'slot_status' => 'unassigned',
                'subscription_item_uuid' => 'item-1',
                'subscription_item_id' => '51',
                'customer_role_id' => null,
                'customer_role_uuid' => '',
                'firstname' => '',
                'lastname' => '',
                'employee_email' => '',
                'assigned_at' => null,
                'assignment_cancel_at_period_end' => 0,
            ],
            [
                'seat_slot_uuid' => 'slot-used',
                'slot_status' => 'assigned',
                'subscription_item_uuid' => 'item-1',
                'subscription_item_id' => 51,
                'customer_role_id' => 81,
                'customer_role_uuid' => 'role-1',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'employee_email' => 'ada@example.test',
                'assigned_at' => '2026-01-02 03:04:05',
                'assignment_cancel_at_period_end' => 1,
            ],
        ],
        'getSeatSummaryForSubscriptionItem' => ['assigned' => 1, 'unassigned' => 1],
    ]);
    [$controller, $models] = billing_seats_fixture(
        ['subscription_item_uuid' => 'item-1', 'per_page' => 1],
        overrides: ['slot' => $slot],
    );
    $controller->index();

    expect($controller->json['data']['items'][0])->toBe([
        'seat_slot_uuid' => 'slot-free',
        'slot_status' => 'unassigned',
        'subscription_item_uuid' => 'item-1',
        'subscription_item_id' => 51,
        'customer_role_uuid' => null,
        'employee_name' => null,
        'employee_email' => null,
        'assigned_at' => null,
        'cancel_at_period_end' => false,
    ])->and($controller->json['data']['items'][1])->toMatchArray([
        'seat_slot_uuid' => 'slot-used',
        'customer_role_uuid' => 'role-1',
        'employee_name' => 'Ada Lovelace',
        'employee_email' => 'ada@example.test',
        'assigned_at' => '2026-01-02T03:04:05Z',
        'cancel_at_period_end' => true,
    ])->and($controller->json['data']['summary'])->toBe(['assigned' => 1, 'unassigned' => 1])
        ->and($controller->json['data']['pagination']['total_pages'])->toBe(2)
        ->and($models['overview']->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - A returned seat row and one documented allowance-inclusion trigger.
 *
 * Steps:
 * 1. Request seats using assigned status, allowance, or usage inclusion.
 * 2. Assert allowance details are added with exact price, role, period, and slot context.
 */
test('Billing seats — documented include triggers add allowance details', function (array $query) {
    $slot = new BillingModelStub([
        'countSeatsForSubscriptionItem' => 1,
        'getSeatsForSubscriptionItem' => [[
            'seat_slot_uuid' => 'slot-1',
            'slot_status' => 'assigned',
            'customer_role_id' => 81,
            'customer_role_uuid' => 'role-1',
            'firstname' => 'Ada',
            'lastname' => '',
            'employee_email' => 'ada@example.test',
            'assigned_at' => null,
        ]],
        'getSeatSummaryForSubscriptionItem' => ['assigned' => 1],
    ]);
    $query += ['subscription_item_uuid' => 'item-1'];
    [$controller, $models] = billing_seats_fixture($query, overrides: ['slot' => $slot]);
    $controller->index();

    expect($controller->json['data']['items'][0]['allowance'])->toBe(['documents' => ['remaining' => 10]])
        ->and(billing_calls($models['overview'], 'getAllowanceForSeat')[0]['args'])->toBe([
            71, 81, '2026-01-01 00:00:00', '2027-01-01 00:00:00', 'slot-1',
        ]);
})->with([
    [['status' => 'assigned']],
    [['include' => 'allowance']],
    [['include' => 'usage']],
]);

/**
 * Prerequisites:
 * - The seat query returns no rows and a zero total.
 *
 * Steps:
 * 1. Request seats.
 * 2. Assert stable empty items, summary, and zero pagination.
 */
test('Billing seats — empty results retain stable list and pagination shapes', function () {
    [$controller] = billing_seats_fixture(['subscription_item_uuid' => 'item-1']);
    $controller->index();

    expect($controller->json['data'])->toBe([
        'items' => [],
        'pagination' => [
            'page' => 1,
            'per_page' => 50,
            'total' => 0,
            'total_pages' => 0,
        ],
        'summary' => ['assigned' => 0, 'unassigned' => 0],
    ]);
});

