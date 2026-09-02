<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/AccountTotpTestDoubles.php';

/**
 * Unit tests for GET/POST /account/totp: setup, confirm, disable, lockout, secrets.
 */

beforeEach(function () {
    $this->accountTotpHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->accountTotpSavedMethod = $this->accountTotpHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
});

afterEach(function () {
    if ($this->accountTotpHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->accountTotpSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - Isolated TOTP controller harness; no authenticator device.
 *
 * Steps:
 * 1. GET TOTP status with customer id 0.
 * 2. Assert 401 before the validation model is loaded and no secret is returned.
 */
test('Account TOTP API — status rejects unauthenticated callers', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load] = account_totp_controller(customerId: 0);

    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($controller->json['data'] ?? [])->not->toHaveKey('manual_code')
        ->and($controller->json['data'] ?? [])->not->toHaveKey('otpauth_url');
});

/**
 * Prerequisites:
 * - Authenticated customer 51 with TOTP enrolled.
 *
 * Steps:
 * 1. GET TOTP status.
 * 2. Assert totp_set and totp_enabled_at shape, and that the secret is absent.
 */
test('Account TOTP API — status never includes the TOTP secret', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$controller, $load, $validation] = account_totp_controller(totpSet: true);
    $validation->successRow = [
        'validation_id' => 9,
        'code' => 'JBSWY3DPEHPK3PXP',
        'date_added' => '2026-08-01 12:00:00',
    ];

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($load->loadedModels)->toBe(['tool/validation'])
        ->and($controller->json['data']['totp_set'])->toBeTrue()
        ->and($controller->json['data']['method'])->toBe('authenticator_app')
        ->and($controller->json['data']['totp_enabled_at'])->toBe('2026-08-01T12:00:00Z')
        ->and($controller->json['data'])->not->toHaveKey('manual_code')
        ->and($controller->json['data'])->not->toHaveKey('otpauth_url')
        ->and(json_encode($controller->json))->not->toContain('JBSWY3DPEHPK3PXP');
});

/**
 * Prerequisites:
 * - Customer 51 already has a successful TOTP enrollment.
 *
 * Steps:
 * 1. POST setup.
 * 2. Assert 409 totp_already_enabled, no new secret row, and no secret in the body.
 */
test('Account TOTP API — setup is rejected when TOTP is already enabled', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$controller, , $validation] = account_totp_controller(action: 'setup');
    $validation->hasSuccessTotp = true;

    $controller->index();

    expect($controller->statusCode)->toBe(409)
        ->and($controller->json['error'])->toBe(['totp_already_enabled'])
        ->and($validation->addCalls)->toBe([])
        ->and($controller->securityEvents)->toBe([])
        ->and($controller->json['data'] ?? [])->not->toHaveKey('manual_code');
});

/**
 * Prerequisites:
 * - Customer 51 has no TOTP. Recent setup count is already at the per-window limit.
 *
 * Steps:
 * 1. POST setup.
 * 2. Assert 429 rate_limited and that expire/add are not called.
 */
test('Account TOTP API — setup is rate limited before a new secret is issued', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$controller, , $validation] = account_totp_controller(action: 'setup');
    $validation->recentSetups = 3;

    $controller->index();

    expect($controller->statusCode)->toBe(429)
        ->and($controller->json['error'])->toBe(['rate_limited'])
        ->and($validation->expireCalls)->toBe([])
        ->and($validation->addCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 has no TOTP and is under the setup rate limit.
 *
 * Steps:
 * 1. POST setup.
 * 2. Confirm then disable using codes generated from the issued secret (same
 *    library as production; no physical device).
 * 3. Assert setup returns otpauth_url + manual_code, confirm persists success
 *    without echoing the secret, and disable tombstones the row and reports
 *    disabled.
 */
test('Account TOTP API — setup confirm and disable complete the lifecycle without leaking the secret after confirm', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$controller, $load, $validation, $cache, $db] = account_totp_controller(
        email: 'user@example.com',
        action: 'setup',
    );

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($validation->expireCalls)->toBe([51])
        ->and($validation->addCalls)->toHaveCount(1)
        ->and($controller->securityEvents)->toBe([[51, 'totp_setup_started', []]])
        ->and($controller->json['data']['manual_code'])->toBeString()->not->toBe('')
        ->and($controller->json['data']['otpauth_url'])->toStartWith('otpauth://totp/')
        ->and($controller->json['data']['otpauth_url'])->toContain($controller->json['data']['manual_code'])
        ->and($controller->json['data']['otpauth_url'])->toContain('user%40example.com');

    $secret = $controller->json['data']['manual_code'];
    expect($validation->addCalls[0])->toBe([51, $secret])
        ->and(json_encode($controller->securityEvents))->not->toContain($secret);

    $controller->setPostPayload(['totp_code' => account_totp_code_for($secret)]);
    $controller->request->get['action'] = 'confirm';
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];
    $controller->statusCode = 200;

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toBe(['status' => 'enabled'])
        ->and($controller->json['data'])->not->toHaveKey('manual_code')
        ->and($controller->json['data'])->not->toHaveKey('otpauth_url')
        ->and(json_encode($controller->json))->not->toContain($secret)
        ->and($validation->completeCalls)->toBe([101])
        ->and($validation->hasSuccessTotp)->toBeTrue()
        ->and($cache->deleted)->toContain('account_totp_confirm_51')
        ->and($controller->totpAudits)->toBe([[51, 'totp_setup_confirm', true, []]])
        ->and($controller->securityEvents[1] ?? null)->toBe([51, 'totp_enabled', []])
        ->and(json_encode($controller->totpAudits))->not->toContain($secret);

    $controller->request->get['action'] = 'disable';
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];
    $controller->statusCode = 200;
    $controller->setPostPayload(['totp_code' => account_totp_code_for($secret)]);

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toBe(['status' => 'disabled'])
        ->and($controller->json['data'])->not->toHaveKey('manual_code')
        ->and(json_encode($controller->json))->not->toContain($secret)
        ->and($db->queries)->toHaveCount(1)
        ->and($db->queries[0])->toContain('deleted = 1')
        ->and($db->queries[0])->toContain('101')
        ->and($db->queries[0])->toContain('expired')
        ->and($cache->deleted)->toContain('account_totp_disable_51')
        ->and($controller->totpAudits[1] ?? null)->toBe([51, 'totp_disable', true, []])
        ->and($controller->securityEvents[2] ?? null)->toBe([51, 'totp_disabled', []])
        ->and($load->loadedModels)->toBe(['tool/validation', 'tool/validation', 'tool/validation']);
});

/**
 * Prerequisites:
 * - A pending TOTP setup exists for customer 51. Confirm already has four
 *   stored failures (threshold 5).
 *
 * Steps:
 * 1. POST confirm with an invalid code.
 * 2. Assert HTTP 429, the pending setup is expired, completeValidation is not
 *    called, and the audit/cache payload never includes the secret or code.
 */
test('Account TOTP API — confirm lockout expires the pending secret after too many invalid codes', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$controller, , $validation, $cache] = account_totp_controller(action: 'confirm');
    $secret = 'JBSWY3DPEHPK3PXP';
    $validation->pendingRow = [
        'validation_id' => 44,
        'customer_id' => 51,
        'code' => $secret,
        'status' => ModelToolValidation::STATUS_IN_PROGRESS,
        'date_added' => gmdate('Y-m-d H:i:s'),
    ];
    $cache->store['account_totp_confirm_51'] = [
        'attempts' => 4,
        'expires_at' => time() + 900,
    ];
    $controller->setPostPayload(['totp_code' => '000000']);

    $controller->index();

    expect($controller->statusCode)->toBe(429)
        ->and($controller->json['error'])->toBe(['rate_limited'])
        ->and($validation->completeCalls)->toBe([])
        ->and($validation->expireCalls)->toBe([51])
        ->and($validation->pendingRow)->toBeNull()
        ->and($cache->store['account_totp_confirm_51']['attempts'])->toBe(5)
        ->and($controller->totpAudits)->toBe([[
            51,
            'totp_setup_confirm',
            false,
            ['error' => 'invalid_totp'],
        ]])
        ->and($controller->securityEvents)->toBe([])
        ->and(json_encode($controller->totpAudits))->not->toContain('000000')
        ->and(json_encode($controller->totpAudits))->not->toContain($secret)
        ->and(json_encode($cache->store))->not->toContain($secret);
});

/**
 * Prerequisites:
 * - Confirm lockout is already active for customer 51.
 *
 * Steps:
 * 1. POST confirm with any code.
 * 2. Assert 429 before the pending secret is read or a verification audit runs.
 */
test('Account TOTP API — confirm returns 429 without reading the pending secret when already locked', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$controller, , $validation, $cache] = account_totp_controller(action: 'confirm');
    $cache->store['account_totp_confirm_51'] = [
        'attempts' => 5,
        'expires_at' => time() + 900,
    ];
    $controller->setPostPayload(['totp_code' => '123456']);

    $controller->index();

    expect($controller->statusCode)->toBe(429)
        ->and($controller->json['error'])->toBe(['rate_limited'])
        ->and($validation->hasTotpCalls)->toBe([])
        ->and($validation->getTotpCalls)->toBe([])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});
