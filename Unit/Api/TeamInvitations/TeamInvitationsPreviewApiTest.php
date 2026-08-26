<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/TeamInvitationsTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamInvitationsPreview} (public token lookup; no auth gate).
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
 * - Unit harness; no database. Preview is a public GET.
 *
 * Steps:
 * 1. Call index() with POST.
 * 2. Assert **HTTP 405** and allowed GET, OPTIONS; token is not read.
 */
test('Team invitations preview — unsupported HTTP method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $c = ti_make_preview_controller($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['GET', 'OPTIONS'])
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - GET without a usable token query value.
 *
 * Steps:
 * 1. Omit token, then send whitespace-only token.
 * 2. Assert **HTTP 400** token_required; model not loaded.
 */
test('Team invitations preview — missing or blank token returns 400', function (?string $token) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $registry->get('request')->get = $token === null ? [] : ['token' => $token];
    $c = ti_make_preview_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['token_required'])
        ->and($load->loadedModels)->toBe([])
        ->and($model->lastPreviewToken)->toBeNull();
})->with([
    'missing key' => [null],
    'blank after trim' => ['   '],
]);

/**
 * Prerequisites:
 * - Model cannot resolve the token.
 *
 * Steps:
 * 1. GET with a padded token.
 * 2. Assert **HTTP 404** invitation_not_found and the trimmed token is forwarded.
 */
test('Team invitations preview — unknown token maps to 404 invitation_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    $model->previewResult = ['ok' => false, 'reason' => 'invitation_not_found'];
    [$registry, $load] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $registry->get('request')->get = ['token' => '  gone  '];
    $c = ti_make_preview_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['invitation_not_found'])
        ->and($load->loadedModels)->toBe(['account/team_invitation'])
        ->and($model->lastPreviewToken)->toBe('gone');
});

/**
 * Prerequisites:
 * - Model fails without a reason key (expired/revoked handled as not found by default).
 *
 * Steps:
 * 1. GET a token whose preview result is not ok.
 * 2. Assert **HTTP 404** invitation_not_found.
 */
test('Team invitations preview — failed preview without reason defaults to invitation_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    $model->previewResult = ['ok' => false];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $registry->get('request')->get = ['token' => 'x'];
    $c = ti_make_preview_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['invitation_not_found']);
});

/**
 * Prerequisites:
 * - Model returns a public invitation payload (no raw token, no numeric ids).
 *
 * Steps:
 * 1. GET a valid token as an unauthenticated caller.
 * 2. Assert **HTTP 200** and data.invitation matches the model payload as-is.
 */
test('Team invitations preview — success returns safe invitation fields for a valid token', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new TeamInvitationsModelStub();
    $invitation = [
        'invitation_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        'status' => 'pending',
        'company_name' => 'Acme',
        'role_code' => 'employee',
        'role_name' => 'Employee',
        'invited_email' => 'joiner@example.com',
    ];
    $model->previewResult = ['ok' => true, 'invitation' => $invitation];
    [$registry] = ti_registry_with_model(new TeamInvitationsAdminCustomerStub(0, 0, 0), $model);
    $registry->get('request')->get = ['token' => 'active-token'];
    $c = ti_make_preview_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['invitation'])->toBe($invitation)
        ->and(isset($c->json['data']['invitation']['token']))->toBeFalse()
        ->and(isset($c->json['data']['invitation']['invitation_id']))->toBeFalse()
        ->and($c->sendResponseCalls)->toBe(1);
});
