<?php

declare(strict_types=1);

/**
 * DB-free validators and step-state mapping on ModelPartnerCustomerLink.
 * Loads the real model with a stubbed registry/db — no application DB.
 */

require_once dirname(__DIR__, 3) . '/tests_config.php';

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (defined('DIR_VENDOR') && is_file(DIR_VENDOR . 'autoload.php')) {
    require_once DIR_VENDOR . 'autoload.php';
}

if (!class_exists(Model::class, false)) {
    require_once DIR_SYSTEM . 'engine/model.php';
}

// Real model (Sponsorships/Partner doubles also load this — no constants-only stub).
if (!class_exists('ModelPartnerCustomerLink', false)) {
    require_once DIR_APPLICATION . 'model/partner/customer_link.php';
}

if (!method_exists('ModelPartnerCustomerLink', 'normalizeEmail')) {
    throw new RuntimeException(
        'ModelPartnerCustomerLink is loaded without validators; remove any constants-only stub.'
    );
}

/** DB stub that answers identity + certificate probes for stepStateForLink. */
final class PartnerLinkValidatorsDbStub
{
    public function __construct(
        public string $identityStatus = '',
        public bool $hasActiveCert = false,
    ) {
    }

    /** @var list<string> */
    public array $queries = [];

    public function query(string $sql): object
    {
        $this->queries[] = $sql;

        if (str_contains($sql, 'identity')) {
            if ($this->identityStatus === '') {
                return (object) ['num_rows' => 0, 'row' => [], 'rows' => []];
            }

            return (object) [
                'num_rows' => 1,
                'row' => ['verification_status' => $this->identityStatus],
                'rows' => [],
            ];
        }

        if (str_contains($sql, 'end_user_certificates')) {
            return (object) [
                'num_rows' => $this->hasActiveCert ? 1 : 0,
                'row' => [],
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

function partner_link_validators_model(?PartnerLinkValidatorsDbStub $db = null): ModelPartnerCustomerLink
{
    $registry = new Registry();
    $registry->set('db', $db ?? new PartnerLinkValidatorsDbStub());

    return new ModelPartnerCustomerLink($registry);
}

/**
 * Prerequisites:
 * - Real customer_link model; no DB needed for normalizeEmail.
 *
 * Steps:
 * 1. Normalize mixed-case / padded emails.
 * 2. Assert lowercase trimmed output.
 */
test('Partner — normalizeEmail lowercases and trims', function () {
    $model = partner_link_validators_model();

    expect($model->normalizeEmail('  Foo.Bar@Example.COM '))->toBe('foo.bar@example.com')
        ->and($model->normalizeEmail(''))->toBe('');
});

/**
 * Prerequisites:
 * - Real customer_link model.
 *
 * Steps:
 * 1. Validate good, empty, and malformed invite emails.
 * 2. Assert only RFC-ish addresses within length pass.
 */
test('Partner — isValidInviteEmail accepts only plausible invite addresses', function (
    string $email,
    bool $expected,
) {
    $model = partner_link_validators_model();

    expect($model->isValidInviteEmail($email))->toBe($expected);
})->with([
    'valid' => ['person@example.com', true],
    'mixed case' => ['  Person@Example.COM ', true],
    'empty' => ['', false],
    'no at' => ['not-an-email', false],
    'spaces inside' => ['a b@c.com', false],
]);

/**
 * Prerequisites:
 * - Real customer_link model.
 *
 * Steps:
 * 1. Validate external_ref length and emptiness.
 * 2. Assert non-empty refs up to 128 chars pass.
 */
test('Partner — isValidExternalRef enforces non-empty max-128 refs', function (
    string $ref,
    bool $expected,
) {
    $model = partner_link_validators_model();

    expect($model->isValidExternalRef($ref))->toBe($expected);
})->with([
    'simple' => ['ext-001', true],
    'trimmed ok' => ['  ref-9  ', true],
    'empty' => ['', false],
    'whitespace only' => ['   ', false],
    'max 128' => [str_repeat('a', 128), true],
    'over 128' => [str_repeat('a', 129), false],
]);

/**
 * Prerequisites:
 * - Real customer_link model status constants.
 *
 * Steps:
 * 1. Check revoked vs live statuses.
 * 2. Assert only partner/customer revoke statuses count as revoked.
 */
test('Partner — isRevokedStatus recognizes partner and customer revoke states', function (
    string $status,
    bool $expected,
) {
    $model = partner_link_validators_model();

    expect($model->isRevokedStatus($status))->toBe($expected);
})->with([
    'revoked by partner' => [ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER, true],
    'revoked by customer' => [ModelPartnerCustomerLink::STATUS_REVOKED_CUSTOMER, true],
    'invited' => [ModelPartnerCustomerLink::STATUS_INVITED, false],
    'onboarding' => [ModelPartnerCustomerLink::STATUS_ONBOARDING, false],
    'active' => [ModelPartnerCustomerLink::STATUS_ACTIVE, false],
]);

/**
 * Prerequisites:
 * - Fabricated link row with no claimed customer; stub db unused for identity.
 *
 * Steps:
 * 1. Call stepStateForLink for an invited unclaimed row.
 * 2. Assert claimed/consent/active flags are false.
 */
test('Partner — stepStateForLink for unclaimed invited link', function () {
    $model = partner_link_validators_model();
    $state = $model->stepStateForLink([
        'customer_id' => null,
        'consent_at' => null,
        'status' => ModelPartnerCustomerLink::STATUS_INVITED,
    ]);

    expect($state)->toMatchArray([
        'claimed' => false,
        'identity_verified' => false,
        'certificate_issued' => false,
        'consent_given' => false,
        'active' => false,
    ]);
});

/**
 * Prerequisites:
 * - Claimed link with approved identity, active cert, consent, active status.
 *
 * Steps:
 * 1. Stub db to return approved identity and a valid signing cert.
 * 2. Call stepStateForLink.
 * 3. Assert all onboard steps are true.
 */
test('Partner — stepStateForLink for fully onboarded active link', function () {
    $db = new PartnerLinkValidatorsDbStub(
        identityStatus: 'approved',
        hasActiveCert: true,
    );
    $model = partner_link_validators_model($db);
    $state = $model->stepStateForLink([
        'customer_id' => 42,
        'consent_at' => '2026-08-15 10:00:00',
        'status' => ModelPartnerCustomerLink::STATUS_ACTIVE,
    ]);

    expect($state)->toMatchArray([
        'claimed' => true,
        'identity_verified' => true,
        'certificate_issued' => true,
        'consent_given' => true,
        'active' => true,
    ])
        ->and($db->queries)->not->toBeEmpty();
});

/**
 * Prerequisites:
 * - Claimed customer without approved identity or cert; consent pending.
 *
 * Steps:
 * 1. Stub db with pending identity and no cert.
 * 2. Assert claimed true, other steps false while status is onboarding.
 */
test('Partner — stepStateForLink for claimed but incomplete onboarding', function () {
    $db = new PartnerLinkValidatorsDbStub(
        identityStatus: 'pending',
        hasActiveCert: false,
    );
    $model = partner_link_validators_model($db);
    $state = $model->stepStateForLink([
        'customer_id' => 42,
        'consent_at' => null,
        'status' => ModelPartnerCustomerLink::STATUS_ONBOARDING,
    ]);

    expect($state)->toMatchArray([
        'claimed' => true,
        'identity_verified' => false,
        'certificate_issued' => false,
        'consent_given' => false,
        'active' => false,
    ]);
});
