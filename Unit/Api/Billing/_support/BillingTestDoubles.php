<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/tests_config.php';
require_once PUBLIC_API . 'billing/catalog.php';
require_once PUBLIC_API . 'billing/company_overview.php';
require_once PUBLIC_API . 'billing/entitlements.php';
require_once PUBLIC_API . 'billing/entitlement_grants.php';
require_once PUBLIC_API . 'billing/ledger_role_consumption.php';
require_once PUBLIC_API . 'billing/ledger_role_grants.php';
require_once PUBLIC_API . 'billing/me.php';
require_once PUBLIC_API . 'billing/seats.php';

trait BillingControllerHarness
{
    public int $checkPluginCalls = 0;

    public int $sendResponseCalls = 0;

    /** @var array<string, mixed>|object */
    private array|object $billingPost = [];

    public function checkPlugin(): void
    {
        ++$this->checkPluginCalls;
    }

    /** @return null */
    public function sendResponse()
    {
        ++$this->sendResponseCalls;

        return null;
    }

    /** @param array<string, mixed>|object $payload */
    public function setBillingPost(array|object $payload): void
    {
        $this->billingPost = $payload;
    }

    /** @return array<string, mixed>|object */
    public function getPost()
    {
        return $this->billingPost;
    }
}

final class TestableControllerPublicapiv1BillingCatalog extends ControllerPublicapiv1BillingCatalog
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingCompanyOverview extends ControllerPublicapiv1BillingCompanyOverview
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingEntitlements extends ControllerPublicapiv1BillingEntitlements
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingEntitlementGrants extends ControllerPublicapiv1BillingEntitlementGrants
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingLedgerRoleConsumption extends ControllerPublicapiv1BillingLedgerRoleConsumption
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingLedgerRoleGrants extends ControllerPublicapiv1BillingLedgerRoleGrants
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingMe extends ControllerPublicapiv1BillingMe
{
    use BillingControllerHarness;
}

final class TestableControllerPublicapiv1BillingSeats extends ControllerPublicapiv1BillingSeats
{
    use BillingControllerHarness;
}

final class BillingCustomerStub
{
    public function __construct(
        private readonly int $customerId = 10,
        private readonly int $companyId = 20,
        private readonly int $roleId = 30,
        private readonly ?string $roleCode = 'admin',
    ) {
    }

    public function getId(): int
    {
        return $this->customerId;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function getRoleCode(): ?string
    {
        return $this->roleCode;
    }
}

/**
 * Generic call-recording model. A configured response may be a value, a queue
 * of values, or a closure that receives the method arguments.
 */
final class BillingModelStub
{
    /** @var array<string, mixed> */
    public array $responses = [];

    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        $response = $this->responses[$method] ?? null;

        if ($response instanceof Closure) {
            return $response(...$arguments);
        }

        if (is_array($response) && array_key_exists('__queue', $response)) {
            return array_shift($this->responses[$method]['__queue']);
        }

        return $response;
    }

    /** @return list<array{method: string, args: list<mixed>}> */
    public function callsTo(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method,
        ));
    }
}

final class BillingLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    /**
     * @param array<string, object> $models Route => model.
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $models,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if (isset($this->models[$route])) {
            $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
        }
    }
}

final class BillingDbResultStub
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public array $rows = [],
        public array $row = [],
        public int $num_rows = 0,
    ) {
        if ($this->row === [] && $this->rows !== []) {
            $this->row = $this->rows[0];
        }
        if ($this->num_rows === 0 && $this->rows !== []) {
            $this->num_rows = count($this->rows);
        }
    }
}

final class BillingDbStub
{
    /** @var list<string> */
    public array $queries = [];

    public BillingDbResultStub $result;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(array $rows = [])
    {
        $this->result = new BillingDbResultStub($rows);
    }

    public function query(string $sql): BillingDbResultStub
    {
        $this->queries[] = $sql;

        return $this->result;
    }
}

/**
 * @param array<string, object> $models
 * @param array<string, mixed>  $query
 *
 * @return array{0: Registry, 1: BillingLoadStub, 2: BillingDbStub}
 */
function billing_registry(
    ?BillingCustomerStub $customer = null,
    array $models = [],
    array $query = [],
    ?BillingDbStub $db = null,
): array {
    $registry = new Registry();
    $load = new BillingLoadStub($registry, $models);
    $db ??= new BillingDbStub();
    $registry->set('load', $load);
    $registry->set('customer', $customer ?? new BillingCustomerStub());
    $registry->set('request', (object) ['get' => $query, 'server' => []]);
    $registry->set('db', $db);

    return [$registry, $load, $db];
}

/**
 * @template T of PublicAPIController
 *
 * @param class-string<T>            $class
 * @param array<string, list<string>> $permissions
 *
 * @return T
 */
function billing_controller(
    string $class,
    Registry $registry,
    array $permissions = ['get' => [], 'post' => []],
): PublicAPIController {
    $controller = new $class($registry);
    $controller->permission = (object) $permissions;

    return $controller;
}

/** @return array<string, list<string>> */
function billing_all_permissions(): array
{
    return [
        'get' => [
            'publicapi/v1/billing/catalog',
            'publicapi/v1/billing/company_overview',
            'publicapi/v1/billing/entitlements',
            'publicapi/v1/billing/entitlement_grants',
            'publicapi/v1/billing/ledger_role_consumption',
            'publicapi/v1/billing/ledger_role_grants',
            'publicapi/v1/billing/me',
            'publicapi/v1/billing/seats',
        ],
        'post' => [
            'publicapi/v1/billing/entitlement_grants',
            'publicapi/v1/billing/seats',
        ],
    ];
}

function billing_set_method(string $method): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
}

/** @return list<array<string, mixed>> */
function billing_calls(BillingModelStub $model, string $method): array
{
    return $model->callsTo($method);
}

/**
 * @param array<string, mixed>        $query
 * @param array<string, mixed>|object $post
 * @param array<string, object>       $overrides
 *
 * @return array{0: TestableControllerPublicapiv1BillingSeats, 1: array<string, BillingModelStub>, 2: BillingLoadStub}
 */
function billing_seats_fixture(
    array $query = [],
    array|object $post = [],
    array $overrides = [],
): array {
    $models = [
        'assignment' => $overrides['assignment'] ?? new BillingModelStub([
            'isRoleAdminForCompany' => true,
            'roleBelongsToCompany' => true,
        ]),
        'item' => $overrides['item'] ?? new BillingModelStub([
            'getByUuid' => [
                'subscription_item_id' => 51,
                'subscription_id' => 61,
                'price_id' => 71,
                'quantity' => 2,
                'current_period_start' => '2026-01-01 00:00:00',
                'current_period_end' => '2027-01-01 00:00:00',
            ],
            'getById' => [
                'subscription_item_id' => 51,
                'subscription_id' => 61,
                'price_id' => 71,
                'quantity' => 2,
                'current_period_start' => '2026-01-01 00:00:00',
                'current_period_end' => '2027-01-01 00:00:00',
            ],
            'reconcileSeatSlotsToQuantity' => null,
        ]),
        'subscription' => $overrides['subscription'] ?? new BillingModelStub([
            'getById' => ['subscription_id' => 61, 'company_id' => 20],
        ]),
        'slot' => $overrides['slot'] ?? new BillingModelStub([
            'countSeatsForSubscriptionItem' => 0,
            'getSeatsForSubscriptionItem' => [],
            'getSeatSummaryForSubscriptionItem' => ['assigned' => 0, 'unassigned' => 0],
            'getByUuid' => [
                'seat_slot_uuid' => 'slot-1',
                'subscription_item_id' => 51,
                'stripe_subscription_item_id' => 'si_local_1',
                'status' => 'unassigned',
            ],
            'seatSlotBelongsToCompany' => true,
        ]),
        'seat_assignment' => $overrides['seat_assignment'] ?? new BillingModelStub([
            'roleHasActiveAssignmentForSubscription' => false,
            'countActiveForSubscriptionItemId' => 0,
            'countUnassignedSlotsForSubscriptionItemId' => 1,
            'assignSeatToStripeSubscriptionItem' => 91,
            'releaseSeatForStripeSubscriptionItem' => null,
        ]),
        'role' => $overrides['role'] ?? new BillingModelStub([
            'getCustomerRoleIdByUuidForCompany' => 81,
        ]),
        'overview' => $overrides['overview'] ?? new BillingModelStub([
            'getAllowanceForSeat' => ['documents' => ['remaining' => 10]],
        ]),
    ];
    [$registry, $load] = billing_registry(
        models: [
            'billing/entitlement_assignment' => $models['assignment'],
            'billing/company_subscription_item' => $models['item'],
            'billing/company_subscription' => $models['subscription'],
            'billing/seat_slot' => $models['slot'],
            'billing/seat_assignment' => $models['seat_assignment'],
            'account/customer_role' => $models['role'],
            'billing/company_overview' => $models['overview'],
        ],
        query: $query,
    );
    /** @var TestableControllerPublicapiv1BillingSeats $controller */
    $controller = billing_controller(TestableControllerPublicapiv1BillingSeats::class, $registry, billing_all_permissions());
    $controller->setBillingPost($post);

    return [$controller, $models, $load];
}
