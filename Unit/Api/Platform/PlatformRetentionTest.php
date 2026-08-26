<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'platform/retention.php';

final class PlatformRetentionCustomerStub
{
    public function __construct(
        private readonly int $customerId,
        private readonly int $companyId,
        private readonly int $roleId,
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
}

final class PlatformRetentionRepresentativeModelStub
{
    public bool $isAdmin = true;

    /** @var list<array{0: int, 1: int}> */
    public array $adminChecks = [];

    public function isCompanyAdminRole(int $roleId, int $companyId): bool
    {
        $this->adminChecks[] = [$roleId, $companyId];

        return $this->isAdmin;
    }
}

final class PlatformRetentionPolicyModelStub
{
    /** @var array{retention_days: int|null, date_modified: string|null} */
    public array $policy = [
        'retention_days' => 30,
        'date_modified' => '2026-08-12 09:30:00',
    ];

    /** @var list<int> */
    public array $getCalls = [];

    /** @var list<array{0: int, 1: int|null, 2: int}> */
    public array $setCalls = [];

    /** @return array{retention_days: int|null, date_modified: string|null} */
    public function getCompanyRetention(int $companyId): array
    {
        $this->getCalls[] = $companyId;

        return $this->policy;
    }

    public function setCompanyRetention(int $companyId, ?int $retentionDays, int $customerId): void
    {
        $this->setCalls[] = [$companyId, $retentionDays, $customerId];
        $this->policy = [
            'retention_days' => $retentionDays,
            'date_modified' => '2026-08-12 10:00:00',
        ];
    }
}

final class PlatformRetentionLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly PlatformRetentionRepresentativeModelStub $representative,
        private readonly PlatformRetentionPolicyModelStub $policy,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;

        if ($route === 'company/representative') {
            $this->registry->set('model_company_representative', $this->representative);
        }
        if ($route === 'signing/document_file_version') {
            $this->registry->set('model_signing_document_file_version', $this->policy);
        }
    }
}

final class TestableControllerPublicAPIV1PlatformRetention extends ControllerPublicAPIV1PlatformRetention
{
    /** @var mixed */
    private $postPayload = [];

    public int $checkPluginCalls = 0;

    public int $sendResponseCalls = 0;

    /** @var list<array{0: int, 1: string, 2: array<string, mixed>}> */
    public array $securityEvents = [];

    /** @param mixed $payload */
    public function setPostPayload($payload): void
    {
        $this->postPayload = $payload;
    }

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

    /** @return mixed */
    public function getPost()
    {
        return $this->postPayload;
    }

    protected function recordCustomerSecurityEvent($customerId, $eventType, array $meta = []): void
    {
        $this->securityEvents[] = [(int) $customerId, (string) $eventType, $meta];
    }
}

/**
 * @return array{
 *     0: TestableControllerPublicAPIV1PlatformRetention,
 *     1: PlatformRetentionLoadStub,
 *     2: PlatformRetentionRepresentativeModelStub,
 *     3: PlatformRetentionPolicyModelStub
 * }
 */
function platform_retention_controller(
    int $customerId = 41,
    int $companyId = 72,
    int $roleId = 93,
): array {
    $registry = new Registry();
    $representative = new PlatformRetentionRepresentativeModelStub();
    $policy = new PlatformRetentionPolicyModelStub();
    $load = new PlatformRetentionLoadStub($registry, $representative, $policy);

    $registry->set('customer', new PlatformRetentionCustomerStub($customerId, $companyId, $roleId));
    $registry->set('load', $load);

    return [
        new TestableControllerPublicAPIV1PlatformRetention($registry),
        $load,
        $representative,
        $policy,
    ];
}

beforeEach(function () {
    $this->platformRetentionHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->platformRetentionSavedMethod = $this->platformRetentionHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
});

afterEach(function () {
    if ($this->platformRetentionHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->platformRetentionSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - An isolated controller harness with no database or filesystem access.
 *
 * Steps:
 * 1. Request the retention policy without an authenticated customer.
 * 2. Assert the request is rejected before any authorization or policy model is loaded.
 */
test('Platform retention API — unauthenticated callers cannot read policy', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load] = platform_retention_controller(customerId: 0);

    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - An authenticated customer in the personal workspace.
 *
 * Steps:
 * 1. Request the company retention policy without a company context.
 * 2. Assert the request is rejected before the admin role is checked.
 */
test('Platform retention API — personal context cannot read company policy', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load] = platform_retention_controller(companyId: 0);

    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated company member whose active role is not an administrator.
 *
 * Steps:
 * 1. Request the active company's retention policy.
 * 2. Assert access is denied and the policy model is never loaded.
 */
test('Platform retention API — non-admin company members cannot read policy', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $representative] = platform_retention_controller();
    $representative->isAdmin = false;

    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['admin_role_required'])
        ->and($representative->adminChecks)->toBe([[93, 72]])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - An authenticated administrator in company 72 with a stored retention policy.
 *
 * Steps:
 * 1. Read the retention policy.
 * 2. Assert the model is scoped to company 72 and the public response contains only policy fields.
 */
test('Platform retention API — company admin reads only the active company policy', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $representative, $policy] = platform_retention_controller();

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($representative->adminChecks)->toBe([[93, 72]])
        ->and($policy->getCalls)->toBe([72])
        ->and($policy->setCalls)->toBe([])
        ->and($controller->json['data'])->toBe([
            'retention_days' => 30,
            'updated_at' => '2026-08-12 09:30:00',
        ])
        ->and($load->loadedModels)->toBe([
            'company/representative',
            'signing/document_file_version',
        ]);
});

/**
 * Prerequisites:
 * - An authenticated company administrator.
 *
 * Steps:
 * 1. Call the endpoint with an unsupported HTTP method.
 * 2. Assert a stable method error and prove no policy read or write occurs.
 */
test('Platform retention API — unsupported methods do not touch policy state', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    [$controller, , , $policy] = platform_retention_controller();

    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->allowedHeaders)->toBe(['GET', 'PUT'])
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($policy->getCalls)->toBe([])
        ->and($policy->setCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated company administrator.
 *
 * Steps:
 * 1. Submit a body that does not contain `retention_days`.
 * 2. Assert validation fails and neither a policy write nor an audit event occurs.
 */
test('Platform retention API — update requires the retention_days field', function () {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    [$controller, , , $policy] = platform_retention_controller();
    $controller->setPostPayload([]);

    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['retention_days_required'])
        ->and($policy->setCalls)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - An authenticated company administrator.
 *
 * Steps:
 * 1. Submit a non-integer, zero, negative, or out-of-range retention value.
 * 2. Assert each value is rejected without writing or auditing a policy change.
 */
test('Platform retention API — update rejects invalid bounds without a write', function ($value) {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    [$controller, , , $policy] = platform_retention_controller();
    $controller->setPostPayload(['retention_days' => $value]);

    $controller->index();

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toBe(['invalid_retention_days'])
        ->and($policy->setCalls)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
})->with([
    'non-numeric' => 'thirty',
    'fraction' => 1.5,
    'zero' => 0,
    'negative' => -1,
    'over maximum' => 3651,
]);

/**
 * Prerequisites:
 * - Customer 41 is an administrator of active company 72.
 *
 * Steps:
 * 1. Set the retention policy to a valid number of days.
 * 2. Assert the write uses only the active company/customer IDs and emits one sanitized security event.
 * 3. Assert the response is re-read from persisted policy state.
 */
test('Platform retention API — admin updates the active company policy and records one event', function () {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    [$controller, , , $policy] = platform_retention_controller();
    $controller->setPostPayload(['retention_days' => '90']);

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($policy->setCalls)->toBe([[72, 90, 41]])
        ->and($policy->getCalls)->toBe([72])
        ->and($controller->securityEvents)->toBe([[
            41,
            'document_retention_changed',
            ['company_id' => 72, 'retention_days' => 90],
        ]])
        ->and($controller->json['data'])->toBe([
            'retention_days' => 90,
            'updated_at' => '2026-08-12 10:00:00',
        ]);
});

/**
 * Prerequisites:
 * - Customer 41 is an administrator of active company 72 with an existing policy.
 *
 * Steps:
 * 1. Clear the policy with each supported empty representation.
 * 2. Assert the model receives `null`, the event contains no foreign tenant data, and the response confirms removal.
 */
test('Platform retention API — admin can clear the active company policy', function ($value) {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    [$controller, , , $policy] = platform_retention_controller();
    $controller->setPostPayload(['retention_days' => $value]);

    $controller->index();

    expect($policy->setCalls)->toBe([[72, null, 41]])
        ->and($controller->securityEvents)->toBe([[
            41,
            'document_retention_changed',
            ['company_id' => 72, 'retention_days' => null],
        ]])
        ->and($controller->json['data']['retention_days'])->toBeNull();
})->with([
    'JSON null' => null,
    'empty string' => '',
]);
