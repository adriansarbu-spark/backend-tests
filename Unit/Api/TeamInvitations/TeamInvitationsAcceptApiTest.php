<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/TeamInvitationsTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamInvitationsAccept}.
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
 * 2. Assert **HTTP 405** and allowed POST, OPTIONS before auth.
 */
test('Team invitations accept — unsupported HTTP method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['POST', 'OPTIONS'])
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer id 0 (not signed in).
 *
 * Steps:
 * 1. POST accept.
 * 2. Assert **HTTP 401** unauthenticated; model not loaded.
 */
test('Team invitations accept — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload(['token' => 'abc']);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer without the accept route permission.
 *
 * Steps:
 * 1. POST accept.
 * 2. Assert **HTTP 403** access_denied before token or model checks.
 */
test('Team invitations accept — missing route permission returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_accept_controller($registry, false);
    $c->setPostPayload(['token' => 'abc']);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with accept permission; token missing or blank after trim.
 *
 * Steps:
 * 1. POST without token, then with whitespace-only token.
 * 2. Assert **HTTP 400** token_required both times; model not loaded.
 */
test('Team invitations accept — missing or blank token returns 400', function (array $payload) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload($payload);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['token_required'])
        ->and($load->loadedModels)->toBe([]);
})->with([
    'missing key' => [[]],
    'blank after trim' => [['token' => '   ']],
]);

/**
 * Prerequisites:
 * - Model reports invitation_not_found.
 *
 * Steps:
 * 1. POST a token the model does not recognise.
 * 2. Assert **HTTP 404** and the trimmed token was forwarded.
 */
test('Team invitations accept — unknown token maps to 404 invitation_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->acceptResult = ['ok' => false, 'reason' => 'invitation_not_found'];
    [$registry, $load] = ti_registry_with_model(
        new TeamInvitationsAdminCustomerStub(9, 10, 5, 'employee', 'Pat', 'pat@example.com'),
        $model,
    );
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload(['token' => '  secret-token  ']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['invitation_not_found'])
        ->and($load->loadedModels)->toBe(['account/team_invitation'])
        ->and($model->lastAcceptArgs)->toMatchArray([
            'token' => 'secret-token',
            'customer_id' => 9,
            'email' => 'pat@example.com',
        ]);
});

/**
 * Prerequisites:
 * - Model returns reason unauthenticated (customer id rejected downstream).
 *
 * Steps:
 * 1. POST a token.
 * 2. Assert **HTTP 401**.
 */
test('Team invitations accept — model unauthenticated reason maps to 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->acceptResult = ['ok' => false, 'reason' => 'unauthenticated'];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload(['token' => 'tok']);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated']);
});

/**
 * Prerequisites:
 * - Model rejects expired, already-used, or mismatched invitations.
 *
 * Steps:
 * 1. POST accept.
 * 2. Assert **HTTP 400** with the model reason (or invitation_accept_failed when reason is omitted).
 */
test('Team invitations accept — other model failures map to 400', function (array $result, string $expectedError) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->acceptResult = $result;
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(1, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload(['token' => 'tok']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe([$expectedError]);
})->with([
    'expired' => [['ok' => false, 'reason' => 'invitation_expired'], 'invitation_expired'],
    'not available' => [['ok' => false, 'reason' => 'invitation_not_available'], 'invitation_not_available'],
    'missing reason' => [['ok' => false], 'invitation_accept_failed'],
]);

/**
 * Prerequisites:
 * - Model accepts the token and returns membership.
 *
 * Steps:
 * 1. POST a valid token.
 * 2. Assert **HTTP 200** and membership is copied onto data without wrapping.
 */
test('Team invitations accept — success returns membership payload', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    $model->acceptResult = [
        'ok' => true,
        'membership' => [
            'role_uuid' => 'role-1',
            'role_code' => 'employee',
            'company_uuid' => 'co-1',
        ],
    ];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(4, 10, 5), $model);
    $c = ti_make_accept_controller($registry);
    $c->setPostPayload(['token' => 'live-token']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['role_uuid'])->toBe('role-1')
        ->and($c->json['data']['role_code'])->toBe('employee')
        ->and($c->sendResponseCalls)->toBe(1);
});
