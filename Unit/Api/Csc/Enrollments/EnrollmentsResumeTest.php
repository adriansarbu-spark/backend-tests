<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../tests_config.php';
require_once __DIR__ . '/../_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollments::handleCreate() resume path:
 * repeat POSTs for a signer with an in-progress enrollment must be idempotent and
 * must never re-check allowance or re-dispatch application.created.
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
 * @return array<string, mixed> an in-progress session row for the target signer.
 */
function csc_enrollments_in_progress_row(): array
{
    return [
        'csc_enrollment_session_id' => 900,
        'enrollment_uuid'           => 'enroll-uuid-existing',
        'enrollment_token'          => 'token-existing',
        'state'                     => ModelCscEnrollmentSession::STATE_CREATED,
        'csc_api_client_id'         => 11,
        'expires_at'                => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at'                => gmdate('Y-m-d H:i:s'),
        'sca_methods'               => 'sms',
        'signer_email'              => 'existing@example.com',
        'redirect_uri'              => 'https://old.example.com/callback',
        'redirect_timing'           => 'on_verified',
    ];
}

/**
 * Prerequisites:
 * - The signer already has an in-progress (non-terminal, non-expired) enrollment.
 *
 * Steps:
 * 1. POST create for the same signer with a (possibly updated) redirect_uri.
 * 2. Assert 200 with resumed = true, the same enrollment_uuid is returned, no new
 *    session was created, and no application.created event was dispatched.
 */
test('CSC enrollments create — resumes existing in-progress session', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->inProgressForSigner = csc_enrollments_in_progress_row();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload([
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['resumed'])->toBeTrue()
        ->and($c->json['data']['enrollment_uuid'])->toBe('enroll-uuid-existing')
        ->and($enrollment->createCalls)->toBe(0)
        ->and(CscEnrollmentEvents::$dispatches)->toBe([]);
});

/**
 * Prerequisites:
 * - Same as above.
 *
 * Steps:
 * 1. POST create for the signer.
 * 2. Assert updateResumeParams() was called exactly once on the model (the
 *    allowlisted redirect/timing/email/id_flow update path), and the resumed
 *    session reflects the new redirect_uri.
 */
test('CSC enrollments create — resume updates permitted fields exactly once', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->inProgressForSigner = csc_enrollments_in_progress_row();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload([
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($enrollment->updateResumeCalls)->toBe(1)
        ->and($enrollment->updateScaCalls)->toBe(0)
        ->and($enrollment->inProgressForSigner['redirect_uri'])->toBe('https://app.example.com/callback');
});

/**
 * Prerequisites:
 * - In-progress session already has a stored signer_email; sca_methods offers TOTP.
 *
 * Steps:
 * 1. POST create for the signer with sca_methods = ['totp'] and no email in the body.
 * 2. Assert 200 (the stored email on the existing session satisfies the TOTP
 *    email requirement) and updateScaMethodsOnResume() was invoked once.
 */
test('CSC enrollments create — resume with TOTP reuses the stored email', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->inProgressForSigner = csc_enrollments_in_progress_row();
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload([
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
        'sca_methods'  => ['totp'],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($enrollment->updateScaCalls)->toBe(1)
        ->and($enrollment->createCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - In-progress session has no stored email; sca_methods offers TOTP and the
 *   request body also omits email.
 *
 * Steps:
 * 1. POST create for the signer with sca_methods = ['totp'] and no email anywhere.
 * 2. Assert 400 email_required_for_totp; no resume update performed.
 */
test('CSC enrollments create — resume TOTP without any email returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $enrollment = new CscEnrollmentSessionModelStub();
    $row = csc_enrollments_in_progress_row();
    $row['signer_email'] = '';
    $enrollment->inProgressForSigner = $row;
    [$registry] = csc_integrator_registry($enrollment);
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->setPostPayload([
        'signer_id'    => 'signer-ext-1',
        'redirect_uri' => 'https://app.example.com/callback',
        'sca_methods'  => ['totp'],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['email_required_for_totp'])
        ->and($enrollment->updateResumeCalls)->toBe(0);
});
