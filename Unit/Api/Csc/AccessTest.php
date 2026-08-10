<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscAccess::index() status payload.
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Unit harness; no database. Customer id 0.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 401 unauthenticated before entitlement or access models load.
 */
test('CSC access — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = csc_access_registry(new CscApiCustomerStub(0, 10, 1));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with company_id 0.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 company_context_required.
 */
test('CSC access — missing company returns 400 company_context_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 0, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required']);
});

/**
 * Prerequisites:
 * - Non-admin role for company.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 403 admin_role_required.
 */
test('CSC access — non-admin returns 403 admin_role_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $ent = new CscEntitlementAssignmentStub();
    $ent->isAdmin = false;
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), null, $ent);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required']);
});

/**
 * Prerequisites:
 * - Admin context; GET permission missing.
 *
 * Steps:
 * 1. GET index() with empty permission get list.
 * 2. Assert 403 access_denied.
 */
test('CSC access — missing route permission returns 403 access_denied', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = (object) ['get' => [], 'post' => [], 'delete' => [], 'patch' => []];
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - Admin with permissions; POST method.
 *
 * Steps:
 * 1. Call index() with POST.
 * 2. Assert 405 method_not_allowed and Allow headers include GET.
 */
test('CSC access — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toContain('GET');
});

/**
 * Prerequisites:
 * - No company access row; enforce metering; no subscription grants.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert state none, can_request true, api_calls_enabled false with access_not_enabled, zero allowances.
 */
test('CSC access — status with no access row returns none snapshot', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $grants = new CscEntitlementGrantStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 88, 7), $access, null, $grants);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['access_state'])->toBe(ModelCscCompanyAccess::STATE_NONE)
        ->and($data['effective_enabled'])->toBeFalse()
        ->and($data['metering_mode'])->toBe(ModelCscCompanyAccess::METER_ENFORCE)
        ->and($data['can_request'])->toBeTrue()
        ->and($data['can_provision'])->toBeFalse()
        ->and($data['master_enabled'])->toBeTrue()
        ->and($data['runtime']['api_calls_enabled'])->toBeFalse()
        ->and($data['runtime']['blocked_reason'])->toBe('access_not_enabled')
        ->and($data['runtime']['subscription']['active'])->toBeFalse()
        ->and($data['runtime']['subscription']['expires_at'])->toBeNull()
        ->and($data['runtime']['allowance']['enrollments_remaining'])->toBe(0)
        ->and($data['runtime']['allowance']['signatures_remaining'])->toBe(0)
        ->and($data['runtime']['allowance']['packs_remaining'])->toBe(0)
        ->and($data['questionnaire']['submitted'])->toBeFalse()
        ->and($data['evidence_count'])->toBe(0)
        ->and($data['requested_at'])->toBeNull()
        ->and($data['approved_at'])->toBeNull();
});

/**
 * Prerequisites:
 * - Approved company with enforce mode, active subscription and remaining allowances.
 *
 * Steps:
 * 1. Seed approved access row + grants.
 * 2. GET index().
 * 3. Assert effective flags, Zulu dates, subscription expiry and allowance balances.
 */
test('CSC access — approved status returns policy metering and allowance snapshot', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_APPROVED,
        'metering_mode' => ModelCscCompanyAccess::METER_ENFORCE,
        'requested_at'  => '2026-01-10 08:30:00',
        'approved_at'   => '2026-01-12 14:15:00',
        'review_due_at' => '2027-01-12 00:00:00',
        'dpa_version'   => '1.0',
        'dpa_accepted_at'=> '2026-01-10 08:31:00',
    ];
    $access->latestQuestionnaire = [
        'csc_security_questionnaire_id' => 9,
        'schema_version'                => CscSecurityQuestionnaire::SCHEMA_VERSION,
        'answers_decoded'               => csc_minimal_valid_answers(),
        'created_at'                    => '2026-01-10 08:30:00',
    ];
    $access->evidence = [
        [
            'csc_access_evidence_id' => 1,
            'original_filename'      => 'iso.pdf',
            'size_bytes'             => 100,
            'created_at'             => '2026-01-10 09:00:00',
            'stored_path'            => 'csc_access_evidence/88/hidden.pdf',
        ],
    ];

    $grants = new CscEntitlementGrantStub();
    $grants->activeTotals = [CscApiMetering::CODE_ACCESS => 1];
    $grants->bucketsByCode[CscApiMetering::CODE_ACCESS] = [
        ['expires_at' => '2026-12-31 23:59:59'],
    ];
    $grants->remainingByCode = [
        CscApiMetering::CODE_ENROLLMENTS => 12,
        CscApiMetering::CODE_SIGNATURES  => 40,
        CscApiMetering::CODE_PACKS       => 3,
    ];

    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 88, 7), $access, null, $grants);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['access_state'])->toBe('approved')
        ->and($data['effective_enabled'])->toBeTrue()
        ->and($data['can_request'])->toBeFalse()
        ->and($data['can_provision'])->toBeTrue()
        ->and($data['runtime']['api_calls_enabled'])->toBeTrue()
        ->and($data['runtime']['blocked_reason'])->toBeNull()
        ->and($data['runtime']['subscription']['active'])->toBeTrue()
        ->and($data['runtime']['subscription']['expires_at'])->toEndWith('Z')
        ->and($data['runtime']['allowance']['enrollments_remaining'])->toBe(12)
        ->and($data['runtime']['allowance']['signatures_remaining'])->toBe(40)
        ->and($data['runtime']['allowance']['packs_remaining'])->toBe(3)
        ->and($data['requested_at'])->toEndWith('Z')
        ->and($data['approved_at'])->toEndWith('Z')
        ->and($data['review_due_at'])->toEndWith('Z')
        ->and($data['questionnaire']['submitted'])->toBeTrue()
        ->and($data['evidence_count'])->toBe(1)
        ->and($data['dpa_version'])->toBe('1.0')
        ->and(json_encode($data))->not->toContain('stored_path');
});

/**
 * Prerequisites:
 * - Rejected access row with reason.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert rejected_reason present and can_request true.
 */
test('CSC access — rejected status exposes rejected_reason and allows re-request', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = [
        'access_state'    => ModelCscCompanyAccess::STATE_REJECTED,
        'metering_mode'   => ModelCscCompanyAccess::METER_ENFORCE,
        'rejected_reason' => 'incomplete evidence',
    ];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->json['data']['access_state'])->toBe('rejected')
        ->and($c->json['data']['rejected_reason'])->toBe('incomplete evidence')
        ->and($c->json['data']['can_request'])->toBeTrue()
        ->and($c->json['data']['info_request_note'])->toBeNull();
});

/**
 * Prerequisites:
 * - Suspended company; unmetered mode still blocked by effective access.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert can_provision false and runtime blocked with access_not_enabled.
 */
test('CSC access — suspended unmetered company is blocked at runtime', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_SUSPENDED,
        'metering_mode' => ModelCscCompanyAccess::METER_UNMETERED,
    ];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->json['data']['effective_enabled'])->toBeFalse()
        ->and($c->json['data']['metering_mode'])->toBe('unmetered')
        ->and($c->json['data']['runtime']['api_calls_enabled'])->toBeFalse()
        ->and($c->json['data']['runtime']['blocked_reason'])->toBe('access_not_enabled');
});

/**
 * Prerequisites:
 * - Approved + unmetered; no subscription required.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert api_calls_enabled true without access grant totals.
 */
test('CSC access — approved unmetered company enables runtime without subscription', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_APPROVED,
        'metering_mode' => ModelCscCompanyAccess::METER_UNMETERED,
    ];
    $grants = new CscEntitlementGrantStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access, null, $grants);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->index();

    expect($c->json['data']['runtime']['api_calls_enabled'])->toBeTrue()
        ->and($c->json['data']['runtime']['blocked_reason'])->toBeNull()
        ->and($c->json['data']['runtime']['subscription']['active'])->toBeFalse();
});
