<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/AccountDeletionTestDoubles.php';

beforeEach(function () {
    $this->accountDeletionHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->accountDeletionSavedMethod = $this->accountDeletionHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
});

afterEach(function () {
    if ($this->accountDeletionHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->accountDeletionSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - An isolated account controller harness with no authenticated customer.
 *
 * Steps:
 * 1. Request account deletion.
 * 2. Assert the request is rejected before account, TOTP, security-event, or closure work starts.
 */
test('Account API — deletion rejects unauthenticated callers before protected work', function () {
    [$controller, $load, $customerModel, $validationModel] = account_deletion_controller(customerId: 0);

    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($customerModel->deletedChecks)->toBe([])
        ->and($validationModel->hasTotpCalls)->toBe([])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 is authenticated but the account is already marked deleted.
 *
 * Steps:
 * 1. Request account deletion again.
 * 2. Assert the endpoint reports the terminal state and never evaluates TOTP or starts a cascade.
 */
test('Account API — deletion is blocked for an already deleted account', function () {
    [$controller, $load, $customerModel, $validationModel] = account_deletion_controller();
    $customerModel->deleted = true;

    $controller->index();

    expect($controller->statusCode)->toBe(410)
        ->and($controller->json['error'])->toBe(['account_already_deleted'])
        ->and($load->loadedModels)->toBe(['account/customer'])
        ->and($customerModel->deletedChecks)->toBe([51])
        ->and($customerModel->eligibilityChecks)->toBe([])
        ->and($validationModel->hasTotpCalls)->toBe([])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 is authenticated but the account model reports an eligibility blocker.
 *
 * Steps:
 * 1. Request deletion from a company context or as the final active administrator.
 * 2. Assert the stable blocker/status and scoped company data are returned.
 * 3. Assert TOTP and account-closure side effects do not run.
 */
test('Account API — deletion enforces personal-context and last-admin eligibility', function (
    int $companyId,
    string $reason,
    int $expectedStatus,
) {
    [$controller, $load, $customerModel, $validationModel] = account_deletion_controller(companyId: $companyId);
    $customerModel->eligibility = [
        'allowed' => false,
        'reason' => $reason,
        'blocking_companies' => [['uuid' => 'company-visible-to-caller']],
    ];

    $controller->index();

    expect($controller->statusCode)->toBe($expectedStatus)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe([$reason])
        ->and($controller->json['data']['blocking_companies'])->toBe([
            ['uuid' => 'company-visible-to-caller'],
        ])
        ->and($customerModel->eligibilityChecks)->toBe([[51, $companyId]])
        ->and($load->loadedModels)->toBe(['account/customer'])
        ->and($validationModel->hasTotpCalls)->toBe([])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
})->with([
    'active company context' => [72, 'company_account_deletion_not_allowed', 403],
    'last active administrator' => [0, 'last_admin_cannot_delete_account', 409],
]);

/**
 * Prerequisites:
 * - Customer 51 is eligible for deletion but has no successful TOTP enrollment.
 *
 * Steps:
 * 1. Request account deletion.
 * 2. Assert deletion fails closed before reading a TOTP secret or invoking account closure.
 */
test('Account API — deletion requires an enrolled TOTP method', function () {
    [$controller, $load, , $validationModel] = account_deletion_controller();
    $validationModel->hasSuccessTotp = false;

    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['totp_not_configured'])
        ->and($load->loadedModels)->toBe(['account/customer', 'tool/validation'])
        ->and($validationModel->hasTotpCalls)->toBe([51])
        ->and($validationModel->getTotpCalls)->toBe([])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
});

/**
 * Prerequisites:
 * - The enrollment lookup says TOTP exists, but no usable secret row is available.
 *
 * Steps:
 * 1. Request account deletion with a nominal code.
 * 2. Assert deletion fails closed and neither verification audit nor closure work occurs.
 */
test('Account API — deletion fails closed when the stored TOTP secret is unavailable', function ($totpRow) {
    [$controller, , , $validationModel] = account_deletion_controller();
    $validationModel->totpRow = $totpRow;
    $controller->setPostPayload(['totp_code' => '123456']);

    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['totp_not_configured'])
        ->and($validationModel->getTotpCalls)->toBe([51])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
})->with([
    'missing row' => null,
    'empty secret' => [['code' => '']],
]);

/**
 * Prerequisites:
 * - Customer 51 is eligible and has an enrolled TOTP secret.
 *
 * Steps:
 * 1. Request account deletion without supplying a TOTP code.
 * 2. Assert a stable validation error and no audit/cascade side effect.
 */
test('Account API — deletion requires a non-empty TOTP code', function ($payload) {
    [$controller] = account_deletion_controller();
    $controller->setPostPayload($payload);

    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['totp_code_required'])
        ->and($controller->totpAudits)->toBe([])
        ->and($controller->securityEvents)->toBe([]);
})->with([
    'missing field' => [[]],
    'blank field' => [['totp_code' => '   ']],
]);

/**
 * Prerequisites:
 * - Customer 51 is eligible and has a known TOTP secret.
 *
 * Steps:
 * 1. Submit an invalid TOTP code for account deletion.
 * 2. Assert the request is rejected and exactly one failure audit is recorded without the code or secret.
 * 3. Assert no account security event is emitted, proving the closure callback was not reached.
 */
test('Account API — deletion rejects invalid TOTP and audits without secrets', function () {
    [$controller] = account_deletion_controller();
    $controller->setPostPayload(['totp_code' => '000000']);

    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['success'])->toBe(0)
        ->and($controller->json['error'])->toBe(['invalid_totp'])
        ->and($controller->totpAudits)->toBe([[
            51,
            'account_delete',
            false,
            ['error' => 'invalid_totp'],
        ]])
        ->and(json_encode($controller->totpAudits))->not->toContain('000000')
        ->and(json_encode($controller->totpAudits))->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($controller->securityEvents)->toBe([]);
});
