<?php

declare(strict_types=1);

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

require_once PUBLIC_API . 'account/active_role.php';

if (! class_exists(ActiveRoleCustomerStub::class, false)) {
    final class ActiveRoleCustomerStub
    {
        public function __construct(private readonly int $customerId = 51)
        {
        }

        public function getId(): int
        {
            return $this->customerId;
        }
    }
}

if (! class_exists(ActiveRoleCustomerRoleModelStub::class, false)) {
    final class ActiveRoleCustomerRoleModelStub
    {
        /** @var array<string, int> role_uuid => customer_role_id */
        public array $ownedRoles = [];

        /** @var array<int, array{role_uuid: string, company_uuid: string}> */
        public array $uuidsByRoleId = [];

        /** @var list<int> */
        public array $ensureMissingUuidsCalls = [];

        /** @var list<array{0: string, 1: int}> */
        public array $lookupCalls = [];

        /** @var list<int> */
        public array $uuidLookupCalls = [];

        public function ensureMissingUuids(int $customerId): void
        {
            $this->ensureMissingUuidsCalls[] = $customerId;
        }

        public function getCustomerRoleIdByUuidForCustomer(string $roleUuid, int $customerId): int
        {
            $this->lookupCalls[] = [$roleUuid, $customerId];

            return $this->ownedRoles[$roleUuid] ?? 0;
        }

        /** @return array{role_uuid: string, company_uuid: string}|null */
        public function getUuidsForCustomerRoleId(int $roleId): ?array
        {
            $this->uuidLookupCalls[] = $roleId;

            return $this->uuidsByRoleId[$roleId] ?? null;
        }
    }
}

if (! class_exists(ActiveRoleCompanyModelStub::class, false)) {
    final class ActiveRoleCompanyModelStub
    {
        /** @var list<int> */
        public array $setCalls = [];

        public function setUserCompanyAndRole($roleId): void
        {
            $this->setCalls[] = (int) $roleId;
        }
    }
}

if (! class_exists(ActiveRoleLoadStub::class, false)) {
    final class ActiveRoleLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly ActiveRoleCustomerRoleModelStub $roleModel,
            private readonly ActiveRoleCompanyModelStub $companyModel,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'account/customer_role') {
                $this->registry->set('model_account_customer_role', $this->roleModel);
            }
            if ($route === 'account/company') {
                $this->registry->set('model_account_company', $this->companyModel);
            }
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1AccountActiveRole::class, false)) {
    final class TestableControllerPublicAPIV1AccountActiveRole extends ControllerPublicAPIV1AccountActiveRole
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @param array<string, mixed> $payload */
        public function setPostPayload(array $payload): void
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

        /** @return array<string, mixed> */
        public function getPost(): array
        {
            return $this->postPayload;
        }
    }
}

if (! function_exists('active_role_controller')) {
    /**
     * @return array{
     *     0: TestableControllerPublicAPIV1AccountActiveRole,
     *     1: ActiveRoleLoadStub,
     *     2: ActiveRoleCustomerRoleModelStub,
     *     3: ActiveRoleCompanyModelStub
     * }
     */
    function active_role_controller(int $customerId = 51): array
    {
        $registry = new Registry();
        $roleModel = new ActiveRoleCustomerRoleModelStub();
        $companyModel = new ActiveRoleCompanyModelStub();
        $load = new ActiveRoleLoadStub($registry, $roleModel, $companyModel);

        $registry->set('customer', new ActiveRoleCustomerStub($customerId));
        $registry->set('load', $load);

        return [
            new TestableControllerPublicAPIV1AccountActiveRole($registry),
            $load,
            $roleModel,
            $companyModel,
        ];
    }
}
