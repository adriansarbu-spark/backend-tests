<?php

declare(strict_types=1);

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

if (! defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (! defined('RA_AUDIT_CHANNEL')) {
    define('RA_AUDIT_CHANNEL', 'ra_audit');
}

require_once PUBLIC_API . 'account/totp.php';

if (! class_exists(AccountApiCacheStub::class, false)) {
    final class AccountApiCacheStub
    {
        /** @var array<string, mixed> */
        public array $store = [];

        /** @var list<string> */
        public array $deleted = [];

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): void
        {
            $this->store[$key] = $value;
        }

        public function delete(string $key): void
        {
            $this->deleted[] = $key;
            unset($this->store[$key]);
        }
    }
}

if (! class_exists('ModelToolValidation', false)) {
    class ModelToolValidation
    {
        public const STATUS_NOT_STARTED = 'not_started';
        public const STATUS_READY_TO_START = 'ready_to_start';
        public const STATUS_IN_PROGRESS = 'in_progress';
        public const STATUS_SUCCESS = 'success';
        public const STATUS_EXPIRED = 'expired';
    }
}

if (! class_exists(AccountTotpCustomerStub::class, false)) {
    final class AccountTotpCustomerStub
    {
        public function __construct(
            private readonly int $customerId = 51,
            private readonly string $email = 'user@example.com',
            private readonly bool $totpSet = false,
        ) {
        }

        public function getId(): int
        {
            return $this->customerId;
        }

        public function getEmail(): string
        {
            return $this->email;
        }

        public function isTotpSet(): bool
        {
            return $this->totpSet;
        }
    }
}

if (! class_exists(AccountTotpValidationModelStub::class, false)) {
    final class AccountTotpValidationModelStub
    {
        public bool $hasSuccessTotp = false;

        /** @var array<string, mixed>|null */
        public ?array $pendingRow = null;

        /** @var array<string, mixed>|null */
        public ?array $successRow = null;

        public int $recentSetups = 0;

        public bool $pendingExpired = false;

        public int $nextValidationId = 101;

        /** @var list<int> */
        public array $hasTotpCalls = [];

        /** @var list<array{0: int, 1: mixed}> */
        public array $getTotpCalls = [];

        /** @var list<int> */
        public array $getSuccessCalls = [];

        /** @var list<array{0: int, 1: string}> */
        public array $addCalls = [];

        /** @var list<int> */
        public array $completeCalls = [];

        /** @var list<int> */
        public array $expireCalls = [];

        /** @var list<array{0: int, 1: int}> */
        public array $countRecentCalls = [];

        /** @var list<array{0: int, 1: int}> */
        public array $isExpiredCalls = [];

        public function hasSuccessTotp(int $customerId): bool
        {
            $this->hasTotpCalls[] = $customerId;

            return $this->hasSuccessTotp;
        }

        public function countRecentTotpSetups(int $customerId, int $windowMinutes): int
        {
            $this->countRecentCalls[] = [$customerId, $windowMinutes];

            return $this->recentSetups;
        }

        public function expireTotpValidations(int $customerId): void
        {
            $this->expireCalls[] = $customerId;
            $this->pendingRow = null;
        }

        public function addTotpValidation(int $customerId, string $secret): int
        {
            $this->addCalls[] = [$customerId, $secret];
            $id = $this->nextValidationId++;
            $this->pendingRow = [
                'validation_id' => $id,
                'customer_id' => $customerId,
                'code' => $secret,
                'status' => ModelToolValidation::STATUS_IN_PROGRESS,
                'date_added' => gmdate('Y-m-d H:i:s'),
            ];

            return $id;
        }

        /** @return array<string, mixed>|null */
        public function getTotpValidation(int $customerId, $status = null): ?array
        {
            $this->getTotpCalls[] = [$customerId, $status];

            if ($status === ModelToolValidation::STATUS_IN_PROGRESS) {
                return $this->pendingRow;
            }
            if ($status === ModelToolValidation::STATUS_SUCCESS) {
                return $this->successRow;
            }

            return $this->successRow ?? $this->pendingRow;
        }

        public function isValidationExpired(array $validation, int $expiryMinutes): bool
        {
            $this->isExpiredCalls[] = [(int) ($validation['validation_id'] ?? 0), $expiryMinutes];

            return $this->pendingExpired;
        }

        public function completeValidation(int $validationId): void
        {
            $this->completeCalls[] = $validationId;
            if ($this->pendingRow && (int) $this->pendingRow['validation_id'] === $validationId) {
                $this->successRow = $this->pendingRow;
                $this->successRow['status'] = ModelToolValidation::STATUS_SUCCESS;
                $this->pendingRow = null;
                $this->hasSuccessTotp = true;
            }
        }

        /** @return array<string, mixed>|null */
        public function getSuccessTotpForCustomer(int $customerId): ?array
        {
            $this->getSuccessCalls[] = $customerId;

            return $this->successRow;
        }
    }
}

if (! class_exists(AccountTotpDbStub::class, false)) {
    final class AccountTotpDbStub
    {
        /** @var list<string> */
        public array $queries = [];

        public function escape($value): string
        {
            return addslashes((string) $value);
        }

        public function query(string $sql): object
        {
            $this->queries[] = $sql;

            return (object) ['num_rows' => 0, 'rows' => [], 'row' => []];
        }
    }
}

if (! class_exists(AccountTotpLoadStub::class, false)) {
    final class AccountTotpLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly AccountTotpValidationModelStub $validationModel,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'tool/validation') {
                $this->registry->set('model_tool_validation', $this->validationModel);
            }
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1AccountTotp::class, false)) {
    final class TestableControllerPublicAPIV1AccountTotp extends ControllerPublicAPIV1AccountTotp
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

if (! function_exists('account_totp_code_for')) {
    function account_totp_code_for(string $secret): string
    {
        return (new RobThree\Auth\TwoFactorAuth())->getCode($secret);
    }
}

if (! function_exists('account_totp_controller')) {
    /**
     * @return array{
     *     0: TestableControllerPublicAPIV1AccountTotp,
     *     1: AccountTotpLoadStub,
     *     2: AccountTotpValidationModelStub,
     *     3: AccountApiCacheStub,
     *     4: AccountTotpDbStub
     * }
     */
    function account_totp_controller(
        int $customerId = 51,
        string $email = 'user@example.com',
        bool $totpSet = false,
        string $action = '',
    ): array {
        $registry = new Registry();
        $validationModel = new AccountTotpValidationModelStub();
        $load = new AccountTotpLoadStub($registry, $validationModel);
        $cache = new AccountApiCacheStub();
        $db = new AccountTotpDbStub();

        $registry->set('customer', new AccountTotpCustomerStub($customerId, $email, $totpSet));
        $registry->set('load', $load);
        $registry->set('cache', $cache);
        $registry->set('db', $db);
        $registry->set('request', (object) ['get' => ['action' => $action], 'server' => []]);
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
            new TestableControllerPublicAPIV1AccountTotp($registry),
            $load,
            $validationModel,
            $cache,
            $db,
        ];
    }
}
