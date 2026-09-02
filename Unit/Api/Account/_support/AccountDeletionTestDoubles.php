<?php

declare(strict_types=1);

require_once __DIR__ . '/AccountControllerBootstrap.php';

if (! class_exists(AccountDeletionCustomerStub::class, false)) {
    final class AccountDeletionCustomerStub
    {
        public function __construct(
            private readonly int $customerId,
            private readonly int $companyId = 0,
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
    }
}

if (! class_exists(AccountDeletionCustomerModelStub::class, false)) {
    final class AccountDeletionCustomerModelStub
    {
        public bool $deleted = false;

        /** @var array{allowed: bool, reason?: string, blocking_companies?: list<array<string, mixed>>} */
        public array $eligibility = ['allowed' => true];

        /** @var list<int> */
        public array $deletedChecks = [];

        /** @var list<array{0: int, 1: int}> */
        public array $eligibilityChecks = [];

        public function isCustomerAccountDeleted(int $customerId): bool
        {
            $this->deletedChecks[] = $customerId;

            return $this->deleted;
        }

        /** @return array{allowed: bool, reason?: string, blocking_companies?: list<array<string, mixed>>} */
        public function getAccountDeletionEligibility(int $customerId, int $companyId): array
        {
            $this->eligibilityChecks[] = [$customerId, $companyId];

            return $this->eligibility;
        }
    }
}

if (! class_exists(AccountDeletionValidationModelStub::class, false)) {
    final class AccountDeletionValidationModelStub
    {
        public bool $hasSuccessTotp = true;

        /** @var array<string, mixed>|null */
        public ?array $totpRow = ['code' => 'JBSWY3DPEHPK3PXP'];

        /** @var list<int> */
        public array $hasTotpCalls = [];

        /** @var list<int> */
        public array $getTotpCalls = [];

        public function hasSuccessTotp(int $customerId): bool
        {
            $this->hasTotpCalls[] = $customerId;

            return $this->hasSuccessTotp;
        }

        /** @return array<string, mixed>|null */
        public function getSuccessTotpForCustomer(int $customerId): ?array
        {
            $this->getTotpCalls[] = $customerId;

            return $this->totpRow;
        }
    }
}

if (! class_exists(AccountDeletionLoadStub::class, false)) {
    final class AccountDeletionLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly AccountDeletionCustomerModelStub $customerModel,
            private readonly AccountDeletionValidationModelStub $validationModel,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;

            if ($route === 'account/customer') {
                $this->registry->set('model_account_customer', $this->customerModel);
            }
            if ($route === 'tool/validation') {
                $this->registry->set('model_tool_validation', $this->validationModel);
            }
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1AccountDeletion::class, false)) {
    final class TestableControllerPublicAPIV1AccountDeletion extends ControllerPublicAPIV1Account
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @var list<array{0: int, 1: string, 2: bool, 3: array<string, mixed>}> */
        public array $totpAudits = [];

        /** @var list<array{0: int, 1: string, 2: array<string, mixed>}> */
        public array $securityEvents = [];

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

        protected function auditTotpVerification($customerId, $context, $success, array $extra = []): void
        {
            $this->totpAudits[] = [(int) $customerId, (string) $context, (bool) $success, $extra];
        }

        protected function recordCustomerSecurityEvent($customerId, $eventType, array $meta = []): void
        {
            $this->securityEvents[] = [(int) $customerId, (string) $eventType, $meta];
        }
    }
}

if (! function_exists('account_deletion_totp_secret')) {
    function account_deletion_totp_secret(): string
    {
        return 'JBSWY3DPEHPK3PXP';
    }
}

if (! function_exists('account_deletion_valid_totp_code')) {
    function account_deletion_valid_totp_code(): string
    {
        return (new RobThree\Auth\TwoFactorAuth())->getCode(account_deletion_totp_secret());
    }
}

if (! function_exists('account_deletion_controller')) {
    /**
     * @return array{
     *     0: TestableControllerPublicAPIV1AccountDeletion,
     *     1: AccountDeletionLoadStub,
     *     2: AccountDeletionCustomerModelStub,
     *     3: AccountDeletionValidationModelStub,
     *     4: AccountApiCacheStub
     * }
     */
    function account_deletion_controller(int $customerId = 51, int $companyId = 0): array
    {
        AccountClosure::reset();

        $registry = new Registry();
        $customerModel = new AccountDeletionCustomerModelStub();
        $validationModel = new AccountDeletionValidationModelStub();
        $load = new AccountDeletionLoadStub($registry, $customerModel, $validationModel);
        $cache = new AccountApiCacheStub();

        $registry->set('customer', new AccountDeletionCustomerStub($customerId, $companyId));
        $registry->set('load', $load);
        $registry->set('cache', $cache);
        $registry->set('config', new class {
            public function load(string $key): void
            {
            }

            public function get(string $key): mixed
            {
                return null;
            }
        });

        return [
            new TestableControllerPublicAPIV1AccountDeletion($registry),
            $load,
            $customerModel,
            $validationModel,
            $cache,
        ];
    }
}
