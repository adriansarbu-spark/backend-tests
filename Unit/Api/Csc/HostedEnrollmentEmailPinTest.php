<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::handleSendEmailPin() and
 * ::handleVerifyEmailPin(): the terms/SCA/email/rate gates on the send side,
 * and the full verify-pin code lifecycle (missing/expired/wrong/correct pin).
 *
 * handleSendEmailPin's actual-send success path is intentionally NOT
 * exercised here: past the rate-limit gate it calls the real CscEmailPin::
 * queuePin(), which drives EmailQueue/GuestSigningEmail against the DB. Only
 * the gates that return before that call are covered.
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
 * @param array<string, mixed> $overrides merged onto a terms_accepted, totp-chosen base session
 * @return array<string, mixed>
 */
function csc_email_pin_session(array $overrides = []): array
{
    return csc_hosted_enrollment_session(array_merge([
        'state'             => ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED,
        'sca_methods'       => 'totp',
        'sca_method_chosen' => 'totp',
        'signer_email'      => 'signer@example.com',
    ], $overrides));
}

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST send_email_pin.
 * 2. Assert 422 terms_not_accepted.
 */
test('CSC hosted enrollment — send_email_pin without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_email_pin_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'send_email_pin');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session terms_accepted but the chosen SCA method is sms, not totp.
 *
 * Steps:
 * 1. POST send_email_pin.
 * 2. Assert 422 sca_method_not_totp.
 */
test('CSC hosted enrollment — send_email_pin when SCA method is not totp returns 422 sca_method_not_totp', function () {
    $session = csc_email_pin_session(['sca_methods' => 'sms', 'sca_method_chosen' => 'sms']);
    [$c] = csc_hosted_enrollment_controller($session, 'send_email_pin');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_method_not_totp']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen, but no signer_email on file.
 *
 * Steps:
 * 1. POST send_email_pin.
 * 2. Assert 422 signer_email_not_available.
 */
test('CSC hosted enrollment — send_email_pin without a signer email returns 422 signer_email_not_available', function () {
    $session = csc_email_pin_session(['signer_email' => '']);
    [$c] = csc_hosted_enrollment_controller($session, 'send_email_pin');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['signer_email_not_available']);
});

/**
 * Prerequisites:
 * - Session otherwise eligible; the enrollment model stub's
 *   reserveEmailPinSendSlot() is forced to return false (rate limit
 *   exhausted), mirroring the real model's atomic UPDATE ... WHERE
 *   email_pin_send_count < limit affecting zero rows.
 *
 * Steps:
 * 1. POST send_email_pin.
 * 2. Assert 429 rate_limited, before CscEmailPin::queuePin() is ever
 *    reached.
 */
test('CSC hosted enrollment — send_email_pin past the rate limit returns 429 rate_limited', function () {
    $session = csc_email_pin_session();
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byToken = $session;
    $enrollment->reserveEmailPinSendSlotResult = false;
    [$registry] = csc_integrator_registry($enrollment);
    $registry->get('request')->get = ['enrollment_token' => 'token-abc', 'action' => 'send_email_pin'];
    $c = new TestableControllerPublicAPIV1CscEnroll($registry);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['rate_limited'])
        ->and($enrollment->reserveEmailPinSendSlotCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST verify_email_pin with a pin.
 * 2. Assert 422 terms_not_accepted.
 */
test('CSC hosted enrollment — verify_email_pin without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_email_pin_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload(['pin' => '654321']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session terms_accepted but the chosen SCA method is sms.
 *
 * Steps:
 * 1. POST verify_email_pin with a pin.
 * 2. Assert 422 sca_method_not_totp.
 */
test('CSC hosted enrollment — verify_email_pin when SCA method is not totp returns 422 sca_method_not_totp', function () {
    $session = csc_email_pin_session(['sca_methods' => 'sms', 'sca_method_chosen' => 'sms']);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload(['pin' => '654321']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_method_not_totp']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen; request omits the pin field.
 *
 * Steps:
 * 1. POST verify_email_pin with an empty body.
 * 2. Assert 400 pin_required.
 */
test('CSC hosted enrollment — verify_email_pin without a pin returns 400 pin_required', function () {
    $session = csc_email_pin_session();
    [$c] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['pin_required']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen, email_pin_expires_at is in the
 *   past.
 *
 * Steps:
 * 1. POST verify_email_pin with the (now-irrelevant) matching pin.
 * 2. Assert 400 email_pin_expired.
 */
test('CSC hosted enrollment — verify_email_pin with an expired pin returns 400 email_pin_expired', function () {
    $session = csc_email_pin_session([
        'email_pin_hash'        => hash('sha256', '654321|token-abc'),
        'email_pin_expires_at'  => gmdate('Y-m-d H:i:s', time() - 10),
    ]);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload(['pin' => '654321']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['email_pin_expired']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen, valid (unexpired) pin hash stored
 *   for '654321'.
 *
 * Steps:
 * 1. POST verify_email_pin with a different pin ('111111').
 * 2. Assert 400 invalid_email_pin and markEmailVerified() is never called.
 */
test('CSC hosted enrollment — verify_email_pin with the wrong pin returns 400 invalid_email_pin', function () {
    $session = csc_email_pin_session([
        'email_pin_hash'        => hash('sha256', '654321|token-abc'),
        'email_pin_expires_at'  => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload(['pin' => '111111']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_email_pin'])
        ->and($enrollment->markEmailVerifiedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session terms_accepted, totp chosen, valid (unexpired) pin hash stored
 *   for '654321'.
 *
 * Steps:
 * 1. POST verify_email_pin with the matching pin.
 * 2. Assert 200, data.email_verified=true, and markEmailVerified() called
 *    once.
 */
test('CSC hosted enrollment — verify_email_pin with the correct pin succeeds', function () {
    $session = csc_email_pin_session([
        'email_pin_hash'        => hash('sha256', '654321|token-abc'),
        'email_pin_expires_at'  => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'verify_email_pin');
    $c->setPostPayload(['pin' => '654321']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['status' => 'terms_accepted', 'email_verified' => true])
        ->and($enrollment->markEmailVerifiedCalls)->toBe(1);
});
