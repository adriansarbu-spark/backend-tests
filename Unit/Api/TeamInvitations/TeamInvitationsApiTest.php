<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'team/invitations.php';
require_once __DIR__ . '/_support/TeamInvitationsTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamInvitations} (admin gate, list, create).
 */

beforeEach(function () {
    $this->tiHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->tiSavedRequestMethod = $this->tiHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->tiHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->tiSavedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Unit harness; no database.
 *
 * Steps:
 * 1. index() with customer id 0 and GET.
 * 2. Assert 401 unauthenticated before admin or model checks.
 */
test('Team invitations API — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 10, 1), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated admin-shaped customer but company id is zero.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 company_context_required (checked before role gate in requireCompanyAdmin).
 */
test('Team invitations API — missing company context returns 400 before listing', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 0, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated non-admin role (employee) with valid company.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 403 admin_role_required; model not loaded.
 */
test('Team invitations API — non-admin role cannot list invitations', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'employee');
    [$registry, $load] = ti_registry_with_model($customer, $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Case-insensitive admin role (Admin) should pass the gate.
 *
 * Steps:
 * 1. GET with status filter.
 * 2. Assert model include_history true and status passed; response lists invitations.
 */
test('Team invitations API — GET list allows case-variant Admin role and forwards status filter', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    $model->listResult = [
        'items'    => [['invitation_uuid' => 'i1', 'status' => 'pending']],
        'page'     => 1,
        'per_page' => 20,
        'total'    => 1,
    ];
    $customer = new TeamInvitationsAdminCustomerStub(1, 10, 5, 'Admin');
    [$registry, $load] = ti_registry_with_model($customer, $model);
    $registry->get('request')->get = ['status' => 'pending'];
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($load->loadedModels)->toBe(['account/team_invitation'])
        ->and($model->lastInvitationsPagedArgs['include_history'])->toBeTrue()
        ->and($model->lastInvitationsPagedArgs['status'])->toBe('pending')
        ->and($c->json['data']['invitations'])->toHaveCount(1)
        ->and($c->json['pagination']['total'])->toBe(1);
});

/**
 * Prerequisites:
 * - Company admin.
 *
 * Steps:
 * 1. DELETE index().
 * 2. Assert 405 and allowed GET, POST, OPTIONS (model is loaded before method routing, same as production controller).
 */
test('Team invitations API — unsupported HTTP method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe(['account/team_invitation']);
});

/**
 * Prerequisites:
 * - Admin POST; model rejects email format.
 *
 * Steps:
 * 1. POST invalid email.
 * 2. Assert 400 invalid_email before createOrRefresh runs (createResult ignored).
 */
test('Team invitations API — POST create rejects invalid email', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->validEmail = false;
    $model->createResult = ['token' => 'x', 'invitation_uuid' => 'u'];
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->setPostPayload(['email' => 'bad', 'role_code' => 'admin']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_email'])
        ->and($model->recordEmailSendCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Admin POST; email valid; role code not allowed.
 *
 * Steps:
 * 1. POST with invalid_role_code path.
 * 2. Assert 400 invalid_role_code.
 */
test('Team invitations API — POST create rejects disallowed role_code', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->allowedRole = false;
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->setPostPayload(['email' => 'ok@example.com', 'role_code' => 'superuser']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_role_code']);
});

/**
 * Prerequisites:
 * - Model returns invitation without token (persistence failure simulation).
 *
 * Steps:
 * 1. POST valid email.
 * 2. Assert 500 invitation_create_failed; recordEmailSend not called.
 */
test('Team invitations API — POST create fails when model returns no token', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->createResult = ['invitation_uuid' => 'no-token-row'];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->setPostPayload(['email' => 'new@example.com']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_create_failed'])
        ->and($model->recordEmailSendCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Create succeeds; harness forces queueInvitationEmail to false (mail queue down).
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 500 invitation_email_queue_failed and recordEmailSend sees queued=false.
 */
test('Team invitations API — POST create surfaces email queue failure after persistence', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->createResult = [
        'token'            => 'secret-token',
        'invitation_uuid'  => '11111111-1111-1111-1111-111111111111',
        'invited_email'    => 'peer@example.com',
        'role_code'        => 'employee',
        'company_name'     => 'Co',
        'role_name'        => 'Employee',
    ];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->queueInvitationEmailResult = false;
    $c->setPostPayload(['email' => 'peer@example.com']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['invitation_email_queue_failed'])
        ->and($model->recordEmailSendCalls)->toHaveCount(1)
        ->and($model->recordEmailSendCalls[0][1])->toBeFalse()
        ->and($model->recordEmailSendCalls[0][2])->toBe('queue_failed');
});

/**
 * Prerequisites:
 * - Create succeeds and queue returns true; response must not leak raw token.
 *
 * Steps:
 * 1. POST with empty role_code in body (controller defaults to employee).
 * 2. Assert 200, invitation in data, token key absent from JSON payload.
 */
test('Team invitations API — POST create success strips token from response body', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->createResult = [
        'token'            => 'raw-secret',
        'invitation_uuid'  => '22222222-2222-2222-2222-222222222222',
        'invited_email'    => 'joiner@example.com',
        'role_code'        => 'employee',
    ];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(9, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1TeamInvitations($registry);
    $c->setPostPayload(['email' => 'joiner@example.com', 'role_code' => '']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['invitation_uuid'])->toBe('22222222-2222-2222-2222-222222222222')
        ->and(isset($c->json['data']['invitation']['token']))->toBeFalse()
        ->and($model->recordEmailSendCalls[0][1])->toBeTrue();
});
