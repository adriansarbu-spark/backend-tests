<?php

declare(strict_types=1);

use RobThree\Auth\TwoFactorAuth;

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/TeamInvitationsTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamInvitationsResend}.
 */

beforeEach(function () {
    $this->tiHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->tiSavedRequestMethod = $this->tiHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $this->tiHadHttpHost = array_key_exists('HTTP_HOST', $_SERVER);
    $this->tiSavedHttpHost = $this->tiHadHttpHost ? $_SERVER['HTTP_HOST'] : null;
    $this->tiHadRemoteAddr = array_key_exists('REMOTE_ADDR', $_SERVER);
    $this->tiSavedRemoteAddr = $this->tiHadRemoteAddr ? $_SERVER['REMOTE_ADDR'] : null;
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

afterEach(function () {
    if (! $this->tiHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->tiSavedRequestMethod;
    }
    if (! $this->tiHadHttpHost) {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $this->tiSavedHttpHost;
    }
    if (! $this->tiHadRemoteAddr) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $this->tiSavedRemoteAddr;
    }
});

/**
 * @return array{0: TeamInvitationsModelStub, 1: TestableControllerPublicAPIV1TeamInvitationsResend, 2: TeamInvitationsLoadStub, 3: TeamInvitationsDbStub}
 */
function ti_resend_ready(
    TeamInvitationsAdminCustomerStub $customer,
    array $rowOverrides = [],
    array $rotateOverrides = [],
    ?TeamInvitationsDbStub $db = null,
    bool $grantPermission = true,
): array {
    $model = new TeamInvitationsModelStub();
    $row = ti_pending_resend_row($rowOverrides);
    $model->validateResendResult = ['ok' => true, 'row' => $row];
    $model->rotateResult = ti_rotated_invitation($rotateOverrides);
    $db ??= new TeamInvitationsDbStub(1);
    [$registry, $load, $wiredDb] = ti_registry_with_model($customer, $model, $db);
    $c = ti_make_resend_controller($registry, $grantPermission);

    return [$model, $c, $load, $wiredDb];
}

/**
 * Prerequisites:
 * - Unit harness; no application database.
 *
 * Steps:
 * 1. Call index() with GET.
 * 2. Assert **HTTP 405** before auth.
 */
test('Team invitations resend — unsupported HTTP method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['POST', 'OPTIONS'])
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer id 0.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 401** unauthenticated even when a company id is present.
 */
test('Team invitations resend — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 10, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with company id 0.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 400** company_context_required.
 */
test('Team invitations resend — missing company context returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 0, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated company admin without resend route permission.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 403** access_denied before the admin-role check.
 */
test('Team invitations resend — missing route permission returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_resend_controller($registry, false);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated employee in a company.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 403** admin_role_required.
 */
test('Team invitations resend — non-admin role cannot resend', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(
        new TeamInvitationsAdminCustomerStub(1, 10, 5, 'employee'),
        $model,
        isCompanyAdmin: false,
    );
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment']);
});

/**
 * Prerequisites:
 * - Company admin; invitation_uuid missing or blank after trim.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 400** invitation_uuid_required.
 */
test('Team invitations resend — missing or blank invitation_uuid returns 400', function (array $payload) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invitation_uuid_required'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment']);
})->with([
    'missing key' => [[]],
    'blank after trim' => [['invitation_uuid' => '  ']],
]);

/**
 * Prerequisites:
 * - Model cannot find the UUID in this company.
 *
 * Steps:
 * 1. POST a padded UUID.
 * 2. Assert **HTTP 404** and tenant arguments are forwarded.
 */
test('Team invitations resend — unknown UUID maps to 404 invitation_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->validateResendResult = ['ok' => false, 'reason' => 'invitation_not_found'];
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 33, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => '  missing-uuid  ']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['invitation_not_found'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'account/team_invitation'])
        ->and($model->lastValidateResendArgs)->toBe([33, 'missing-uuid']);
});

/**
 * Prerequisites:
 * - Model rejects a non-pending or expired invitation, or omits a reason.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 400** with the model reason or invitation_resend_failed.
 */
test('Team invitations resend — other validate failures map to 400', function (array $result, string $expectedError) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->validateResendResult = $result;
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u1']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe([$expectedError]);
})->with([
    'not pending' => [['ok' => false, 'reason' => 'invitation_not_pending'], 'invitation_not_pending'],
    'expired' => [['ok' => false, 'reason' => 'invitation_expired'], 'invitation_expired'],
    'missing reason' => [['ok' => false], 'invitation_resend_failed'],
]);

/**
 * Prerequisites:
 * - Pending admin invitation; inviter has TOTP enrolled; totp_code omitted.
 *
 * Steps:
 * 1. POST resend without totp_code.
 * 2. Assert **HTTP 400** totp_code_required; token is not rotated.
 */
test('Team invitations resend — admin invite with TOTP enrolled requires totp_code', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'admin', 'Pat', 'pat@example.com', 'JBSWY3DPEHPK3PXP');
    [$model, $c] = ti_resend_ready($customer, ['role_code' => 'admin']);
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['totp_code_required'])
        ->and($model->lastRotateInvitationId)->toBeNull();
});

/**
 * Prerequisites:
 * - Pending admin invitation; inviter has TOTP enrolled; code is wrong.
 *
 * Steps:
 * 1. POST resend with totp_code 000000.
 * 2. Assert **HTTP 400** invalid_totp; token is not rotated.
 */
test('Team invitations resend — admin invite rejects invalid totp_code', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'admin', 'Pat', 'pat@example.com', 'JBSWY3DPEHPK3PXP');
    [$model, $c] = ti_resend_ready($customer, ['role_code' => 'Admin']);
    $c->setPostPayload([
        'invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'totp_code' => '000000',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_totp'])
        ->and($model->lastRotateInvitationId)->toBeNull();
});

/**
 * Prerequisites:
 * - Send limiter denies the resend (cooldown).
 *
 * Steps:
 * 1. POST a pending employee invitation.
 * 2. Assert **HTTP 429**, default rate-limit error, and retry_after_seconds in data.
 */
test('Team invitations resend — send limiter returns 429 with retry_after_seconds', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$model, $c] = ti_resend_ready(new TeamInvitationsAdminCustomerStub(1, 10, 5));
    $c->sendAllowedResult = [
        'ok' => false,
        'error' => 'team_invitation_send_rate_limited',
        'retry_after_seconds' => 42,
    ];
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['team_invitation_send_rate_limited'])
        ->and($c->json['data']['retry_after_seconds'])->toBe(42)
        ->and($model->lastRotateInvitationId)->toBeNull();
});

/**
 * Prerequisites:
 * - Send limiter denies without retry_after_seconds or a named error.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 429** with the default error and no retry payload.
 */
test('Team invitations resend — send limiter without retry hint uses default error', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [, $c] = ti_resend_ready(new TeamInvitationsAdminCustomerStub(1, 10, 5));
    $c->sendAllowedResult = ['ok' => false];
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['team_invitation_send_rate_limited'])
        ->and($c->json['data'])->toBe([]);
});

/**
 * Prerequisites:
 * - Validate succeeds; rotate returns no token (persistence failure).
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 500** invitation_resend_failed and the rate-limit reservation is released.
 */
test('Team invitations resend — rotate without token returns 500 and releases reservation', function (?array $rotateResult) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->validateResendResult = ['ok' => true, 'row' => ti_pending_resend_row()];
    $model->rotateResult = $rotateResult;
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_resend_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_resend_failed'])
        ->and($c->releasedReservations)->toHaveCount(1)
        ->and($model->recordEmailSendCalls)->toBe([]);
})->with([
    'null' => [null],
    'empty token' => [['invitation_uuid' => 'u', 'token' => '']],
]);

/**
 * Prerequisites:
 * - Rotate succeeds; fake mail queue insert returns no id.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 500** invitation_email_queue_failed, queued=false is recorded, reservation released.
 */
test('Team invitations resend — email queue failure returns 500 and releases reservation', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $db = new TeamInvitationsDbStub(0);
    [$model, $c] = ti_resend_ready(new TeamInvitationsAdminCustomerStub(1, 10, 5), db: $db);
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_email_queue_failed'])
        ->and($model->recordEmailSendCalls)->toBe([
            ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', false, 'queue_failed'],
        ])
        ->and($c->releasedReservations)->toHaveCount(1)
        ->and(isset($c->json['data']['invitation']))->toBeFalse();
});

/**
 * Prerequisites:
 * - Case-variant Admin; employee invitation; queue insert succeeds.
 *
 * Steps:
 * 1. POST resend.
 * 2. Assert **HTTP 200**, token stripped from the body, and queued=true is recorded.
 */
test('Team invitations resend — success strips token and records queued send', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'Admin');
    [$model, $c, $load, $db] = ti_resend_ready($customer);
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['invitation_uuid'])->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
        ->and(isset($c->json['data']['invitation']['token']))->toBeFalse()
        ->and($model->lastRotateInvitationId)->toBe(7)
        ->and($model->recordEmailSendCalls)->toBe([
            ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', true, null],
        ])
        ->and($c->releasedReservations)->toBe([])
        ->and($db->queries)->not->toBe([])
        ->and($load->loadedLibraries)->toContain('emailQueue');
});

/**
 * Prerequisites:
 * - Admin invitation; inviter has no TOTP enrolled, so totp_code is not required.
 * - Sender first name is empty (email uses the Someone fallback).
 * - Send-allowed result has no rate_limit_reservation (empty-array fallback).
 *
 * Steps:
 * 1. POST resend without totp_code.
 * 2. Assert **HTTP 200**.
 */
test('Team invitations resend — admin invite without TOTP enrolled succeeds', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'admin', '', 'pat@example.com', null);
    [$model, $c] = ti_resend_ready($customer, ['role_code' => 'admin']);
    $c->sendAllowedResult = ['ok' => true];
    $c->setPostPayload(['invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['invitation_uuid'])->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
        ->and($model->lastRotateInvitationId)->toBe(7);
});

/**
 * Prerequisites:
 * - Admin invitation; inviter has TOTP enrolled; code is valid.
 *
 * Steps:
 * 1. POST resend with a current TOTP code.
 * 2. Assert **HTTP 200** and the token is rotated.
 */
test('Team invitations resend — admin invite accepts a valid totp_code', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $secret = 'JBSWY3DPEHPK3PXP';
    $code = (new TwoFactorAuth())->getCode($secret);
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'admin', 'Pat', 'pat@example.com', $secret);
    [$model, $c] = ti_resend_ready($customer, ['role_code' => 'admin']);
    $c->setPostPayload([
        'invitation_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'totp_code' => $code,
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($model->lastRotateInvitationId)->toBe(7)
        ->and(isset($c->json['data']['invitation']['token']))->toBeFalse();
});
