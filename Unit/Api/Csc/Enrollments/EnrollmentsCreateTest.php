<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../tests_config.php';
require_once __DIR__ . '/../_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollments::handleCreate() new-session path:
 * allowance gating, TTL resolution, persistence failure and the success payload.
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    CscEnrollmentEvents::reset();
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
    CscEnrollmentEvents::reset();
});

/**
 * @return array<string, mixed>
 */
function csc_enrollments_create_payload(): array
{
    return [
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
        'email'        => 'signer@example.com',
    ];
}

/**
 * Prerequisites:
 * - Company metering mode is "enforce" (with an active access subscription so the
 *   top-level gate passes) and the enrollments allowance is exhausted (remaining 0).
 *
 * Steps:
 * 1. POST create with a valid body.
 * 2. Assert 402 enrollment_allowance_exhausted; no session created.
 */
test('CSC enrollments create — exhausted allowance returns 402', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry, , $access, $grants] = csc_integrator_registry($enrollment);
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_APPROVED,
        'metering_mode' => ModelCscCompanyAccess::METER_ENFORCE,
    ];
    $grants->remainingByCode[CscApiMetering::CODE_ENROLLMENTS] = 0;
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_create_payload());
    $c->index();

    expect($c->statusCode)->toBe(402)
        ->and($c->json['error'])->toBe(['enrollment_allowance_exhausted'])
        ->and($enrollment->createCalls)->toBe(0)
        ->and(CscEnrollmentEvents::$dispatches)->toBe([]);
});

/**
 * Prerequisites:
 * - Allowance available, but the model's createSession() fails (returns null).
 *
 * Steps:
 * 1. POST create with a valid body.
 * 2. Assert 500 enrollment_create_failed; no application.created event dispatched.
 */
test('CSC enrollments create — persistence failure returns 500', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->createThrows = true;
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_create_payload());
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['enrollment_create_failed'])
        ->and($enrollment->createCalls)->toBe(1)
        ->and(CscEnrollmentEvents::$dispatches)->toBe([]);
});

/**
 * Prerequisites:
 * - Fully valid new-session create; default unmetered/approved access from
 *   csc_integrator_registry(); hosted base URL configured.
 *
 * Steps:
 * 1. POST create with a valid body.
 * 2. Assert 201, hosted_url built from the base URL + default path + token,
 *    resumed = false, and exactly one application.created event dispatched.
 */
test('CSC enrollments create — success returns 201 with hosted URL and dispatches event', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_create_payload());
    $c->index();

    expect($c->statusCode)->toBe(201)
        ->and($enrollment->createCalls)->toBe(1)
        ->and($c->json['data']['enrollment_uuid'])->toBe('enroll-uuid-1')
        ->and($c->json['data']['hosted_url'])->toBe('https://enroll.example.com/hosted/csc/enroll/token-abc')
        ->and($c->json['data']['resumed'])->toBeFalse()
        ->and($c->json['data']['status'])->toBe('created')
        ->and(CscEnrollmentEvents::$dispatches)->toHaveCount(1)
        ->and(CscEnrollmentEvents::$dispatches[0]['event'])->toBe('application.created');
});

/**
 * Prerequisites:
 * - Config csc_enrollment_ttl_seconds explicitly set below the 300s floor.
 *
 * Steps:
 * 1. POST create with a valid body.
 * 2. Assert the model receives ttl_seconds = 86400 (the default), not the configured value.
 */
test('CSC enrollments create — TTL below 300 seconds falls back to the default', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry(
        $enrollment,
        null,
        null,
        null,
        null,
        null,
        null,
        ['csc_enrollment_ttl_seconds' => 100],
    );
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_create_payload());
    $c->index();

    expect($c->statusCode)->toBe(201)
        ->and($enrollment->lastCreateData['ttl_seconds'])->toBe(86400);
});

/**
 * Prerequisites:
 * - Config csc_enrollment_ttl_seconds set above the 300s floor.
 *
 * Steps:
 * 1. POST create with a valid body.
 * 2. Assert the model receives the configured TTL verbatim.
 */
test('CSC enrollments create — TTL at or above 300 seconds is honored', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry(
        $enrollment,
        null,
        null,
        null,
        null,
        null,
        null,
        ['csc_enrollment_ttl_seconds' => 3600],
    );
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_create_payload());
    $c->index();

    expect($c->statusCode)->toBe(201)
        ->and($enrollment->lastCreateData['ttl_seconds'])->toBe(3600);
});
