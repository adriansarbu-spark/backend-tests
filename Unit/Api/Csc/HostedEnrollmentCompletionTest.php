<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::handleCheckinToken(),
 * ::handleStatus() and ::handleRedirectAck(): the SCA-established/
 * verification-ready gates for the checkin handoff, the status snapshot,
 * and the redirect acknowledgement gate.
 *
 * handleCheckinToken's success path is intentionally NOT exercised here: it
 * calls the real checkin SDK over HTTP (Guzzle) once past its gates. Only
 * the gates that return before that call are covered. handleStatus and
 * handleRedirectAck's should_redirect=true branch (a provisioned Keycloak
 * client secret) is also left uncovered to avoid touching CscKeycloakProvisioner
 * — both are exercised here only with csc_api_client's getByInternalId()
 * returning null (no provisioned client), which is exactly what keeps them
 * network-free.
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
 * @param array<string, mixed> $overrides merged onto a terms_accepted, sms-chosen base session
 * @return array<string, mixed>
 */
function csc_completion_session(array $overrides = []): array
{
    return csc_hosted_enrollment_session(array_merge([
        'state'             => ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED,
        'sca_methods'       => 'sms',
        'sca_method_chosen' => 'sms',
    ], $overrides));
}

/**
 * Prerequisites:
 * - Session state=terms_accepted (SCA not yet established via SMS).
 *
 * Steps:
 * 1. GET checkin_token.
 * 2. Assert 422 sms_not_verified (the legacy code kept for sms subjects).
 */
test('CSC hosted enrollment — checkin_token before SMS verification returns 422 sms_not_verified', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_completion_session();
    [$c] = csc_hosted_enrollment_controller($session, 'checkin_token');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sms_not_verified']);
});

/**
 * Prerequisites:
 * - Session state=terms_accepted with totp chosen (SCA not yet
 *   established).
 *
 * Steps:
 * 1. GET checkin_token.
 * 2. Assert 422 sca_not_established (the totp-appropriate code, per
 *    chosenScaMethod()).
 */
test('CSC hosted enrollment — checkin_token before TOTP SCA established returns 422 sca_not_established', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_completion_session(['sca_methods' => 'totp', 'sca_method_chosen' => 'totp']);
    [$c] = csc_hosted_enrollment_controller($session, 'checkin_token');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_not_established']);
});

/**
 * Prerequisites:
 * - Session state=sms_verified (SCA established) but verification_id is
 *   empty (accept_terms never ran / stub session created without it).
 *
 * Steps:
 * 1. GET checkin_token.
 * 2. Assert 422 verification_not_ready — still before any HTTP call to the
 *    checkin SDK.
 */
test('CSC hosted enrollment — checkin_token without a verification id returns 422 verification_not_ready', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_completion_session([
        'state'           => ModelCscEnrollmentSession::STATE_SMS_VERIFIED,
        'verification_id' => '',
    ]);
    [$c] = csc_hosted_enrollment_controller($session, 'checkin_token');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['verification_not_ready']);
});

/**
 * Prerequisites:
 * - Fresh terms_accepted session; csc_api_client's getByInternalId() is
 *   unstubbed (returns null), so no Keycloak client secret is available.
 *
 * Steps:
 * 1. GET status.
 * 2. Assert 200 with the session's public status echoed, redirect_timing
 *    passed through, and should_redirect=false/redirect_url=null (no
 *    provisioned client secret to sign a redirect with).
 */
test('CSC hosted enrollment — status returns the session snapshot without a redirect', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_completion_session();
    [$c] = csc_hosted_enrollment_controller($session, 'status');
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe([
            'status'          => 'terms_accepted',
            'redirect_timing' => 'on_verified',
            'should_redirect' => false,
            'redirect_url'    => null,
        ]);
});

/**
 * Prerequisites:
 * - Session state=verified (a redirect-eligible state for the on_verified
 *   timing), but no Keycloak client secret is provisioned.
 *
 * Steps:
 * 1. GET status.
 * 2. Assert should_redirect stays false even in a redirect-eligible state,
 *    because resolveRedirectForSession() requires a non-empty client secret
 *    (characterizes the "not provisioned yet" behavior).
 */
test('CSC hosted enrollment — status never signals a redirect without a provisioned client secret', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_completion_session(['state' => ModelCscEnrollmentSession::STATE_VERIFIED]);
    [$c] = csc_hosted_enrollment_controller($session, 'status');
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['status'])->toBe('verified')
        ->and($c->json['data']['should_redirect'])->toBeFalse()
        ->and($c->json['data']['redirect_url'])->toBeNull();
});

/**
 * Prerequisites:
 * - Session already has redirected_at set (browser already acknowledged).
 *
 * Steps:
 * 1. POST redirect_ack.
 * 2. Assert 200 acknowledged=true immediately, without recomputing the
 *    redirect (markRedirected() is not called again).
 */
test('CSC hosted enrollment — redirect_ack on an already-redirected session short-circuits to acknowledged', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $session = csc_completion_session(['redirected_at' => gmdate('Y-m-d H:i:s')]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'redirect_ack');
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['acknowledged' => true])
        ->and($enrollment->markRedirectedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Fresh terms_accepted session (not yet in a redirect-eligible state) and
 *   no provisioned client secret.
 *
 * Steps:
 * 1. POST redirect_ack.
 * 2. Assert 409 redirect_not_due, and markRedirected() is never called.
 */
test('CSC hosted enrollment — redirect_ack before the redirect is due returns 409 redirect_not_due', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $session = csc_completion_session();
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'redirect_ack');
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['redirect_not_due'])
        ->and($enrollment->markRedirectedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session state=verified (redirect-eligible for on_verified timing), but
 *   no Keycloak client secret is provisioned — resolveRedirectForSession()
 *   requires one before should_redirect can ever be true.
 *
 * Steps:
 * 1. POST redirect_ack.
 * 2. Assert 409 redirect_not_due even in a terminal, redirect-eligible
 *    state — characterizes that provisioning gates the browser redirect,
 *    not just the enrollment state.
 */
test('CSC hosted enrollment — redirect_ack in a redirect-eligible state still returns 409 without a provisioned client', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $session = csc_completion_session(['state' => ModelCscEnrollmentSession::STATE_VERIFIED]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'redirect_ack');
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['redirect_not_due'])
        ->and($enrollment->markRedirectedCalls)->toBe(0);
});
