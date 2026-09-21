<?php

declare(strict_types=1);

/**
 * Unit harness for partner sponsorship console + beneficiary routes.
 *
 * Mirrors Billing / TeamInvitations doubles: testable controllers (no real
 * plugin/sendResponse side effects), customer stubs, call-recording models,
 * and a registry/load helper. No real DB, Stripe, EmailQueue, or filesystem.
 */

require_once dirname(__DIR__, 4) . '/tests_config.php';

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(Model::class, false)) {
    require_once DIR_SYSTEM . 'engine/model.php';
}

// Controllers reference model constants; avoid loading SQL-backed model files.
if (!class_exists('ModelPartnerProgram', false)) {
    class ModelPartnerProgram
    {
        public const LOCALES = ['en-gb', 'ro-ro'];

        public const BRANDING_KEYS = ['sender_display_name'];

        public const EMAIL_COPY_KEYS = ['subject', 'intro', 'body', 'cta', 'benefit_description'];
    }
}

// Load the real model (constants + validators). Do not define a constants-only
// stub here — that shadows ModelPartnerCustomerLink and forces
// PartnerCustomerLinkValidatorsTest to skip when this harness loads first.
if (!class_exists('ModelPartnerCustomerLink', false)) {
    if (defined('DIR_VENDOR') && is_file(DIR_VENDOR . 'autoload.php')) {
        require_once DIR_VENDOR . 'autoload.php';
    }
    require_once DIR_APPLICATION . 'model/partner/customer_link.php';
}

require_once PUBLIC_API . 'sponsorships/program.php';
require_once PUBLIC_API . 'sponsorships/overview.php';
require_once PUBLIC_API . 'sponsorships/email_preview.php';
require_once PUBLIC_API . 'sponsorships/invitations.php';
require_once PUBLIC_API . 'sponsorships/invitations/batch.php';
require_once PUBLIC_API . 'sponsorships/invitations/revoke.php';
require_once PUBLIC_API . 'sponsorships/invitations/resend.php';
require_once PUBLIC_API . 'sponsorships/mine.php';
require_once PUBLIC_API . 'sponsorships/mine/revoke.php';

trait SponsorshipsControllerHarness
{
    public int $checkPluginCalls = 0;

    public int $sendResponseCalls = 0;

    /** @var array<string, mixed>|object */
    private array|object $sponsorshipPost = [];

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
        $this->sponsorshipPost = $payload;
    }

    /** @return array<string, mixed>|object */
    public function getPost()
    {
        return $this->sponsorshipPost;
    }
}

final class TestableControllerPublicAPIV1SponsorshipsProgram extends ControllerPublicAPIV1SponsorshipsProgram
{
    use SponsorshipsControllerHarness;
}

final class TestableControllerPublicAPIV1SponsorshipsOverview extends ControllerPublicAPIV1SponsorshipsOverview
{
    use SponsorshipsControllerHarness;
}

final class TestableControllerPublicAPIV1SponsorshipsEmailPreview extends ControllerPublicAPIV1SponsorshipsEmailPreview
{
    use SponsorshipsControllerHarness;
}

/** Shared send-limiter + hosted-URL stubs for invite / batch / resend controllers. */
trait SponsorshipsInvitationSendHarness
{
    /** @var array<string, mixed> */
    public array $sendAllowedResult = [
        'ok' => true,
        'rate_limit_reservation' => ['count_key' => 'partner_invitation:count:20:hash'],
    ];

    /** @var list<array<string, mixed>> */
    public array $releasedReservations = [];

    /** @var list<array<string, mixed>> */
    public array $committedReservations = [];

    public string $hostedOnboardUrlResult = 'https://app.example.test/partner/onboard/test-token';

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

    protected function hostedOnboardUrl($token)
    {
        return $this->hostedOnboardUrlResult !== ''
            ? $this->hostedOnboardUrlResult
            : 'https://app.example.test/partner/onboard/' . rawurlencode((string) $token);
    }
}

final class TestableControllerPublicAPIV1SponsorshipsInvitations extends ControllerPublicAPIV1SponsorshipsInvitations
{
    use SponsorshipsControllerHarness;
    use SponsorshipsInvitationSendHarness;

    public bool $queueInvitationEmailResult = true;

    /** @var list<array{0: mixed, 1: mixed, 2: array<string, mixed>}> */
    public array $billInvitationIfDueCalls = [];

    /** @var list<array{0: mixed, 1: mixed, 2: string}> */
    public array $queueInvitationEmailCalls = [];

    protected function queueInvitationEmail($program, $link, $token)
    {
        $this->queueInvitationEmailCalls[] = [$program, $link, (string) $token];

        return $this->queueInvitationEmailResult;
    }

    protected function billInvitationIfDue($program, $plan, array $result)
    {
        $this->billInvitationIfDueCalls[] = [$program, $plan, $result];
    }
}

final class TestableControllerPublicAPIV1SponsorshipsInvitationsBatch extends ControllerPublicAPIV1SponsorshipsInvitationsBatch
{
    use SponsorshipsControllerHarness;
    use SponsorshipsInvitationSendHarness;

    /** When not false, overrides DB lookup in validateRows (`null` = no blocker). */
    public mixed $blockingLinkOverride = false;

    /** @var list<array{0: mixed, 1: string, 2: string, 3: string}> */
    public array $findExistingBlockingLinkCalls = [];

    protected function findExistingBlockingLink($program, $distribution, $email, $external_ref)
    {
        $this->findExistingBlockingLinkCalls[] = [$program, (string) $distribution, (string) $email, (string) $external_ref];
        if ($this->blockingLinkOverride !== false) {
            return $this->blockingLinkOverride;
        }

        return parent::findExistingBlockingLink($program, $distribution, $email, $external_ref);
    }
}

final class TestableControllerPublicAPIV1SponsorshipsInvitationsRevoke extends ControllerPublicAPIV1SponsorshipsInvitationsRevoke
{
    use SponsorshipsControllerHarness;
}

final class TestableControllerPublicAPIV1SponsorshipsInvitationsResend extends ControllerPublicAPIV1SponsorshipsInvitationsResend
{
    use SponsorshipsControllerHarness;
    use SponsorshipsInvitationSendHarness;
}

final class TestableControllerPublicAPIV1SponsorshipsMine extends ControllerPublicAPIV1SponsorshipsMine
{
    use SponsorshipsControllerHarness;
}

final class TestableControllerPublicAPIV1SponsorshipsMineRevoke extends ControllerPublicAPIV1SponsorshipsMineRevoke
{
    use SponsorshipsControllerHarness;
}

/** Authenticated company admin (or other role) for sponsorship console gates. */
final class SponsorshipsCustomerStub
{
    public function __construct(
        private readonly int $customerId = 10,
        private readonly int $companyId = 20,
        private readonly int $roleId = 30,
        private readonly string $roleCode = 'admin',
        private readonly string $email = 'sponsor-admin@example.com',
        private readonly string $firstName = 'Pat',
        private readonly string $companyName = 'Acme Partner Co',
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }
}

/**
 * Generic call-recording model. A configured response may be a value, a queue
 * of values, or a closure that receives the method arguments.
 */
final class SponsorshipsModelStub
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

final class SponsorshipsLanguageStub
{
    /** @var list<array{0: string, 1: string}> */
    public array $loadCalls = [];

    public function load_specific_dir(string $file, string $locale): void
    {
        $this->loadCalls[] = [$file, $locale];
    }

    public function get(string $key): string
    {
        // Returning the key itself makes PartnerInvitationEmail treat defaults as empty.
        return $key;
    }
}

final class SponsorshipsBrandingModelStub
{
    /** @var array<string, mixed>|null */
    public ?array $brandRow = null;

    /** @var array<string, mixed>|null */
    public ?array $formatted = null;

    public function getForCompany(int $companyId): ?array
    {
        return $this->brandRow;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    public function formatForApi($row): ?array
    {
        return $this->formatted;
    }
}

final class SponsorshipsDbStub
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

final class SponsorshipsLoadStub
{
    /** @var list<string> */
    public array $loadedModels = [];

    /** @var list<string> */
    public array $loadedLibraries = [];

    /**
     * @param array<string, object> $models Route => model instance
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

    public function library(string $route): void
    {
        $this->loadedLibraries[] = $route;
    }
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sponsorships_sample_program(array $overrides = []): array
{
    return array_merge([
        'program_id' => 1,
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'program_code' => 'acme-partner',
        'name' => 'Acme Partner Program',
        'status' => 'active',
        'billing_mode' => 'per_invitation',
        'company_id' => 20,
        'payment_terms_days' => 30,
        'link_token_ttl_days' => 14,
        'branding_json' => '{"sender_display_name":"Acme Sponsors"}',
        'email_copy_json' => '{}',
        'default_program_plan_id' => 5,
    ], $overrides);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sponsorships_console_program_payload(array $overrides = []): array
{
    return array_merge([
        'program_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'program_code' => 'acme-partner',
        'name' => 'Acme Partner Program',
        'status' => 'active',
        'billing_mode' => 'per_invitation',
        'payment_terms_days' => 30,
        'link_token_ttl_days' => 14,
        'branding' => ['sender_display_name' => 'Acme Sponsors'],
        'company_brand' => null,
        'email_copy' => new stdClass(),
        'plans' => [
            [
                'program_plan_uuid' => '11111111-2222-3333-4444-555555555555',
                'product_code' => 'sign-pro',
                'product_name' => 'Sign Pro',
                'billing_interval' => 'month',
                'currency' => 'ron',
                'unit_amount' => 4900,
                'status' => 'active',
                'is_default' => true,
            ],
        ],
    ], $overrides);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, int>
 */
function sponsorships_overview_counters(array $overrides = []): array
{
    return array_merge([
        'invited' => 2,
        'onboarding' => 1,
        'active' => 3,
        'revoked' => 0,
        'billed' => 4,
    ], $overrides);
}

/**
 * Default program model stub wired for console GET/PATCH happy paths.
 *
 * @param array<string, mixed>|null $program
 * @param array<string, mixed>      $extraResponses
 */
function sponsorships_program_model(
    ?array $program = null,
    array $extraResponses = [],
): SponsorshipsModelStub {
    $program ??= sponsorships_sample_program();
    $console = sponsorships_console_program_payload();

    return new SponsorshipsModelStub(array_merge([
        'getProgramForCompany' => $program,
        'getProgramById' => $program,
        'formatProgramForConsole' => $console,
        'getBranding' => ['sender_display_name' => 'Acme Sponsors'],
        'getEmailCopy' => [],
        'getOverviewCounters' => sponsorships_overview_counters(),
        'getActivePlanByUuid' => null,
        'getDefaultPlan' => [
            'program_plan_id' => 5,
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'product_name' => 'Sign Pro',
        ],
        'updateConsoleConfig' => true,
        'getActiveProgramByCode' => $program,
    ], $extraResponses));
}

/**
 * @param array<string, object> $models
 * @param array<string, mixed>  $query
 *
 * @return array{0: Registry, 1: SponsorshipsLoadStub, 2: SponsorshipsDbStub}
 */
function sponsorships_registry(
    ?SponsorshipsCustomerStub $customer = null,
    array $models = [],
    array $query = [],
    ?SponsorshipsDbStub $db = null,
    ?SponsorshipsLanguageStub $language = null,
): array {
    $registry = new Registry();
    $load = new SponsorshipsLoadStub($registry, $models);
    $db ??= new SponsorshipsDbStub();
    $registry->set('load', $load);
    $registry->set('customer', $customer ?? new SponsorshipsCustomerStub());
    $registry->set('request', (object) ['get' => $query, 'server' => []]);
    $registry->set('db', $db);
    $registry->set('language', $language ?? new SponsorshipsLanguageStub());
    $registry->set('config', new class {
        public function load(string $key): void
        {
        }

        public function get(string $key): mixed
        {
            return match ($key) {
                'config_language_id' => 1,
                'mailgun_max_retries' => 0,
                'team_invitation_send_limit_per_company_recipient' => 100,
                'team_invitation_send_window_secs' => 3600,
                'team_invitation_resend_min_interval_secs' => 0,
                default => null,
            };
        }
    });

    return [$registry, $load, $db];
}

/**
 * @template T of PublicAPIController
 *
 * @param class-string<T>             $class
 * @param array<string, list<string>> $permissions
 *
 * @return T
 */
function sponsorships_controller(
    string $class,
    Registry $registry,
    array $permissions = [],
): PublicAPIController {
    $controller = new $class($registry);
    $controller->permission = (object) $permissions;

    return $controller;
}

/** @return array<string, list<string>> */
function sponsorships_all_permissions(): array
{
    return [
        'get' => [
            'publicapi/v1/sponsorships/program',
            'publicapi/v1/sponsorships/overview',
            'publicapi/v1/sponsorships/email_preview',
            'publicapi/v1/sponsorships/invitations',
            'publicapi/v1/sponsorships/mine',
        ],
        'post' => [
            'publicapi/v1/sponsorships/invitations',
            'publicapi/v1/sponsorships/invitations/batch',
            'publicapi/v1/sponsorships/invitations/revoke',
            'publicapi/v1/sponsorships/invitations/resend',
            'publicapi/v1/sponsorships/mine/revoke',
        ],
        'patch' => [
            'publicapi/v1/sponsorships/program',
        ],
    ];
}

function sponsorships_set_method(string $method): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
}

/** @return list<array{method: string, args: list<mixed>}> */
function sponsorships_calls(SponsorshipsModelStub $model, string $method): array
{
    return $model->callsTo($method);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sponsorships_sample_link(array $overrides = []): array
{
    return array_merge([
        'link_id' => 9,
        'uuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        'program_id' => 1,
        'program_plan_id' => 5,
        'status' => ModelPartnerCustomerLink::STATUS_INVITED,
        'distribution' => 'simplifi_email',
        'invited_email' => 'beneficiary@example.com',
        'invited_given_name' => 'Ben',
        'invited_surname' => 'Eficiary',
        'external_ref' => '',
        'billed_at' => null,
        'last_sent_at' => null,
        'plan' => ['product_name' => 'Sign Pro'],
    ], $overrides);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function sponsorships_create_or_refresh_ok(array $overrides = []): array
{
    $link = $overrides['link'] ?? sponsorships_sample_link();
    unset($overrides['link']);

    return array_merge([
        'ok' => true,
        'link_id' => (int) ($link['link_id'] ?? 9),
        'link' => $link,
        'token' => 'invite-token-plaintext',
        'refreshed' => false,
        'revived' => false,
        'staged_rotation' => null,
    ], $overrides);
}

/**
 * Link model stub with real-ish email/ref validators for batch validateRows.
 *
 * @param array<string, mixed> $extraResponses
 */
function sponsorships_link_model_for_validation(array $extraResponses = []): SponsorshipsModelStub
{
    return new SponsorshipsModelStub(array_merge([
        'normalizeEmail' => static fn ($email): string => trim(mb_strtolower((string) $email)),
        'isValidInviteEmail' => static function ($email): bool {
            $email = trim(mb_strtolower((string) $email));

            return $email !== '' && strlen($email) <= 320 && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        },
        'isValidExternalRef' => static function ($externalRef): bool {
            $externalRef = trim((string) $externalRef);

            return $externalRef !== '' && mb_strlen($externalRef) <= 128;
        },
        'externalRefConflict' => false,
        'formatLink' => static fn ($row) => is_array($row) ? $row : null,
        'getLinkById' => null,
    ], $extraResponses));
}

/**
 * Console fixture: admin customer + entitlement + program (+ optional link/sponsorship).
 *
 * @param array<string, mixed>  $query
 * @param array<string, object> $overrides Model route overrides
 *
 * @return array{
 *   0: Registry,
 *   1: SponsorshipsLoadStub,
 *   2: array{assignment: SponsorshipsModelStub, program: SponsorshipsModelStub, link: SponsorshipsModelStub, sponsorship: SponsorshipsModelStub, branding: SponsorshipsBrandingModelStub},
 *   3: SponsorshipsCustomerStub
 * }
 */
function sponsorships_console_fixture(
    array $query = [],
    array $overrides = [],
    ?SponsorshipsCustomerStub $customer = null,
    bool $isCompanyAdmin = true,
    ?array $program = null,
): array {
    $customer ??= new SponsorshipsCustomerStub();
    $assignment = $overrides['billing/entitlement_assignment']
        ?? new SponsorshipsModelStub(['isRoleAdminForCompany' => $isCompanyAdmin]);
    $programModel = $overrides['partner/program']
        ?? sponsorships_program_model($program);
    $linkModel = $overrides['partner/customer_link']
        ?? new SponsorshipsModelStub([]);
    $sponsorshipModel = $overrides['partner/sponsorship']
        ?? new SponsorshipsModelStub(['getSponsorshipsForCustomer' => []]);
    $branding = $overrides['company/branding']
        ?? new SponsorshipsBrandingModelStub();

    [$registry, $load] = sponsorships_registry(
        customer: $customer,
        models: [
            'billing/entitlement_assignment' => $assignment,
            'partner/program' => $programModel,
            'partner/customer_link' => $linkModel,
            'partner/sponsorship' => $sponsorshipModel,
            'company/branding' => $branding,
        ],
        query: $query,
    );

    return [
        $registry,
        $load,
        [
            'assignment' => $assignment,
            'program' => $programModel,
            'link' => $linkModel,
            'sponsorship' => $sponsorshipModel,
            'branding' => $branding,
        ],
        $customer,
    ];
}
