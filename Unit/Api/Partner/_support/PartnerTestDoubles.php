<?php

declare(strict_types=1);

/**
 * Unit harness for hosted partner onboard + join routes.
 *
 * Reuses sponsorship customer/program/link stubs from SponsorshipsTestDoubles.
 * Rate-limit and send-limiter hooks are overridable on the testable controllers.
 */

require_once dirname(__DIR__, 2) . '/Sponsorships/_support/SponsorshipsTestDoubles.php';

require_once PUBLIC_API . 'partner/onboard.php';
require_once PUBLIC_API . 'partner/join.php';

trait PartnerControllerHarness
{
    public int $checkPluginCalls = 0;

    public int $sendResponseCalls = 0;

    /** @var array<string, mixed>|object */
    private array|object $partnerPost = [];

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
        $this->partnerPost = $payload;
    }

    /** @return array<string, mixed>|object */
    public function getPost()
    {
        return $this->partnerPost;
    }
}

final class TestableControllerPublicAPIV1PartnerOnboard extends ControllerPublicAPIV1PartnerOnboard
{
    use PartnerControllerHarness;

    /** @var array<string, mixed> */
    public array $sendAllowedResult = [
        'ok' => true,
        'rate_limit_reservation' => ['count_key' => 'partner_onboard_resend:count:hash'],
    ];

    /** @var list<array<string, mixed>> */
    public array $releasedReservations = [];

    /** @var list<array<string, mixed>> */
    public array $committedReservations = [];

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

    protected function commitTeamInvitationRateLimit(array $reservation)
    {
        $this->committedReservations[] = $reservation;
    }
}

final class TestableControllerPublicAPIV1PartnerJoin extends ControllerPublicAPIV1PartnerJoin
{
    use PartnerControllerHarness;

    /** @var array{ok: bool}|null When set, overrides sliding-window checks. */
    public ?array $rateLimitResult = null;

    /** @var list<array{key: string, max: int, window: int}> */
    public array $rateLimitAttempts = [];

    /**
     * @param mixed $cache
     * @return array{ok: bool}
     */
    protected function reserveSlidingWindowRateLimitAttempt($cache, $cacheKey, $maxAttempts, $windowSeconds): array
    {
        $this->rateLimitAttempts[] = [
            'key' => (string) $cacheKey,
            'max' => (int) $maxAttempts,
            'window' => (int) $windowSeconds,
        ];

        if ($this->rateLimitResult !== null) {
            return $this->rateLimitResult;
        }

        return ['ok' => true];
    }
}

/**
 * In-memory cache stand-in so join enters the rate-limit branch.
 */
final class PartnerCacheStub
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }
}

/**
 * @param array<string, mixed> $query Must include onboard_token / action / program_code as needed
 * @param array<string, object> $overrides
 *
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: array{program: SponsorshipsModelStub, link: SponsorshipsModelStub, branding: SponsorshipsBrandingModelStub}}
 */
function partner_registry(
    ?SponsorshipsCustomerStub $customer = null,
    array $query = [],
    array $overrides = [],
    ?PartnerCacheStub $cache = null,
): array {
    $programModel = $overrides['partner/program'] ?? sponsorships_program_model();
    $linkModel = $overrides['partner/customer_link'] ?? new SponsorshipsModelStub([]);
    $branding = $overrides['company/branding'] ?? new SponsorshipsBrandingModelStub();

    [$registry, $load] = sponsorships_registry(
        customer: $customer ?? new SponsorshipsCustomerStub(0, 0, 0),
        models: [
            'partner/program' => $programModel,
            'partner/customer_link' => $linkModel,
            'company/branding' => $branding,
            'billing/entitlement_assignment' => $overrides['billing/entitlement_assignment']
                ?? new SponsorshipsModelStub(['isRoleAdminForCompany' => false]),
        ],
        query: $query,
    );

    if ($cache !== null) {
        $registry->set('cache', $cache);
    }

    return [
        $registry,
        $load,
        [
            'program' => $programModel,
            'link' => $linkModel,
            'branding' => $branding,
        ],
    ];
}

/**
 * @template T of PublicAPIController
 *
 * @param class-string<T>             $class
 * @param array<string, list<string>> $permissions
 *
 * @return T
 */
function partner_controller(
    string $class,
    Registry $registry,
    array $permissions = [],
): PublicAPIController {
    $controller = new $class($registry);
    $controller->permission = (object) $permissions;

    return $controller;
}

/** @return array<string, list<string>> */
function partner_all_permissions(): array
{
    return [
        'get' => [
            'publicapi/v1/partner/onboard',
        ],
        'post' => [
            'publicapi/v1/partner/onboard',
            'publicapi/v1/partner/join',
        ],
    ];
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function partner_sample_link(array $overrides = []): array
{
    return array_merge([
        'link_id' => 9,
        'uuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        'program_id' => 1,
        'status' => ModelPartnerCustomerLink::STATUS_INVITED,
        'distribution' => 'simplifi_email',
        'invited_email' => 'invitee@example.com',
        'invited_given_name' => 'Alex',
        'invited_surname' => null,
        'external_ref' => 'EMP-42',
        'product_name' => 'Sign Pro',
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        'token_hash' => 'hash',
        'customer_id' => null,
        'consent_at' => null,
    ], $overrides);
}

/**
 * Stubbed SPA step flags (avoids identity/certificate SQL in unit tests).
 *
 * @param array<string, bool> $overrides
 * @return array<string, bool>
 */
function partner_sample_steps(array $overrides = []): array
{
    return array_merge([
        'claimed' => false,
        'identity_verified' => false,
        'certificate_issued' => false,
        'consent_given' => false,
        'active' => false,
    ], $overrides);
}

function partner_set_method(string $method): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
}
