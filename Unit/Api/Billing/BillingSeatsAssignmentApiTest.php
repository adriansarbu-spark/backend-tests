<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/BillingTestDoubles.php';

beforeEach(function () {
    $this->billingHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->billingSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    billing_set_method('POST');
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
 * - POST targets the base seats route or an unknown included action.
 *
 * Steps:
 * 1. Dispatch the request.
 * 2. Assert HTTP 405, exact allowed methods, and no seat write.
 */
test('Billing seats — mutations dispatch only exact supported subroutes', function (string $route) {
    [$controller, $models] = billing_seats_fixture(['route' => $route]);
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS'])
        ->and($controller->json['error'])->toBe(['Method not allowed'])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([
    'publicapi/v1/billing/seats',
    'publicapi/v1/billing/seats/unknown',
]);

/**
 * Prerequisites:
 * - An assign or release body omits the seat slot UUID.
 *
 * Steps:
 * 1. POST each mutation using array and object body parsing.
 * 2. Assert HTTP 400 before role, slot, or assignment access.
 */
test('Billing seats — mutations require slot UUID for array and object bodies', function (
    string $action,
    array|object $post,
) {
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/' . $action],
        $post,
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['seat_slot_uuid is required'])
        ->and($models['slot']->calls)->toBe([])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([
    ['assign', ['customer_role_uuid' => 'role-1']],
    ['release', (object) []],
]);

/**
 * Prerequisites:
 * - Seat assignment has a slot UUID but no target role UUID.
 *
 * Steps:
 * 1. POST the assignment.
 * 2. Assert the exact HTTP 400 validation response and no slot lookup.
 */
test('Billing seats — assignment requires target role UUID', function () {
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1'],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['seat_slot_uuid and customer_role_uuid are required'])
        ->and($models['slot']->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - The target role is unknown or fails the company ownership check.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert HTTP 400 and prove no slot or assignment mutation occurs.
 */
test('Billing seats — target role must resolve inside the active company', function (
    int $resolvedRoleId,
    bool $belongs,
) {
    $role = new BillingModelStub(['getCustomerRoleIdByUuidForCompany' => $resolvedRoleId]);
    $assignment = new BillingModelStub([
        'isRoleAdminForCompany' => true,
        'roleBelongsToCompany' => $belongs,
    ]);
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['role' => $role, 'assignment' => $assignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['role_not_in_company'])
        ->and($models['slot']->calls)->toBe([])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([[0, true], [81, false]]);

/**
 * Prerequisites:
 * - The selected slot is missing or belongs to another company.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert HTTP 404 hides the slot and no item or assignment mutation follows.
 */
test('Billing seats — unknown and foreign slots are hidden', function (mixed $slotRow, bool $belongs) {
    $slot = new BillingModelStub([
        'getByUuid' => $slotRow,
        'seatSlotBelongsToCompany' => $belongs,
    ]);
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['slot' => $slot],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['seat_slot_not_found'])
        ->and($models['item']->calls)->toBe([])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([[null, true], [['subscription_item_id' => 51], false]]);

/**
 * Prerequisites:
 * - A local slot lacks its Stripe or local subscription-item identity.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert HTTP 500 context_missing and no item or assignment write.
 */
test('Billing seats — slot mutations require complete local item context', function (array $slotRow) {
    $slot = new BillingModelStub([
        'getByUuid' => $slotRow,
        'seatSlotBelongsToCompany' => true,
    ]);
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['slot' => $slot],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(500)
        ->and($controller->json['error'])->toBe(['subscription_item_context_missing'])
        ->and($models['item']->calls)->toBe([])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([
    [['subscription_item_id' => 51, 'stripe_subscription_item_id' => '']],
    [['subscription_item_id' => 0, 'stripe_subscription_item_id' => 'si_1']],
]);

/**
 * Prerequisites:
 * - The local item is absent or its subscription parent is foreign.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert the current exact 404/400 branch and no assignment write.
 */
test('Billing seats — missing item and foreign parent contexts stop mutations', function (
    mixed $itemRow,
    mixed $parentRow,
    int $status,
) {
    $item = new BillingModelStub(['getById' => $itemRow]);
    $subscription = new BillingModelStub(['getById' => $parentRow]);
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['item' => $item, 'subscription' => $subscription],
    );
    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toBe(['subscription_item_not_found'])
        ->and($models['seat_assignment']->calls)->toBe([]);
})->with([
    [null, null, 404],
    [
        ['subscription_item_id' => 51, 'subscription_id' => 61, 'quantity' => 2],
        ['company_id' => 999],
        400,
    ],
]);

/**
 * Prerequisites:
 * - A positive local item quantity and an assignable free slot.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert slot reconciliation occurs before capacity checks.
 */
test('Billing seats — positive item quantity reconciles local slots before capacity checks', function () {
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
    );
    $controller->index();

    expect(billing_calls($models['item'], 'reconcileSeatSlotsToQuantity')[0]['args'])->toBe([51, 2, 20])
        ->and($controller->statusCode)->toBe(200);
});

/**
 * Prerequisites:
 * - The slot state is outside the assignable local states.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert seat_slot_not_assignable and no assignment write.
 */
test('Billing seats — assignment accepts only unassigned or cancel-pending slots', function (string $state) {
    $slot = new BillingModelStub([
        'getByUuid' => [
            'subscription_item_id' => 51,
            'stripe_subscription_item_id' => 'si_1',
            'status' => $state,
        ],
        'seatSlotBelongsToCompany' => true,
    ]);
    [$controller, $models] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['slot' => $slot],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['seat_slot_not_assignable'])
        ->and(billing_calls($models['seat_assignment'], 'assignSeatToStripeSubscriptionItem'))->toBe([]);
})->with(['assigned', 'released', 'unknown']);

/**
 * Prerequisites:
 * - The target role already has an active seat from the parent subscription.
 *
 * Steps:
 * 1. POST another seat assignment.
 * 2. Assert duplicate protection and no insert.
 */
test('Billing seats — a role cannot receive duplicate seats from one subscription', function () {
    $seatAssignment = new BillingModelStub([
        'roleHasActiveAssignmentForSubscription' => true,
    ]);
    [$controller] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['seat_assignment' => $seatAssignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['role_already_assigned_to_subscription'])
        ->and(billing_calls($seatAssignment, 'assignSeatToStripeSubscriptionItem'))->toBe([]);
});

/**
 * Prerequisites:
 * - Active assignments fill capacity or no free local slot remains.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert no_unassigned_seats and no insert.
 */
test('Billing seats — capacity and free-slot checks prevent over-assignment', function (
    int $active,
    int $free,
) {
    $seatAssignment = new BillingModelStub([
        'roleHasActiveAssignmentForSubscription' => false,
        'countActiveForSubscriptionItemId' => $active,
        'countUnassignedSlotsForSubscriptionItemId' => $free,
    ]);
    [$controller] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['seat_assignment' => $seatAssignment],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['no_unassigned_seats'])
        ->and(billing_calls($seatAssignment, 'assignSeatToStripeSubscriptionItem'))->toBe([]);
})->with([[2, 1], [1, 0]]);

/**
 * Prerequisites:
 * - All assignment validation succeeds and the local insert returns an ID or zero.
 *
 * Steps:
 * 1. POST seat assignment.
 * 2. Assert exact insert context and success shape, or HTTP 500 assignment_failed.
 */
test('Billing seats — local assignment ID controls the final response', function (int $assignmentId) {
    $seatAssignment = new BillingModelStub([
        'roleHasActiveAssignmentForSubscription' => false,
        'countActiveForSubscriptionItemId' => 0,
        'countUnassignedSlotsForSubscriptionItemId' => 1,
        'assignSeatToStripeSubscriptionItem' => $assignmentId,
    ]);
    [$controller] = billing_seats_fixture(
        ['route' => 'publicapi/v1/billing/seats/assign'],
        (object) ['seat_slot_uuid' => 'slot-1', 'customer_role_uuid' => 'role-1'],
        ['seat_assignment' => $seatAssignment],
    );
    $controller->index();

    expect(billing_calls($seatAssignment, 'assignSeatToStripeSubscriptionItem')[0]['args'])->toBe([
        20, 'si_local_1', 81, 'slot-1',
    ]);
    if ($assignmentId > 0) {
        expect($controller->statusCode)->toBe(200)
            ->and($controller->json['data'])->toBe(['ok' => true, 'assignment_id' => $assignmentId]);
    } else {
        expect($controller->statusCode)->toBe(500)
            ->and($controller->json['error'])->toBe(['assignment_failed']);
    }
})->with([91, 0]);

/**
 * Prerequisites:
 * - A valid local slot is released once or repeatedly.
 *
 * Steps:
 * 1. POST release.
 * 2. Assert exact local context reaches the release model and the boundary remains idempotently successful.
 */
test('Billing seats — release is idempotent at the controller boundary', function () {
    $seatAssignment = new BillingModelStub(['releaseSeatForStripeSubscriptionItem' => null]);
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        [$controller] = billing_seats_fixture(
            ['route' => 'publicapi/v1/billing/seats/release'],
            ['seat_slot_uuid' => 'slot-1'],
            ['seat_assignment' => $seatAssignment],
        );
        $controller->index();
        expect($controller->statusCode)->toBe(200)
            ->and($controller->json['data'])->toBe(['ok' => true]);
    }

    expect(billing_calls($seatAssignment, 'releaseSeatForStripeSubscriptionItem'))->toHaveCount(2)
        ->and(billing_calls($seatAssignment, 'releaseSeatForStripeSubscriptionItem')[0]['args'])->toBe([
            'si_local_1', 'slot-1', 20,
        ])->and(billing_calls($seatAssignment, 'assignSeatToStripeSubscriptionItem'))->toBe([]);
});

