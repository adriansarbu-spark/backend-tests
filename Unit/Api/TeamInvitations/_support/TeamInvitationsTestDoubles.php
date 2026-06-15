<?php

declare(strict_types=1);

if (!class_exists(TestableControllerPublicAPIV1TeamInvitations::class)) {
    /**
     * Harness for {@see ControllerPublicAPIV1TeamInvitations}: avoids real email queue and captures POST.
     */
    final class TestableControllerPublicAPIV1TeamInvitations extends ControllerPublicAPIV1TeamInvitations
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public int $getPostCalls = 0;

        public bool $queueInvitationEmailResult = true;

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
            ++$this->getPostCalls;

            return $this->postPayload;
        }

        protected function queueInvitationEmail($invitation): bool
        {
            return $this->queueInvitationEmailResult;
        }
    }
}

/** Admin customer for invitations API (company admin gate). */
final class TeamInvitationsAdminCustomerStub
{
    public function __construct(
        private readonly int $customerId,
        private readonly int $companyId,
        private readonly int $roleId,
        private readonly string $roleCode = 'admin',
        private readonly string $firstName = 'Pat',
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

    public function getRoleCode(): string
    {
        return $this->roleCode;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }
}

final class TeamInvitationsModelStub
{
    /** @var array<string, mixed> */
    public array $lastInvitationsPagedArgs = [];

    /** @var array<string, mixed> */
    public array $listResult = [
        'items' => [],
        'page' => 1,
        'per_page' => 20,
        'total' => 0,
    ];

    public string $companyUuid = '10000000-0000-0000-0000-000000000002';

    public bool $validEmail = true;

    public bool $allowedRole = true;

    /** @var array<string, mixed>|null */
    public ?array $createResult = null;

    /** @var list<array{0: string, 1: bool, 2: string|null}> */
    public array $recordEmailSendCalls = [];

    public function getInvitationsForCompanyPaged(array $args): array
    {
        $this->lastInvitationsPagedArgs = $args;

        return $this->listResult;
    }

    public function getCompanyUuid(int $companyId): string
    {
        return $this->companyUuid;
    }

    public function isValidInviteEmail(string $email): bool
    {
        return $this->validEmail;
    }

    public function isAllowedRoleCode(string $roleCode): bool
    {
        return $this->allowedRole;
    }

    public function isAdminInviteRoleCode(string $roleCode): bool
    {
        return mb_strtolower(trim($roleCode)) === 'admin';
    }

    public function createOrRefreshPendingInvitation(
        int $companyId,
        int $inviterCustomerId,
        int $inviterRoleId,
        string $email,
        string $roleCode,
    ): ?array {
        return $this->createResult;
    }

    public function recordEmailSend(string $invitationUuid, bool $queued, ?string $errorCode): void
    {
        $this->recordEmailSendCalls[] = [$invitationUuid, $queued, $errorCode];
    }
}

final class TeamInvitationsLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly TeamInvitationsModelStub $model,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if ($route === 'account/team_invitation') {
            $this->registry->set('model_account_team_invitation', $this->model);
        }
    }
}

/**
 * @return array{0: Registry, 1: TeamInvitationsLoadStub}
 */
function ti_registry_with_model(object $customer, TeamInvitationsModelStub $model): array
{
    $registry = new Registry();
    $load = new TeamInvitationsLoadStub($registry, $model);
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', new class {
        public function load(string $key): void
        {
        }

        public function get(string $key): mixed
        {
            return match ($key) {
                'team_invitation_send_limit_per_company_recipient' => 100,
                'team_invitation_send_window_secs' => 3600,
                'team_invitation_resend_min_interval_secs' => 0,
                'config_language_id' => 1,
                default => null,
            };
        }
    });
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);

    return [$registry, $load];
}

/**
 * Team invitations controller with route permissions granted (Dimension 3 gate).
 */
function ti_make_controller(Registry $registry): TestableControllerPublicAPIV1TeamInvitations
{
    $controller = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $controller->permission = (object) [
        'get'  => ['publicapi/v1/team/invitations'],
        'post' => ['publicapi/v1/team/invitations'],
    ];

    return $controller;
}
