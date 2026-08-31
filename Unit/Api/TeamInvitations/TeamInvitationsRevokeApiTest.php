<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/TeamInvitationsTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamInvitationsRevoke}.
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
 * 1. Call index() with GET.
 * 2. Assert **HTTP 405** before auth.
 */
test('Team invitations revoke — unsupported HTTP method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_revoke_controller($registry);
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
 * 1. POST revoke.
 * 2. Assert **HTTP 401** unauthenticated; company is not required yet.
 */
test('Team invitations revoke — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $c = ti_make_revoke_controller($registry);
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
 * 1. POST revoke.
 * 2. Assert **HTTP 400** company_context_required.
 */
test('Team invitations revoke — missing company context returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 0, 5), $model);
    $c = ti_make_revoke_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated company user without revoke route permission.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert **HTTP 403** access_denied before the admin-role check.
 */
test('Team invitations revoke — missing route permission returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_revoke_controller($registry, false);
    $c->setPostPayload(['invitation_uuid' => 'u']);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated employee in a company (not admin).
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert **HTTP 403** admin_role_required; tenant model is not loaded.
 */
test('Team invitations revoke — non-admin role cannot revoke', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(
        new TeamInvitationsAdminCustomerStub(1, 10, 5, 'employee'),
        $model,
        isCompanyAdmin: false,
    );
    $c = ti_make_revoke_controller($registry);
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
 * 1. POST revoke.
 * 2. Assert **HTTP 400** invitation_uuid_required.
 */
test('Team invitations revoke — missing or blank invitation_uuid returns 400', function (array $payload) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_revoke_controller($registry);
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
 * - Model cannot find the UUID in this company (foreign or unknown).
 *
 * Steps:
 * 1. POST a padded UUID.
 * 2. Assert **HTTP 404**; company id and trimmed UUID are forwarded (tenant scope).
 */
test('Team invitations revoke — unknown UUID maps to 404 invitation_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->revokeResult = ['ok' => false, 'reason' => 'invitation_not_found'];
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 22, 5), $model);
    $c = ti_make_revoke_controller($registry);
    $c->setPostPayload(['invitation_uuid' => '  foreign-uuid  ']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['invitation_not_found'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'account/team_invitation'])
        ->and($model->lastRevokeArgs)->toBe([22, 'foreign-uuid']);
});

/**
 * Prerequisites:
 * - Model rejects a non-pending invitation, or omits a reason.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert **HTTP 400** with the model reason or invitation_revoke_failed.
 */
test('Team invitations revoke — other model failures map to 400', function (array $result, string $expectedError) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->revokeResult = $result;
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_revoke_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u1']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe([$expectedError]);
})->with([
    'not pending' => [['ok' => false, 'reason' => 'invitation_not_pending'], 'invitation_not_pending'],
    'missing reason' => [['ok' => false], 'invitation_revoke_failed'],
]);

/**
 * Prerequisites:
 * - Case-variant Admin role; model revokes successfully.
 *
 * Steps:
 * 1. POST revoke.
 * 2. Assert **HTTP 200** and invitation is under data.
 */
test('Team invitations revoke — success returns invitation and allows case-variant Admin', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->revokeResult = [
        'ok' => true,
        'invitation' => [
            'invitation_uuid' => 'u1',
            'status' => 'revoked',
        ],
    ];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5, 'Admin'), $model);
    $c = ti_make_revoke_controller($registry);
    $c->setPostPayload(['invitation_uuid' => 'u1']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation']['invitation_uuid'])->toBe('u1')
        ->and($c->json['data']['invitation']['status'])->toBe('revoked')
        ->and($c->sendResponseCalls)->toBe(1);
});
