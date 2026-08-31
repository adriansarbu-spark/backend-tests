<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::handleSendSms() and
 * ::handleVerifySms(): the terms/SCA gates both handlers share, plus the
 * full verify-sms code lifecycle (missing/expired/wrong/correct code).
 *
 * handleSendSms's happy path is intentionally NOT exercised here: once past
 * phone validation it constructs a real SmsAlert\SmsClient and would attempt
 * a network call. Only the gates that return before that construction are
 * covered (terms/SCA/phone validation).
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
 * @param array<string, mixed> $overrides merged onto a terms_accepted, sms-chosen base session
 * @return array<string, mixed>
 */
function csc_sms_session(array $overrides = []): array
{
    return csc_hosted_enrollment_session(array_merge([
        'state'             => ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED,
        'sca_methods'       => 'sms',
        'sca_method_chosen' => 'sms',
    ], $overrides));
}

function csc_enroll_sms_rate_limit_cache_key(string $token, string $ip = '203.0.113.1'): string
{
    return 'csc_enroll_sms:' . hash('sha256', $token . '|' . $ip);
}

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST send_sms with a phone number.
 * 2. Assert 422 terms_not_accepted, before any phone validation runs.
 */
test('CSC hosted enrollment — send_sms without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_sms_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload(['phone' => '0722334455']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session terms_accepted but the chosen SCA method is totp, not sms.
 *
 * Steps:
 * 1. POST send_sms.
 * 2. Assert 422 sca_method_not_sms.
 */
test('CSC hosted enrollment — send_sms when SCA method is not sms returns 422 sca_method_not_sms', function () {
    $session = csc_sms_session(['sca_methods' => 'totp', 'sca_method_chosen' => 'totp']);
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload(['phone' => '0722334455']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_method_not_sms']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; request omits the phone field.
 *
 * Steps:
 * 1. POST send_sms with an empty body.
 * 2. Assert 400 phone_required (still before the SmsAlert client exists).
 */
test('CSC hosted enrollment — send_sms without a phone returns 400 phone_required', function () {
    $session = csc_sms_session();
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['phone_required']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; phone value libphonenumber cannot
 *   parse as a valid number.
 *
 * Steps:
 * 1. POST send_sms with an unparseable phone value.
 * 2. Assert 400 invalid_phone_number.
 */
test('CSC hosted enrollment — send_sms with an invalid phone returns 400 invalid_phone_number', function () {
    $session = csc_sms_session();
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload(['phone' => 'not-a-phone']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_phone_number']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; phone is a valid, non-RO number
 *   (Romania-only enrollment).
 *
 * Steps:
 * 1. POST send_sms with a valid French mobile number.
 * 2. Assert 400 phone_region_not_allowed.
 */
test('CSC hosted enrollment — send_sms with a non-RO phone returns 400 phone_region_not_allowed', function () {
    $session = csc_sms_session();
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload(['phone' => '+33612345678']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['phone_region_not_allowed']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; integrator locked signer_phone to
 *   +40722334455.
 *
 * Steps:
 * 1. POST send_sms with a different valid RO number.
 * 2. Assert 400 phone_locked before any SMS provider is contacted.
 */
test('CSC hosted enrollment — send_sms with a phone that does not match the integrator lock returns 400 phone_locked', function () {
    $session = csc_sms_session([
        'signer_phone'        => '+40722334455',
        'signer_phone_locked' => 1,
    ]);
    [$c] = csc_hosted_enrollment_controller($session, 'send_sms');
    $c->setPostPayload(['phone' => '0721111111']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['phone_locked']);
});

/**
 * Prerequisites:
 * - Session otherwise eligible; csc_sms_attempt already records the
 *   configured per-session send cap as reached.
 *
 * Steps:
 * 1. POST send_sms.
 * 2. Assert 429 rate_limited before phone validation or gateway send.
 */
test('CSC hosted enrollment — send_sms when the per-session send cap is reached returns 429 rate_limited', function () {
    $session = csc_sms_session();
    $smsAttempt = new CscSmsAttemptModelStub();
    $smsAttempt->countSentForContextResult = 10;
    [$c] = csc_hosted_enrollment_controller(
        $session,
        'send_sms',
        'token-abc',
        null,
        null,
        ['csc_enrollment_sms_max_per_session' => 10],
        $smsAttempt,
    );
    $c->setPostPayload(['phone' => '0722334455']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['rate_limited'])
        ->and($smsAttempt->countSentForContextCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Session otherwise eligible; the sliding-window cache bucket for this
 *   token+IP is already at the configured limit.
 *
 * Steps:
 * 1. POST send_sms.
 * 2. Assert 429 rate_limited before phone validation or gateway send.
 */
test('CSC hosted enrollment — send_sms past the sliding-window rate limit returns 429 rate_limited', function () {
    $session = csc_sms_session();
    $cache = new CscApiCacheStub();
    $cache->store[csc_enroll_sms_rate_limit_cache_key('token-abc')] = '5';
    $hadRemoteAddr = array_key_exists('REMOTE_ADDR', $_SERVER);
    $savedRemoteAddr = $hadRemoteAddr ? $_SERVER['REMOTE_ADDR'] : null;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    try {
        [$c] = csc_hosted_enrollment_controller(
            $session,
            'send_sms',
            'token-abc',
            null,
            null,
            ['csc_enrollment_sms_rate_limit' => 5],
            null,
            $cache,
        );
        $c->setPostPayload(['phone' => '0722334455']);
        $c->index();

        expect($c->statusCode)->toBe(429)
            ->and($c->json['error'])->toBe(['rate_limited']);
    } finally {
        if (! $hadRemoteAddr) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $savedRemoteAddr;
        }
    }
});

/**
 * Prerequisites:
 * - Session otherwise eligible; GET_LOCK for the per-session send lock
 *   returns unavailable (concurrent send in progress).
 *
 * Steps:
 * 1. POST send_sms.
 * 2. Assert 429 rate_limited before cap/window checks run.
 */
test('CSC hosted enrollment — send_sms when the per-session send lock is unavailable returns 429 rate_limited', function () {
    $session = csc_sms_session();
    $db = new CscIntegratorDbStub();
    $db->grantSmsSendLock = false;
    [$c] = csc_hosted_enrollment_controller(
        $session,
        'send_sms',
        'token-abc',
        null,
        null,
        [],
        null,
        null,
        $db,
    );
    $c->setPostPayload(['phone' => '0722334455']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['rate_limited']);
});

/**
 * Prerequisites:
 * - Session state=created (terms not yet accepted).
 *
 * Steps:
 * 1. POST verify_sms with a code.
 * 2. Assert 422 terms_not_accepted.
 */
test('CSC hosted enrollment — verify_sms without accepted terms returns 422 terms_not_accepted', function () {
    $session = csc_sms_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['terms_not_accepted']);
});

/**
 * Prerequisites:
 * - Session terms_accepted but chosen SCA method is totp.
 *
 * Steps:
 * 1. POST verify_sms with a code.
 * 2. Assert 422 sca_method_not_sms.
 */
test('CSC hosted enrollment — verify_sms when SCA method is not sms returns 422 sca_method_not_sms', function () {
    $session = csc_sms_session(['sca_methods' => 'totp', 'sca_method_chosen' => 'totp']);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['sca_method_not_sms']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; request omits the code field.
 *
 * Steps:
 * 1. POST verify_sms with an empty body.
 * 2. Assert 400 code_required.
 */
test('CSC hosted enrollment — verify_sms without a code returns 400 code_required', function () {
    $session = csc_sms_session();
    [$c] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['code_required']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen, sms_code_expires_at is in the past.
 *
 * Steps:
 * 1. POST verify_sms with the (now-irrelevant) matching code.
 * 2. Assert 400 sms_code_expired — the expiry check runs before the hash
 *    comparison.
 */
test('CSC hosted enrollment — verify_sms with an expired code returns 400 sms_code_expired', function () {
    $session = csc_sms_session([
        'sms_code_hash'        => hash('sha256', '123456|token-abc'),
        'sms_code_expires_at'  => gmdate('Y-m-d H:i:s', time() - 10),
    ]);
    [$c] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['sms_code_expired']);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen, valid (unexpired) code hash stored
 *   for '123456'.
 *
 * Steps:
 * 1. POST verify_sms with a different code ('000000').
 * 2. Assert 400 invalid_sms_code and markSmsVerified() is never called.
 */
test('CSC hosted enrollment — verify_sms with the wrong code returns 400 invalid_sms_code', function () {
    $session = csc_sms_session([
        'sms_code_hash'        => hash('sha256', '123456|token-abc'),
        'sms_code_expires_at'  => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload(['code' => '000000']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_sms_code'])
        ->and($enrollment->markSmsVerifiedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen; integrator locked signer_phone to
 *   +40722334455 but a pending OTP was minted for a different number before
 *   the lock was tightened (or via a stale send).
 *
 * Steps:
 * 1. POST verify_sms with the matching code for the pending OTP.
 * 2. Assert 400 phone_locked at the verification boundary; markSmsVerified()
 *    is never called.
 */
test('CSC hosted enrollment — verify_sms rejects a pending OTP on a non-locked number when phone is locked', function () {
    $session = csc_sms_session([
        'signer_phone'        => '+40722334455',
        'signer_phone_locked' => 1,
        'phone_e164'          => '+40711111111',
        'sms_code_hash'       => hash('sha256', '123456|token-abc'),
        'sms_code_expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'verify_sms');
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['phone_locked'])
        ->and($enrollment->markSmsVerifiedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session terms_accepted, sms chosen, valid (unexpired) code hash stored
 *   for '123456'.
 *
 * Steps:
 * 1. POST verify_sms with the matching code.
 * 2. Assert 200, data.status='sms_verified', markSmsVerified() called once,
 *    and the signer model's activateSms() called once (SMS becomes the
 *    signer's active OTP method) — all without touching any SMS provider.
 */
test('CSC hosted enrollment — verify_sms with the correct code succeeds', function () {
    $session = csc_sms_session([
        'sms_code_hash'        => hash('sha256', '123456|token-abc'),
        'sms_code_expires_at'  => gmdate('Y-m-d H:i:s', time() + 600),
    ]);
    $signer = new CscSignerModelStub();
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'verify_sms', 'token-abc', $signer);
    $c->setPostPayload(['code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['status' => 'sms_verified'])
        ->and($enrollment->markSmsVerifiedCalls)->toBe(1)
        ->and($signer->activateSmsCalls)->toBe(1)
        ->and($signer->lastActivateSmsSignerId)->toBe(77);
});
