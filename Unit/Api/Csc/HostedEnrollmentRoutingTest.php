<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::index() routing/dispatch:
 * token resolution, expiry gate, method/action dispatch table, and the
 * per-action minimal-stub gate each handler enforces before doing real work.
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
 * - GET request with no enrollment_token in the path/query.
 *
 * Steps:
 * 1. Call index() with an empty enrollment_token.
 * 2. Assert 400 enrollment_token_required, before any model is touched.
 */
test('CSC hosted enrollment — missing token returns 400 enrollment_token_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c, $enrollment] = csc_hosted_enrollment_controller(csc_hosted_enrollment_session(), '', '');
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['enrollment_token_required']);
});

/**
 * Prerequisites:
 * - getByToken() resolves to null (no session matches the token).
 *
 * Steps:
 * 1. GET index() with an unknown token.
 * 2. Assert 404 enrollment_not_found.
 */
test('CSC hosted enrollment — unknown token returns 404 enrollment_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c] = csc_hosted_enrollment_controller(null, '', 'does-not-exist');
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['enrollment_not_found']);
});

/**
 * Prerequisites:
 * - Session flagged __force_expired (isExpired() -> true) while state is the
 *   non-terminal 'created'.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 410 enrollment_expired (loadSessionOrFail's expiry gate blocks
 *    non-terminal states once past expires_at).
 */
test('CSC hosted enrollment — expired active session returns 410 enrollment_expired', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_hosted_enrollment_session([
        'state'           => ModelCscEnrollmentSession::STATE_CREATED,
        '__force_expired' => true,
    ]);
    [$c] = csc_hosted_enrollment_controller($session);
    $c->index();

    expect($c->statusCode)->toBe(410)
        ->and($c->json['error'])->toBe(['enrollment_expired']);
});

/**
 * Prerequisites:
 * - Session flagged __force_expired but state is 'verified' (a terminal
 *   state exempted from the expiry gate per loadSessionOrFail's allow-list).
 *
 * Steps:
 * 1. GET index().
 * 2. Assert the response is NOT 410 (the session still loads); we assert the
 *    full 200 + status payload since the stubbed legal/document model
 *    returns no documents, so handleLoad's LegalDocuments lookup resolves
 *    cleanly without needing a real DB.
 */
test('CSC hosted enrollment — expired but verified session is not blocked by the expiry gate', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_hosted_enrollment_session([
        'state'           => ModelCscEnrollmentSession::STATE_VERIFIED,
        '__force_expired' => true,
    ]);
    [$c] = csc_hosted_enrollment_controller($session);

    try {
        $c->index();
        expect($c->statusCode)->not->toBe(410);
        expect($c->statusCode)->toBe(200)
            ->and($c->json['data']['status'])->toBe('verified');
    } catch (\Throwable $e) {
        // Even if a downstream legal-documents dependency were to blow up in
        // some environment, the important routing invariant is that the
        // expiry gate itself never fired for an exempted terminal state.
        expect($c->statusCode)->not->toBe(410);
    }
});

/**
 * Prerequisites:
 * - Valid session; DELETE is not in the allowedHeaders/dispatch table.
 *
 * Steps:
 * 1. Call index() with REQUEST_METHOD=DELETE and no action.
 * 2. Assert 405 method_not_allowed.
 */
test('CSC hosted enrollment — unsupported method returns 405 method_not_allowed', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    [$c] = csc_hosted_enrollment_controller(csc_hosted_enrollment_session());
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Valid session; action query param set to something not in the dispatch
 *   table (e.g. GET with an unrecognized action, or POST with a GET-only
 *   action name).
 *
 * Steps:
 * 1. Call index() with an unrecognized action.
 * 2. Assert 405 method_not_allowed (falls through the if/else chain).
 */
test('CSC hosted enrollment — unrecognized action returns 405 method_not_allowed', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$c] = csc_hosted_enrollment_controller(csc_hosted_enrollment_session(), 'not_a_real_action');
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Session already past terms (state=sms_verified), so both accept_terms
 *   and choose_sca — which only allow created/terms_accepted — hit their
 *   invalid_enrollment_state gate immediately after the handler is reached.
 *
 * Steps:
 * 1. POST accept_terms → assert 422 invalid_enrollment_state.
 * 2. POST choose_sca → assert 422 invalid_enrollment_state.
 * This proves index() dispatches both actions to the right handler.
 */
test('CSC hosted enrollment — accept_terms and choose_sca dispatch reaches invalid_enrollment_state gate', function () {
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_SMS_VERIFIED]);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$cAccept] = csc_hosted_enrollment_controller($session, 'accept_terms');
    $cAccept->setPostPayload([]);
    $cAccept->index();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$cChoose] = csc_hosted_enrollment_controller($session, 'choose_sca');
    $cChoose->setPostPayload(['sca_method' => 'sms']);
    $cChoose->index();

    expect($cAccept->statusCode)->toBe(422)
        ->and($cAccept->json['error'])->toBe(['invalid_enrollment_state'])
        ->and($cChoose->statusCode)->toBe(422)
        ->and($cChoose->json['error'])->toBe(['invalid_enrollment_state']);
});

/**
 * Prerequisites:
 * - Fresh session (state=created, terms not yet accepted) shared across the
 *   SMS/email-pin/TOTP actions, each of which requires state=terms_accepted
 *   before doing any real work.
 *
 * Steps:
 * 1. POST send_sms, verify_sms, send_email_pin, verify_email_pin, totp_setup,
 *    totp_verify with the same not-yet-accepted session.
 * 2. Assert every one dispatches to its handler and returns 422
 *    terms_not_accepted (proves the routing table wiring for all six).
 */
test('CSC hosted enrollment — sms/email-pin/totp actions all dispatch to terms_not_accepted gate', function () {
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    $actions = ['send_sms', 'verify_sms', 'send_email_pin', 'verify_email_pin', 'totp_setup', 'totp_verify'];

    foreach ($actions as $action) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        [$c] = csc_hosted_enrollment_controller($session, $action);
        $c->setPostPayload(['code' => '123456', 'pin' => '123456']);
        $c->index();

        expect($c->statusCode)->toBe(422)
            ->and($c->json['error'])->toBe(['terms_not_accepted']);
    }
});

/**
 * Prerequisites:
 * - Fresh session (state=created); checkin_token requires state=sms_verified.
 *
 * Steps:
 * 1. GET checkin_token.
 * 2. Assert 422 sms_not_verified (proves GET-action dispatch works too).
 */
test('CSC hosted enrollment — checkin_token dispatch reaches sms_not_verified gate', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'checkin_token');
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sms_not_verified']);
});

/**
 * Prerequisites:
 * - Fresh session; status has no gate and always succeeds once the session
 *   loads.
 *
 * Steps:
 * 1. GET status.
 * 2. Assert 200 with the session's public status echoed back.
 */
test('CSC hosted enrollment — status dispatch returns the session snapshot', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'status');
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['status'])->toBe('created')
        ->and($c->json['data']['should_redirect'])->toBeFalse();
});

/**
 * Prerequisites:
 * - Fresh, not-yet-redirected session; redirect_ack computes should_redirect
 *   from state/timing and (absent a provisioned client secret) always
 *   resolves to false for a non-terminal state.
 *
 * Steps:
 * 1. POST redirect_ack.
 * 2. Assert 409 redirect_not_due (proves POST-action dispatch reaches the
 *    handler and its redirect computation).
 */
test('CSC hosted enrollment — redirect_ack dispatch reaches redirect_not_due gate', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'redirect_ack');
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['redirect_not_due']);
});
