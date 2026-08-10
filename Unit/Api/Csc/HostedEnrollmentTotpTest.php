<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::handleTotpSetup() and
 * ::handleTotpVerify(): the shared totpGateOrFail() gate (terms/SCA/email
 * verified), plus totp-specific validation (setup_required, code_required,
 * invalid code) and the success path using RobThree\Auth\TwoFactorAuth
 * directly (no network involved).
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
});

/**
 * @param array<string, mixed> $overrides merged onto a terms_accepted, totp-chosen, email-verified base session
 * @return array<string, mixed>
 */
function csc_totp_session(array $overrides = []): array
{
    return csc_hosted_enrollment_session(array_merge([
        'state'             => ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED,
        'sca_methods'       => 'totp',
        'sca_method_chosen' => 'totp',
        'email_verified_at' => gmdate('Y-m-d H:i:s'),
    ], $overrides));
}

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST totp_setup.
 * 2. Assert 422 terms_not_accepted (totpGateOrFail's first check).
 */
test('CSC hosted enrollment — totp_setup without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_totp_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_setup');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session terms_accepted but the chosen SCA method is sms.
 *
 * Steps:
 * 1. POST totp_setup.
 * 2. Assert 422 sca_method_not_totp.
 */
test('CSC hosted enrollment — totp_setup when SCA method is not totp returns 422 sca_method_not_totp', function () {
    $session = csc_totp_session(['sca_methods' => 'sms', 'sca_method_chosen' => 'sms']);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_setup');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_method_not_totp']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen, but email_verified_at is empty
 *   (email PIN step not completed).
 *
 * Steps:
 * 1. POST totp_setup.
 * 2. Assert 422 email_not_verified (third and last totpGateOrFail check).
 */
test('CSC hosted enrollment — totp_setup without a verified email returns 422 email_not_verified', function () {
    $session = csc_totp_session(['email_verified_at' => null]);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_setup');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['email_not_verified']);
});

/**
 * Prerequisites:
 * - Session passes all three totpGateOrFail checks (terms accepted, totp
 *   chosen, email verified).
 *
 * Steps:
 * 1. POST totp_setup.
 * 2. Assert 200 with a secret + otpauth_uri in the response, and
 *    setTotpSecretPending() called once with that secret.
 */
test('CSC hosted enrollment — totp_setup past all gates returns a secret and otpauth URI', function () {
    $session = csc_totp_session();
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'totp_setup');
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['secret'])->toBeString()->not->toBe('')
        ->and($c->json['data']['otpauth_uri'])->toContain('otpauth://totp/')
        ->and($enrollment->setTotpSecretPendingCalls)->toBe(1)
        ->and($enrollment->lastTotpSecretPending)->toBe($c->json['data']['secret']);
});

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST totp_verify with a code.
 * 2. Assert 422 terms_not_accepted (shares totpGateOrFail with setup).
 */
test('CSC hosted enrollment — totp_verify without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_totp_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_verify');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session passes the shared gate, but no totp_secret_pending was ever set
 *   (totp_setup was never called).
 *
 * Steps:
 * 1. POST totp_verify with a code.
 * 2. Assert 422 totp_setup_required.
 */
test('CSC hosted enrollment — totp_verify before totp_setup returns 422 totp_setup_required', function () {
    $session = csc_totp_session();
    [$c] = csc_hosted_enrollment_controller($session, 'totp_verify');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['totp_setup_required']);
});

/**
 * Prerequisites:
 * - Session has a pending totp secret; request omits the code field.
 *
 * Steps:
 * 1. POST totp_verify with an empty body.
 * 2. Assert 400 code_required.
 */
test('CSC hosted enrollment — totp_verify without a code returns 400 code_required', function () {
    $session = csc_totp_session(['totp_secret_pending' => 'ABCDEFGHIJKLMNOP']);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_verify');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['code_required']);
});

/**
 * Prerequisites:
 * - Session has a pending totp secret; the submitted code does not match
 *   any code the secret could produce within tolerance.
 *
 * Steps:
 * 1. POST totp_verify with an all-zero code.
 * 2. Assert 400 invalid_totp_code.
 */
test('CSC hosted enrollment — totp_verify with an incorrect code returns 400 invalid_totp_code', function () {
    $session = csc_totp_session(['totp_secret_pending' => 'ABCDEFGHIJKLMNOP']);
    [$c] = csc_hosted_enrollment_controller($session, 'totp_verify');
    $c->setPostPayload(['code' => '000000']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_totp_code']);
});

/**
 * Prerequisites:
 * - Session has a pending totp secret; the submitted code is generated from
 *   that exact secret via RobThree\Auth\TwoFactorAuth (same library the
 *   controller uses), so it is guaranteed valid right now — no network
 *   involved.
 *
 * Steps:
 * 1. POST totp_verify with the freshly generated code.
 * 2. Assert 200, data.sca_method='totp', signer's activateTotp() called
 *    once with the secret, and markScaEstablished() called once.
 */
test('CSC hosted enrollment — totp_verify with the correct code succeeds', function () {
    $secret = 'ABCDEFGHIJKLMNOP';
    $code = (new \RobThree\Auth\TwoFactorAuth())->getCode($secret);
    $session = csc_totp_session(['totp_secret_pending' => $secret]);
    $signer = new CscSignerModelStub();
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'totp_verify', 'token-abc', $signer);
    $c->setPostPayload(['code' => $code]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['sca_method'])->toBe('totp')
        ->and($signer->activateTotpCalls)->toBe(1)
        ->and($signer->lastActivateTotpSecret)->toBe($secret)
        ->and($enrollment->markScaEstablishedCalls)->toBe(1);
});
