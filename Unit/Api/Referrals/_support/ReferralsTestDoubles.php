<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/tests_config.php';
require_once PUBLIC_API . 'referrals.php';
require_once PUBLIC_API . 'referrals/validate.php';
require_once PUBLIC_API . 'referrals/status.php';
require_once PUBLIC_API . 'referrals/campaign.php';
require_once PUBLIC_API . 'referrals/invite.php';
require_once PUBLIC_API . 'referrals/invite/send.php';
require_once PUBLIC_API . 'referrals/remind.php';

if (!class_exists(TestableControllerPublicapiv1Referrals::class)) {
    final class TestableControllerPublicapiv1Referrals extends ControllerPublicapiv1Referrals
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

if (!class_exists(TestableControllerPublicapiv1ReferralsValidate::class)) {
    final class TestableControllerPublicapiv1ReferralsValidate extends ControllerPublicapiv1ReferralsValidate
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

if (!class_exists(TestableControllerPublicapiv1ReferralsStatus::class)) {
    final class TestableControllerPublicapiv1ReferralsStatus extends ControllerPublicapiv1ReferralsStatus
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

if (!class_exists(TestableControllerPublicapiv1ReferralsCampaign::class)) {
    final class TestableControllerPublicapiv1ReferralsCampaign extends ControllerPublicapiv1ReferralsCampaign
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

if (!class_exists(TestableControllerPublicapiv1ReferralsInvite::class)) {
    final class TestableControllerPublicapiv1ReferralsInvite extends ControllerPublicapiv1ReferralsInvite
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @var array<string, mixed>|object */
        private array|object $postPayload = [];

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
        public function setPostPayload(array|object $payload): void
        {
            $this->postPayload = $payload;
        }

        /** @return array<string, mixed>|object */
        public function getPost()
        {
            return $this->postPayload;
        }
    }
}

if (!class_exists(TestableControllerPublicapiv1ReferralsInviteSend::class)) {
    final class TestableControllerPublicapiv1ReferralsInviteSend extends ControllerPublicapiv1ReferralsInviteSend
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @var array<string, mixed>|object */
        private array|object $postPayload = [];

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
        public function setPostPayload(array|object $payload): void
        {
            $this->postPayload = $payload;
        }

        /** @return array<string, mixed>|object */
        public function getPost()
        {
            return $this->postPayload;
        }
    }
}

if (!class_exists(TestableControllerPublicapiv1ReferralsRemind::class)) {
    final class TestableControllerPublicapiv1ReferralsRemind extends ControllerPublicapiv1ReferralsRemind
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @var array<string, mixed>|object */
        private array|object $postPayload = [];

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
        public function setPostPayload(array|object $payload): void
        {
            $this->postPayload = $payload;
        }

        /** @return array<string, mixed>|object */
        public function getPost()
        {
            return $this->postPayload;
        }
    }
}

final class ReferralsCustomerStub
{
    public function __construct(
        private readonly int $customerId,
        private readonly string $firstName = 'Pat',
    ) {
    }

    public function getId(): int
    {
        return $this->customerId;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }
}

final class ReferralsConfigStub
{
    public function __construct(
        private readonly int $remindCooldownSeconds = 0,
        private readonly int $languageId = 1,
    ) {
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'referral_remind_cooldown_seconds' => $this->remindCooldownSeconds,
            'config_language_id' => $this->languageId,
            default => null,
        };
    }
}

final class ReferralModelStub
{
    /** @var array{total: int, items: list<array<string, mixed>>} */
    public array $listResult = ['total' => 0, 'items' => []];

    /** @var array<int, list<array<string, mixed>>> */
    public array $rewardsByReferralIds = [];

    /** @var array<int, string|null> */
    public array $lastRemindByRef = [];

    /** @var array<string, string|null> */
    public array $lastInviteRemindByEmail = [];

    /** @var array<string, mixed>|null */
    public ?array $inviteByCode = null;

    /** @var array<string, mixed>|null */
    public ?array $activeInvite = ['code' => 'REFCODE1', 'active' => true, 'invite_id' => 1];

    public bool $rotateCalled = false;

    /** @var array<string, mixed>|null */
    public ?array $asReferred = null;

    /** @var array{pending?: int, fulfilled?: int, cancelled?: int} */
    public array $countsByStatus = ['pending' => 0, 'fulfilled' => 0, 'cancelled' => 0];

    /** @var array{total?: int} */
    public array $inviteCounts = ['total' => 0];

    /** @var array<string, mixed>|null */
    public ?array $defaultCampaign = ['campaign_uuid' => 'camp-1'];

    /** @var array{ok: bool, reason?: string} */
    public array $canSendResult = ['ok' => true];

    /** @var array<string, mixed> */
    public array $recordInviteSendResult = ['invite_send_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'];

    /** @var array<string, mixed>|null */
    public ?array $referralRowForReferrer = null;

    /** @var array<string, mixed>|null */
    public ?array $inviteSendRowForReferrer = null;

    public ?string $lastRemindForReferral = null;

    public ?string $lastInviteRemindForEmail = null;

    public function listReferralActivityForReferrerPaged(
        int $referrer_id,
        $status,
        $search,
        $sort,
        $order,
        int $page,
        int $per_page,
    ): array {
        return $this->listResult;
    }

    public function getReferrerReferralRewardsByReferralIds(int $referrer_id, array $referral_ids): array
    {
        return $this->rewardsByReferralIds;
    }

    public function getLastRemindCreatedAtByReferralIds(int $referrer_id, array $referral_ids): array
    {
        return $this->lastRemindByRef;
    }

    public function getLastInviteRemindCreatedAtByEmails(int $referrer_id, array $emails): array
    {
        return $this->lastInviteRemindByEmail;
    }

    public function getInviteByCode(string $code): ?array
    {
        return $this->inviteByCode;
    }

    public function getOrCreateActiveInviteForReferrer(int $referrer_id): ?array
    {
        return $this->activeInvite;
    }

    public function rotateInviteForReferrer(int $referrer_id): ?array
    {
        $this->rotateCalled = true;

        return $this->activeInvite;
    }

    public function getReferralForReferred(int $customer_id): ?array
    {
        return $this->asReferred;
    }

    public function countReferralsByStatusForReferrer(int $referrer_id): array
    {
        return $this->countsByStatus;
    }

    public function countInviteSendsForReferrer(int $referrer_id): array
    {
        return $this->inviteCounts;
    }

    public function getDefaultCampaignWithRewards(): ?array
    {
        return $this->defaultCampaign;
    }

    public function canSendInviteEmail(int $referrer_customer_id, string $recipient_email, $ip_address = null): array
    {
        return $this->canSendResult;
    }

    public function recordInviteEmailSend(
        int $referrer_id,
        ?int $invite_id,
        string $email,
        string $status,
        ?string $error_message,
        $ip,
        $ua,
    ): array {
        return $this->recordInviteSendResult;
    }

    public function getReferralRowForReferrerByUuid(int $referrer_id, string $uuid): array
    {
        return $this->referralRowForReferrer ?? [];
    }

    public function getInviteSendRowForReferrerByUuid(int $referrer_id, string $uuid): array
    {
        return $this->inviteSendRowForReferrer ?? [];
    }

    public function getLastRemindCreatedAtForReferral(int $referrer_id, int $referral_id): ?string
    {
        return $this->lastRemindForReferral;
    }

    public function getLastInviteRemindCreatedAtForEmail(int $referrer_id, string $email): ?string
    {
        return $this->lastInviteRemindForEmail;
    }

    public function recordRemindSend(int $referrer_id, int $referral_id, string $email, string $status): void
    {
    }

    public function recordInviteRemindSend(int $referrer_id, string $email, string $status): void
    {
    }
}

final class RoleEntitlementGrantStub
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $rewardsByReferralIds = [];

    /** @var array<string, int> */
    public array $rewardTotals = [];

    public function getReferrerReferralRewardsByReferralIds(int $referrer_id, array $referral_ids): array
    {
        return $this->rewardsByReferralIds;
    }

    public function getReferrerReferralRewardTotalsForCustomer(int $customer_id): array
    {
        return $this->rewardTotals;
    }
}

final class ReferralsAccountCustomerModelStub
{
    public function __construct(private readonly int $customerIdByEmail = 0)
    {
    }

    public function getCustomerIdByEmail(string $email): int
    {
        return $this->customerIdByEmail;
    }
}

final class ReferralsLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly ReferralModelStub $referralModel,
        private readonly ?RoleEntitlementGrantStub $grantModel = null,
        private readonly ?ReferralsAccountCustomerModelStub $accountCustomerModel = null,
    ) {
    }

    public function model(string $route): void
    {
        $this->loadedModels[] = $route;
        if ($route === 'referral/referral') {
            $this->registry->set('model_referral_referral', $this->referralModel);
        } elseif ($route === 'billing/role_entitlement_grant' && $this->grantModel !== null) {
            $this->registry->set('model_billing_role_entitlement_grant', $this->grantModel);
        } elseif ($route === 'account/customer' && $this->accountCustomerModel !== null) {
            $this->registry->set('model_account_customer', $this->accountCustomerModel);
        }
    }
}

/**
 * @return array{0: Registry, 1: ReferralsLoadStub}
 */
function ref_registry_with_models(
    ReferralsCustomerStub $customer,
    ReferralModelStub $referralModel,
    ?RoleEntitlementGrantStub $grantModel = null,
    ?ReferralsAccountCustomerModelStub $accountCustomerModel = null,
    ?ReferralsConfigStub $config = null,
): array {
    $registry = new Registry();
    $load = new ReferralsLoadStub($registry, $referralModel, $grantModel, $accountCustomerModel);
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', $config ?? new ReferralsConfigStub());
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);

    return [$registry, $load];
}
