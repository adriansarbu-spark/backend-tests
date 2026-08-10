<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../tests_config.php';
require_once __DIR__ . '/../_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollments::handleCreate() request validation.
 * Every scenario here must fail before CscEnrollmentSessionModelStub::createSession()
 * is ever invoked (asserted via createCalls === 0).
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
 * @return array<string, mixed> a minimal valid create payload; callers override fields under test.
 */
function csc_enrollments_valid_payload(): array
{
    return [
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
    ];
}

/**
 * Prerequisites:
 * - POST body omits signer_id.
 *
 * Steps:
 * 1. POST create with empty signer_id.
 * 2. Assert 400 signer_id_required; no session created.
 */
test('CSC enrollments create — missing signer_id returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    unset($payload['signer_id']);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['signer_id_required'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - POST body omits redirect_uri.
 *
 * Steps:
 * 1. POST create with empty redirect_uri.
 * 2. Assert 400 redirect_uri_required; no session created.
 */
test('CSC enrollments create — missing redirect_uri returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    unset($payload['redirect_uri']);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['redirect_uri_required'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - redirect_uri fails CscProvisioningValidation::normalizeRedirectUri (no scheme/host).
 *
 * Steps:
 * 1. POST create with a malformed redirect_uri.
 * 2. Assert 400 invalid_redirect_uri; no session created.
 */
test('CSC enrollments create — malformed redirect_uri returns 400 invalid_redirect_uri', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['redirect_uri'] = 'not-a-valid-uri';
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_redirect_uri'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - redirect_uri normalizes fine but is not allowlisted for the client
 *   (CscRedirectUriModelStub::uriExistsForClient() returns false).
 *
 * Steps:
 * 1. POST create with a well-formed but unregistered redirect_uri.
 * 2. Assert 400 invalid_redirect_uri; no session created.
 */
test('CSC enrollments create — unallowlisted redirect_uri returns 400 invalid_redirect_uri', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $redirect = new CscRedirectUriModelStub();
    $redirect->uriExists = false;
    [$registry] = csc_integrator_registry($enrollment, null, $redirect);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_valid_payload());
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_redirect_uri'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - redirect_timing outside the on_verified|on_submitted allowlist.
 *
 * Steps:
 * 1. POST create with redirect_timing = 'bogus'.
 * 2. Assert 400 invalid_redirect_timing; no session created.
 */
test('CSC enrollments create — invalid redirect_timing returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['redirect_timing'] = 'bogus';
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_redirect_timing'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - csc_enrollment_hosted_base_url config value is blank.
 *
 * Steps:
 * 1. POST create with an otherwise valid body.
 * 2. Assert 503 hosted_url_not_configured; no session created.
 */
test('CSC enrollments create — blank hosted base URL returns 503 hosted_url_not_configured', function () {
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
        ['csc_enrollment_hosted_base_url' => ''],
    );
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_valid_payload());
    $c->index();

    expect($c->statusCode)->toBe(503)
        ->and($c->json['error'])->toBe(['hosted_url_not_configured'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - email field present but not a valid address.
 *
 * Steps:
 * 1. POST create with email = 'not-an-email'.
 * 2. Assert 400 invalid_email; no session created.
 */
test('CSC enrollments create — invalid email returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['email'] = 'not-an-email';
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_email'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - sca_methods provided with a value outside sms|totp.
 *
 * Steps:
 * 1. POST create with sca_methods = ['sms', 'carrier-pigeon'].
 * 2. Assert 400 invalid_sca_methods; no session created.
 */
test('CSC enrollments create — invalid sca_methods returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['sca_methods'] = ['sms', 'carrier-pigeon'];
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_sca_methods'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - sca_methods provided as an empty array.
 *
 * Steps:
 * 1. POST create with sca_methods = [].
 * 2. Assert 400 invalid_sca_methods (empty list is rejected, not defaulted).
 */
test('CSC enrollments create — empty sca_methods array returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['sca_methods'] = [];
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_sca_methods'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - id_flow provided but not present in the checkin_enroll_flows allowlist.
 *
 * Steps:
 * 1. POST create with id_flow = 'not-a-real-flow'.
 * 2. Assert 400 invalid_id_flow; no session created.
 */
test('CSC enrollments create — invalid id_flow returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['id_flow'] = 'not-a-real-flow';
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_id_flow'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - sca_methods offers only totp; no email supplied and no in-progress session
 *   whose stored email could stand in for it.
 *
 * Steps:
 * 1. POST create with sca_methods = ['totp'] and no email.
 * 2. Assert 400 email_required_for_totp; no session created.
 */
test('CSC enrollments create — TOTP without email returns 400 email_required_for_totp', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $payload = csc_enrollments_valid_payload();
    $payload['sca_methods'] = ['totp'];
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['email_required_for_totp'])
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Signer model reports an invalid (zero) signer id after upsert.
 *
 * Steps:
 * 1. POST create with an otherwise valid body.
 * 2. Assert 400 invalid_signer_id; no session created.
 */
test('CSC enrollments create — invalid signer id returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $signer = new CscSignerModelStub();
    $signer->signerId = 0;
    [$registry] = csc_integrator_registry($enrollment, $signer);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload(csc_enrollments_valid_payload());
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_signer_id'])
        ->and($enrollment->createCalls)->toBe(0);
});
