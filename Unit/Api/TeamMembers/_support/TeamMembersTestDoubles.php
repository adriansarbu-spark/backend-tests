<?php

declare(strict_types=1);

if (!class_exists(TestableControllerPublicAPIV1TeamMembers::class)) {
    /**
     * Harness for {@see ControllerPublicAPIV1TeamMembers}: counts hooks and supplies a deterministic POST body.
     */
    final class TestableControllerPublicAPIV1TeamMembers extends ControllerPublicAPIV1TeamMembers
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public int $getPostCalls = 0;

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
    }
}

/** Authenticated customer for team members list and terminate flows. */
final class TeamMembersCustomerStub
{
    public function __construct(
        private readonly int $customerId,
        private readonly int $companyId,
        private readonly int $roleId = 1,
        private readonly ?string $totpSecret = null,
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

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }
}

final class TeamMembersRepresentativeStub
{
    public function __construct(private readonly bool $isAdmin)
    {
    }

    public function isCompanyAdminRole(int $customerRoleId, int $companyId): bool
    {
        return $this->isAdmin;
    }
}

/** Captures list/terminate calls; return values are set per test via properties. */
final class TeamMembersTeamInvitationModelStub
{
    /** @var array<string, mixed> */
    public array $lastMembersPagedArgs = [];

    /** @var array<string, mixed> */
    public array $listResult = [
        'items' => [],
        'page' => 1,
        'per_page' => 20,
        'total' => 0,
    ];

    public string $companyUuid = '00000000-0000-0000-0000-000000000001';

    /** @var array<string, mixed> */
    public array $terminateResult = [];

    public function getMembersForCompanyPaged(array $args): array
    {
        $this->lastMembersPagedArgs = $args;

        return $this->listResult;
    }

    public function getCompanyUuid(int $companyId): string
    {
        return $this->companyUuid;
    }

    public function terminateMember(string $roleUuid, int $companyId, int $customerId, string $reason): array
    {
        return $this->terminateResult;
    }
}
