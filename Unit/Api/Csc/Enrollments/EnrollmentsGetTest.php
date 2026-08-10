<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../tests_config.php';
require_once __DIR__ . '/../_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollments::handleGet() — the tenant-scoped
 * enrollment status lookup (GET publicapi/v1/csc/enrollments?enrollment_uuid=...).
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
 * - GET request with no enrollment_uuid query parameter.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 enrollment_uuid_required.
 */
test('CSC enrollments get — missing enrollment_uuid returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['enrollment_uuid_required']);
});

/**
 * Prerequisites:
 * - enrollment_uuid supplied but the session model has no matching row for
 *   this client (either truly missing or belonging to another client).
 *
 * Steps:
 * 1. GET index() with enrollment_uuid set.
 * 2. Assert 404 enrollment_not_found.
 */
test('CSC enrollments get — unknown or foreign enrollment_uuid returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $registry->get('request')->get['enrollment_uuid'] = 'unknown-uuid';
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['enrollment_not_found']);
});

/**
 * Prerequisites:
 * - Session exists for the authenticated client, in the initial "created" state,
 *   SMS-only SCA, no credential issued yet.
 *
 * Steps:
 * 1. GET index() with the matching enrollment_uuid.
 * 2. Assert the basic public payload: uuid, signer_id, status, redirect_timing,
 *    sca_methods/sca_method, Zulu-formatted timestamps, and credential_status
 *    defaulting to "none" without a credential_uuid/identity block.
 */
test('CSC enrollments get — success returns the basic session payload', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid'    => 'enroll-uuid-get-1',
        'external_signer_id' => 'signer-ext-1',
        'state'              => ModelCscEnrollmentSession::STATE_CREATED,
        'redirect_timing'    => 'on_verified',
        'sca_methods'        => 'sms',
        'expires_at'         => '2026-01-01 00:00:00',
        'created_at'         => '2025-12-01 00:00:00',
        'credential_status'  => ModelCscEnrollmentSession::CREDENTIAL_NONE,
    ];
    [$registry] = csc_integrator_registry($enrollment);
    $registry->get('request')->get['enrollment_uuid'] = 'enroll-uuid-get-1';
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['enrollment_uuid'])->toBe('enroll-uuid-get-1')
        ->and($data['signer_id'])->toBe('signer-ext-1')
        ->and($data['status'])->toBe('created')
        ->and($data['redirect_timing'])->toBe('on_verified')
        ->and($data['id_flow'])->toBeNull()
        ->and($data['sca_methods'])->toBe(['sms'])
        ->and($data['sca_method'])->toBe('sms')
        ->and($data['expires_at'])->toBe('2026-01-01T00:00:00Z')
        ->and($data['created_at'])->toBe('2025-12-01T00:00:00Z')
        ->and($data['credential_status'])->toBe('none')
        ->and($data)->not->toHaveKey('credential_uuid')
        ->and($data)->not->toHaveKey('identity');
});

/**
 * Prerequisites:
 * - Session carries a selected id_flow and both SCA methods offered (no choice made).
 *
 * Steps:
 * 1. GET index() with the matching enrollment_uuid.
 * 2. Assert id_flow is surfaced and sca_method is null when no method was chosen yet.
 */
test('CSC enrollments get — surfaces id_flow and null sca_method when undecided', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid'    => 'enroll-uuid-get-2',
        'external_signer_id' => 'signer-ext-2',
        'state'              => ModelCscEnrollmentSession::STATE_CREATED,
        'redirect_timing'    => 'on_submitted',
        'id_flow'            => 'ro-id-card-only',
        'sca_methods'        => 'sms,totp',
        'expires_at'         => '2026-02-01 00:00:00',
        'created_at'         => '2025-12-15 00:00:00',
        'credential_status'  => ModelCscEnrollmentSession::CREDENTIAL_NONE,
    ];
    [$registry] = csc_integrator_registry($enrollment);
    $registry->get('request')->get['enrollment_uuid'] = 'enroll-uuid-get-2';
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['id_flow'])->toBe('ro-id-card-only')
        ->and($data['sca_methods'])->toBe(['sms', 'totp'])
        ->and($data['sca_method'])->toBeNull();
});

/**
 * Prerequisites:
 * - Session credential_status is "failed" with a failure code recorded.
 *
 * Steps:
 * 1. GET index() with the matching enrollment_uuid.
 * 2. Assert credential_failure_code is surfaced alongside credential_status.
 */
test('CSC enrollments get — surfaces credential_failure_code when credential failed', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid'          => 'enroll-uuid-get-3',
        'external_signer_id'       => 'signer-ext-3',
        'state'                    => ModelCscEnrollmentSession::STATE_VERIFIED,
        'redirect_timing'          => 'on_verified',
        'sca_methods'              => 'sms',
        'expires_at'               => '2026-01-01 00:00:00',
        'created_at'               => '2025-12-01 00:00:00',
        'credential_status'        => ModelCscEnrollmentSession::CREDENTIAL_FAILED,
        'credential_failure_code'  => 'issuer_unreachable',
    ];
    [$registry] = csc_integrator_registry($enrollment);
    $registry->get('request')->get['enrollment_uuid'] = 'enroll-uuid-get-3';
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['credential_status'])->toBe('failed')
        ->and($data['credential_failure_code'])->toBe('issuer_unreachable');
});
