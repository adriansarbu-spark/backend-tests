<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/tests_config.php';

if (! defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

require_once PUBLIC_API . 'team/invitations.php';
require_once PUBLIC_API . 'team/invitations/accept.php';
require_once PUBLIC_API . 'team/invitations/preview.php';
require_once PUBLIC_API . 'team/invitations/resend.php';
require_once PUBLIC_API . 'team/invitations/revoke.php';

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

if (!class_exists(TestableControllerPublicAPIV1TeamInvitationsAccept::class)) {
    final class TestableControllerPublicAPIV1TeamInvitationsAccept extends ControllerPublicAPIV1TeamInvitationsAccept
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

if (!class_exists(TestableControllerPublicAPIV1TeamInvitationsPreview::class)) {
    final class TestableControllerPublicAPIV1TeamInvitationsPreview extends ControllerPublicAPIV1TeamInvitationsPreview
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

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
    }
}

if (!class_exists(TestableControllerPublicAPIV1TeamInvitationsResend::class)) {
    /**
     * Harness for resend: captures POST, stubs send-rate-limit, and lets EmailQueue run against a fake DB.
     */
    final class TestableControllerPublicAPIV1TeamInvitationsResend extends ControllerPublicAPIV1TeamInvitationsResend
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @var array<string, mixed> */
        public array $sendAllowedResult = [
            'ok' => true,
            'rate_limit_reservation' => ['count_key' => 'team_invitation:count:10:hash'],
        ];

        /** @var list<array<string, mixed>> */
        public array $releasedReservations = [];

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

        protected function assertTeamInvitationSendAllowed(
            $company_id,
            $invited_email,
            $is_resend,
            $last_sent_at = null,
            $role_code = '',
        ): array {
            return $this->sendAllowedResult;
        }

        protected function releaseSendRateLimitReservation(array $reservation)
        {
            $this->releasedReservations[] = $reservation;
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1TeamInvitationsRevoke::class)) {
    final class TestableControllerPublicAPIV1TeamInvitationsRevoke extends ControllerPublicAPIV1TeamInvitationsRevoke
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

/** Admin customer for invitations API (company admin gate). */
final class TeamInvitationsAdminCustomerStub
{
    public function __construct(
        private readonly int $customerId,
        private readonly int $companyId,
        private readonly int $roleId,
        private readonly string $roleCode = 'admin',
        private readonly string $firstName = 'Pat',
        private readonly string $email = 'pat@example.com',
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

    public function getRoleCode(): string
    {
        return $this->roleCode;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
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

    /** @var array{token: string, customer_id: int, email: string}|null */
    public ?array $lastAcceptArgs = null;

    /** @var array<string, mixed> */
    public array $acceptResult = ['ok' => false, 'reason' => 'invitation_not_found'];

    public ?string $lastPreviewToken = null;

    /** @var array<string, mixed> */
    public array $previewResult = ['ok' => false, 'reason' => 'invitation_not_found'];

    /** @var array{0: int, 1: string}|null */
    public ?array $lastValidateResendArgs = null;

    /** @var array<string, mixed> */
    public array $validateResendResult = ['ok' => false, 'reason' => 'invitation_not_found'];

    public ?int $lastRotateInvitationId = null;

    /** @var array<string, mixed>|null */
    public ?array $rotateResult = null;

    /** @var array{0: int, 1: string}|null */
    public ?array $lastRevokeArgs = null;

    /** @var array<string, mixed> */
    public array $revokeResult = ['ok' => false, 'reason' => 'invitation_not_found'];

    public string $roleLabel = 'Employee';

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

    public function acceptInvitationByToken(string $token, int $customerId, string $acceptedEmail): array
    {
        $this->lastAcceptArgs = [
            'token' => $token,
            'customer_id' => $customerId,
            'email' => $acceptedEmail,
        ];

        return $this->acceptResult;
    }

    public function getPreviewByToken(string $token): array
    {
        $this->lastPreviewToken = $token;

        return $this->previewResult;
    }

    public function validateInvitationForResend(int $companyId, string $invitationUuid): array
    {
        $this->lastValidateResendArgs = [$companyId, $invitationUuid];

        return $this->validateResendResult;
    }

    public function rotateInvitationTokenForResend(int $invitationId): ?array
    {
        $this->lastRotateInvitationId = $invitationId;

        return $this->rotateResult;
    }

    public function revokeInvitation(int $companyId, string $invitationUuid): array
    {
        $this->lastRevokeArgs = [$companyId, $invitationUuid];

        return $this->revokeResult;
    }

    public function getRoleLabelForEmail(string $roleCode, int $languageId): string
    {
        return $this->roleLabel;
    }
}

final class TeamInvitationsLanguageModelStub
{
    public function getLanguages(): array
    {
        return ['en-gb' => ['language_id' => 1]];
    }
}

final class TeamInvitationsEntitlementAssignmentStub
{
    public function __construct(private readonly bool $isCompanyAdmin = true)
    {
    }

    public function isRoleAdminForCompany(int $roleId, int $companyId): bool
    {
        return $this->isCompanyAdmin;
    }
}

final class TeamInvitationsBrandingModelStub
{
    /** @return array{display_name: null, logo_url: null} */
    public function getEmailBranding(int $companyId): array
    {
        return ['display_name' => null, 'logo_url' => null];
    }
}

final class TeamInvitationsDbStub
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(public int $lastId = 1)
    {
    }

    public function query(string $sql): object
    {
        $this->queries[] = $sql;

        return (object) ['num_rows' => 0, 'row' => [], 'rows' => []];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function getLastId(): int
    {
        return $this->lastId;
    }
}

final class TeamInvitationsLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    /** @var list<string> */
    public array $loadedLibraries = [];

    /** @var list<string> */
    public array $loadedControllers = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly TeamInvitationsModelStub $model,
        private readonly TeamInvitationsEntitlementAssignmentStub $entitlementAssignment,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if ($route === 'account/team_invitation') {
            $this->registry->set('model_account_team_invitation', $this->model);
        } elseif ($route === 'billing/entitlement_assignment') {
            $this->registry->set('model_billing_entitlement_assignment', $this->entitlementAssignment);
        } elseif ($route === 'company/branding') {
            $this->registry->set('model_company_branding', new TeamInvitationsBrandingModelStub());
        } elseif ($route === 'localisation/language') {
            $this->registry->set('model_localisation_language', new TeamInvitationsLanguageModelStub());
        }
    }

    public function library(string $route): void
    {
        $this->loadedLibraries[] = $route;
    }

    public function controller(string $route): void
    {
        $this->loadedControllers[] = $route;
    }
}

/**
 * @return array{0: Registry, 1: TeamInvitationsLoadStub, 2: TeamInvitationsDbStub}
 */
function ti_registry_with_model(
    object $customer,
    TeamInvitationsModelStub $model,
    ?TeamInvitationsDbStub $db = null,
    bool $isCompanyAdmin = true,
): array {
    $registry = new Registry();
    $load = new TeamInvitationsLoadStub(
        $registry,
        $model,
        new TeamInvitationsEntitlementAssignmentStub($isCompanyAdmin),
    );
    $db ??= new TeamInvitationsDbStub();
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('db', $db);
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
                'mailgun_max_retries' => 0,
                default => null,
            };
        }
    });
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);

    return [$registry, $load, $db];
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

function ti_make_accept_controller(
    Registry $registry,
    bool $grantPermission = true,
): TestableControllerPublicAPIV1TeamInvitationsAccept {
    $controller = new TestableControllerPublicAPIV1TeamInvitationsAccept($registry);
    if ($grantPermission) {
        $controller->permission = (object) [
            'post' => ['publicapi/v1/team/invitations/accept'],
        ];
    }

    return $controller;
}

function ti_make_preview_controller(Registry $registry): TestableControllerPublicAPIV1TeamInvitationsPreview
{
    return new TestableControllerPublicAPIV1TeamInvitationsPreview($registry);
}

function ti_make_resend_controller(
    Registry $registry,
    bool $grantPermission = true,
): TestableControllerPublicAPIV1TeamInvitationsResend {
    $controller = new TestableControllerPublicAPIV1TeamInvitationsResend($registry);
    if ($grantPermission) {
        $controller->permission = (object) [
            'post' => ['publicapi/v1/team/invitations/resend'],
        ];
    }

    return $controller;
}

function ti_make_revoke_controller(
    Registry $registry,
    bool $grantPermission = true,
): TestableControllerPublicAPIV1TeamInvitationsRevoke {
    $controller = new TestableControllerPublicAPIV1TeamInvitationsRevoke($registry);
    if ($grantPermission) {
        $controller->permission = (object) [
            'post' => ['publicapi/v1/team/invitations/revoke'],
        ];
    }

    return $controller;
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ti_pending_resend_row(array $overrides = []): array
{
    return array_merge([
        'invitation_id' => 7,
        'invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'invited_email' => 'invitee@example.com',
        'role_code' => 'employee',
        'last_sent_at' => null,
        'company_name' => 'Acme',
        'role_name' => 'Employee',
    ], $overrides);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ti_rotated_invitation(array $overrides = []): array
{
    return array_merge([
        'token' => 'rotated-secret',
        'invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'invited_email' => 'invitee@example.com',
        'role_code' => 'employee',
        'company_name' => 'Acme',
        'role_name' => 'Employee',
    ], $overrides);
}
