<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/AccountDeletionTestDoubles.php';

/**
 * Unit tests for DELETE /account success, TOTP lockout, and AccountClosure mapping.
 * Negative TOTP/eligibility cases live in AccountDeletionTest.php.
 */

beforeEach(function () {
    $this->accountDeletionHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->accountDeletionSavedMethod = $this->accountDeletionHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    AccountClosure::reset();
});

afterEach(function () {
    if ($this->accountDeletionHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->accountDeletionSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
    AccountClosure::reset();
});

/**
 * Prerequisites:
 * - Customer 51 is eligible, has an enrolled TOTP secret, and is not locked out.
 * - The submitted code is generated from that secret with the same library the
 *   controller uses (no physical authenticator).
 * - AccountClosure is a test double that records the cascade and fires the
 *   security-event callback on success.
 *
 * Steps:
 * 1. Submit account deletion with a valid TOTP code.
 * 2. Assert the cascade ran for customer 51, the TOTP fail counter was cleared,
 *    one success audit was recorded without the code or secret, and the
 *    account_deleted security event was emitted.
 * 3. Assert the response reports data.status=deleted and includes cascaded
 *    duplicate customer ids from the closure result.
 */
test('Account API — valid TOTP deletes the account via cascade and records a security event', function () {
    [$controller, $load, , $validationModel, $cache] = account_deletion_controller();
    AccountClosure::$result = [
        'ok' => true,
        'error' => null,
        'cascaded_duplicate_customer_ids' => [88],
    ];
    $code = account_deletion_valid_totp_code();
    $controller->setPostPayload(['totp_code' => $code]);

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['success'])->toBe(1)
        ->and($controller->json['data']['status'])->toBe('deleted')
        ->and($controller->json['data']['cascaded_duplicate_customer_ids'])->toBe([88])
        ->and($load->loadedModels)->toBe(['account/customer', 'tool/validation'])
        ->and($validationModel->getTotpCalls)->toBe([51])
        ->and(AccountClosure::$calls)->toBe([['customer_id' => 51]])
        ->and($cache->deleted)->toContain('totp_fail_51')
        ->and($controller->totpAudits)->toBe([[51, 'account_delete', true, []]])
        ->and(json_encode($controller->totpAudits))->not->toContain($code)
        ->and(json_encode($controller->totpAudits))->not->toContain(account_deletion_totp_secret())
        ->and($controller->securityEvents)->toBe([
            [88, 'account_deleted', ['cascade_from_customer_id' => 51]],
            [51, 'account_deleted', []],
        ])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Customer 51 is eligible with a valid TOTP, but the shared totp_fail pool
 *   already sits at the lockout threshold.
 *
 * Steps:
 * 1. Submit a valid TOTP code while the fail cache is locked.
 * 2. Assert HTTP 429 too_many_totp_attempts with retry_after_minutes, a failure
 *    audit without secrets, and no AccountClosure or security-event call.
 */
test('Account API — deletion is locked out with 429 before TOTP verification', function () {
    [$controller, , , $validationModel, $cache] = account_deletion_controller();
    $cache->store['totp_fail_51'] = [
        'attempts' => 5,
        'expires_at' => time() + 900,
    ];
    $code = account_deletion_valid_totp_code();
    $controller->setPostPayload(['totp_code' => $code]);

    $controller->index();

    expect($controller->statusCode)->toBe(429)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['too_many_totp_attempts'])
        ->and($controller->json['data']['retry_after_minutes'])->toBe(15)
        ->and($controller->totpAudits)->toBe([[
            51,
            'account_delete',
            false,
            ['error' => 'too_many_totp_attempts'],
        ]])
        ->and(json_encode($controller->totpAudits))->not->toContain($code)
        ->and(json_encode($controller->totpAudits))->not->toContain(account_deletion_totp_secret())
        ->and(AccountClosure::$calls)->toBe([])
        ->and($controller->securityEvents)->toBe([])
        ->and($validationModel->getTotpCalls)->toBe([51]);
});

/**
 * Prerequisites:
 * - Customer 51 is eligible with an enrolled TOTP secret. Four prior failures
 *   are already stored in the shared totp_fail pool (threshold is 5).
 *
 * Steps:
 * 1. Submit an invalid TOTP code so this failure reaches the lockout threshold.
 * 2. Assert HTTP 429, no cascade, and no account_deleted security event.
 */
test('Account API — deletion maps the TOTP failure that reaches lockout to 429', function () {
    [$controller, , , , $cache] = account_deletion_controller();
    $cache->store['totp_fail_51'] = [
        'attempts' => 4,
        'expires_at' => time() + 900,
    ];
    $controller->setPostPayload(['totp_code' => '000000']);

    $controller->index();

    expect($controller->statusCode)->toBe(429)
        ->and($controller->json['error'])->toBe(['too_many_totp_attempts'])
        ->and($controller->json['data']['retry_after_minutes'])->toBe(15)
        ->and($cache->store['totp_fail_51']['attempts'])->toBe(5)
        ->and($controller->totpAudits)->toBe([[
            51,
            'account_delete',
            false,
            ['error' => 'invalid_totp'],
        ]])
        ->and(AccountClosure::$calls)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 passes TOTP. AccountClosure reports a non-Keycloak provider
 *   failure (certificate revocation).
 *
 * Steps:
 * 1. Submit a valid TOTP code.
 * 2. Assert TOTP success was audited and the fail counter cleared, but HTTP 502
 *    is returned, no account_deleted security event is recorded, and
 *    data.status is not deleted.
 */
test('Account API — deletion maps AccountClosure provider failure to 502', function () {
    [$controller, , , , $cache] = account_deletion_controller();
    AccountClosure::$result = [
        'ok' => false,
        'error' => 'certificate_revocation_failed',
        'cascaded_duplicate_customer_ids' => [],
    ];
    $controller->setPostPayload(['totp_code' => account_deletion_valid_totp_code()]);

    $controller->index();

    expect($controller->statusCode)->toBe(502)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['certificate_revocation_failed'])
        ->and($controller->json['data'] ?? [])->not->toHaveKey('status')
        ->and(AccountClosure::$calls)->toBe([['customer_id' => 51]])
        ->and($controller->totpAudits)->toBe([[51, 'account_delete', true, []]])
        ->and($cache->deleted)->toContain('totp_fail_51')
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 passes TOTP. AccountClosure reports Keycloak admin unavailable.
 *
 * Steps:
 * 1. Submit a valid TOTP code.
 * 2. Assert HTTP 503 keycloak_admin_unavailable, cascade was attempted, and no
 *    account_deleted security event was emitted.
 */
test('Account API — deletion maps Keycloak-unavailable closure to 503', function () {
    [$controller] = account_deletion_controller();
    AccountClosure::$result = [
        'ok' => false,
        'error' => 'keycloak_admin_unavailable',
        'cascaded_duplicate_customer_ids' => [],
    ];
    $controller->setPostPayload(['totp_code' => account_deletion_valid_totp_code()]);

    $controller->index();

    expect($controller->statusCode)->toBe(503)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['keycloak_admin_unavailable'])
        ->and(AccountClosure::$calls)->toBe([['customer_id' => 51]])
        ->and($controller->securityEvents)->toBe([]);
});
