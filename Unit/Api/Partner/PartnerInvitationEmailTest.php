<?php

declare(strict_types=1);

/**
 * Unit coverage for PartnerInvitationEmail pure compose + display-name helpers.
 * No EmailQueue / Mailgun / filesystem.
 */

require_once dirname(__DIR__, 3) . '/tests_config.php';
require_once DIR_SYSTEM . 'library/partner_invitation_email.php';

/** Language stub with partner_invitation_default_* keys for composeCopy. */
final class PartnerInvitationEmailLanguageStub
{
    /** @var list<array{0: string, 1: string}> */
    public array $loadCalls = [];

    /** @var array<string, string> */
    private array $strings;

    /** @param array<string, string> $strings */
    public function __construct(array $strings = [])
    {
        $this->strings = $strings ?: [
            'partner_invitation_default_subject' => '{{partner_name}} invites you',
            'partner_invitation_default_title' => 'A benefit from {{partner_name}}',
            'partner_invitation_default_eyebrow' => 'Sponsored benefit',
            'partner_invitation_default_invite_line' => '{{partner_name}} offers {{plan_name}} via {{benefit_description}}',
            'partner_invitation_default_body' => 'Default body for {{plan_name}}',
            'partner_invitation_default_cta' => 'Activate',
            'partner_invitation_default_benefit' => 'your benefits package',
        ];
    }

    public function load_specific_dir(string $file, string $locale): void
    {
        $this->loadCalls[] = [$file, $locale];
    }

    public function get(string $key): string
    {
        return $this->strings[$key] ?? $key;
    }
}

final class PartnerInvitationEmailProgramStub
{
    /** @var array<string, mixed> */
    public array $branding;

    /** @param array<string, mixed> $branding */
    public function __construct(array $branding = [])
    {
        $this->branding = $branding;
    }

    /** @param array<string, mixed> $program */
    public function getBranding(array $program): array
    {
        return $this->branding;
    }
}

final class PartnerInvitationEmailLoadStub
{
    /**
     * @param array<string, object> $models
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly array $models,
    ) {
    }

    public function model(string $route): void
    {
        if (isset($this->models[$route])) {
            $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
        }
    }
}

final class PartnerInvitationEmailDbStub
{
    public function __construct(
        private readonly string $companyName = 'Legal Company Name SA',
    ) {
    }

    public function query(string $sql): object
    {
        if (str_contains($sql, 'FROM `company`') || str_contains($sql, 'FROM company')) {
            return (object) [
                'num_rows' => 1,
                'row' => ['name' => $this->companyName],
                'rows' => [],
            ];
        }

        return (object) ['num_rows' => 0, 'row' => [], 'rows' => []];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

/**
 * @param array<string, mixed> $branding
 * @param array<string, mixed>|null $brand
 * @return Registry
 */
function partner_invitation_email_registry(
    array $branding = [],
    ?PartnerInvitationEmailLanguageStub $language = null,
    string $companyName = 'Legal Company Name SA',
): Registry {
    $registry = new Registry();
    $program = new PartnerInvitationEmailProgramStub($branding);
    $load = new PartnerInvitationEmailLoadStub($registry, [
        'partner/program' => $program,
        'localisation/language' => new class {
            /** @return array<string, array{language_id: int}> */
            public function getLanguages(): array
            {
                return [
                    'en-gb' => ['language_id' => 1],
                    'ro-ro' => ['language_id' => 2],
                ];
            }
        },
        'company/branding' => new class {
            public function getForCompany(int $companyId): ?array
            {
                return null;
            }

            public function formatForApi($row): ?array
            {
                return null;
            }
        },
    ]);
    $registry->set('load', $load);
    $registry->set('language', $language ?? new PartnerInvitationEmailLanguageStub());
    $registry->set('db', new PartnerInvitationEmailDbStub($companyName));

    return $registry;
}

/**
 * Prerequisites:
 * - Language defaults available; no program email_copy overrides.
 *
 * Steps:
 * 1. Call composeCopy for en-gb with partner + plan names.
 * 2. Assert placeholders are rendered and brand colors/logo are copied through.
 */
test('Partner — composeCopy renders defaults with placeholders and brand fields', function () {
    $language = new PartnerInvitationEmailLanguageStub();
    $registry = partner_invitation_email_registry(language: $language);
    $brand = [
        'logo_url' => 'https://cdn.example/logo.png',
        'accent_color' => '#112233',
        'on_accent' => '#ffffff',
    ];

    $copy = PartnerInvitationEmail::composeCopy(
        $registry,
        'en-gb',
        $brand,
        [],
        'Acme Sponsors',
        'Sign Pro',
    );

    expect($language->loadCalls)->toBe([['mail/mailgun', 'en-gb']])
        ->and($copy['subject'])->toBe('Acme Sponsors invites you')
        ->and($copy['title'])->toBe('A benefit from Acme Sponsors')
        ->and($copy['invite_line'])->toBe('Acme Sponsors offers Sign Pro via your benefits package')
        ->and($copy['body'])->toBe('Default body for Sign Pro')
        ->and($copy['cta'])->toBe('Activate')
        ->and($copy['benefit_description'])->toBe('your benefits package')
        ->and($copy['logo_url'])->toBe('https://cdn.example/logo.png')
        ->and($copy['accent_color'])->toBe('#112233')
        ->and($copy['on_accent'])->toBe('#ffffff');
});

/**
 * Prerequisites:
 * - Program email_copy overrides for en-gb.
 *
 * Steps:
 * 1. Call composeCopy with subject/intro/body/cta/benefit overrides.
 * 2. Assert overrides win over language defaults; title/eyebrow stay on defaults.
 */
test('Partner — composeCopy prefers program email_copy overrides over defaults', function () {
    $registry = partner_invitation_email_registry();
    $emailCopy = [
        'en-gb' => [
            'subject' => 'Custom subject for {{partner_name}}',
            'intro' => 'Custom intro {{plan_name}}',
            'body' => 'Custom body',
            'cta' => 'Join now',
            'benefit_description' => 'VIP package',
        ],
    ];

    $copy = PartnerInvitationEmail::composeCopy(
        $registry,
        'en-gb',
        [],
        $emailCopy,
        'Partner X',
        'Plan Y',
    );

    expect($copy['subject'])->toBe('Custom subject for Partner X')
        ->and($copy['invite_line'])->toBe('Custom intro Plan Y')
        ->and($copy['body'])->toBe('Custom body')
        ->and($copy['cta'])->toBe('Join now')
        ->and($copy['benefit_description'])->toBe('VIP package')
        ->and($copy['title'])->toBe('A benefit from Partner X')
        ->and($copy['eyebrow'])->toBe('Sponsored benefit');
});

/**
 * Prerequisites:
 * - Program branding has sender_display_name.
 *
 * Steps:
 * 1. Call partnerDisplayName.
 * 2. Assert program sender_display_name wins over brand and company name.
 */
test('Partner — partnerDisplayName prefers program sender_display_name', function () {
    $registry = partner_invitation_email_registry([
        'sender_display_name' => 'Campaign Sender',
    ]);
    $program = ['company_id' => 20, 'program_id' => 1];
    $brand = ['display_name' => 'Brand Display'];

    expect(PartnerInvitationEmail::partnerDisplayName($registry, $program, $brand))
        ->toBe('Campaign Sender');
});

/**
 * Prerequisites:
 * - No sender_display_name; brand has display_name.
 *
 * Steps:
 * 1. Call partnerDisplayName with brand payload.
 * 2. Assert brand display_name is used.
 */
test('Partner — partnerDisplayName falls back to company brand display_name', function () {
    $registry = partner_invitation_email_registry([]);
    $program = ['company_id' => 20, 'program_id' => 1];
    $brand = ['display_name' => 'Brand Display'];

    expect(PartnerInvitationEmail::partnerDisplayName($registry, $program, $brand))
        ->toBe('Brand Display');
});

/**
 * Prerequisites:
 * - No sender_display_name and empty brand; company row exists in db stub.
 *
 * Steps:
 * 1. Call partnerDisplayName with null brand (resolve returns empty).
 * 2. Assert legal company name from db is returned.
 */
test('Partner — partnerDisplayName falls back to legal company name', function () {
    $registry = partner_invitation_email_registry(
        branding: [],
        companyName: 'Legal Company Name SA',
    );
    $program = ['company_id' => 20, 'program_id' => 1];

    expect(PartnerInvitationEmail::partnerDisplayName($registry, $program, []))
        ->toBe('Legal Company Name SA');
});

/**
 * Prerequisites:
 * - Localisation language model with en-gb / ro-ro ids.
 *
 * Steps:
 * 1. Resolve locale for known and unknown language ids.
 * 2. Assert matching dir or default ro-ro.
 */
test('Partner — localeForLanguageId maps language ids and defaults to ro-ro', function () {
    $registry = partner_invitation_email_registry();

    expect(PartnerInvitationEmail::localeForLanguageId($registry, 1))->toBe('en-gb')
        ->and(PartnerInvitationEmail::localeForLanguageId($registry, 2))->toBe('ro-ro')
        ->and(PartnerInvitationEmail::localeForLanguageId($registry, 99))->toBe('ro-ro');
});
