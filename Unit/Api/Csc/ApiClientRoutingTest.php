<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for CSC api_client routing (index + rotate_secret detection).
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
 * - Unauthenticated customer.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 401.
 */
test('CSC api_client routing — unauthenticated returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(0, 10, 1));
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated']);
});

/**
 * Prerequisites:
 * - Admin; DELETE method.
 *
 * Steps:
 * 1. Call index() with DELETE.
 * 2. Assert 405 with GET/POST/PATCH allowed.
 */
test('CSC api_client routing — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toContain('GET')
        ->and($c->allowedHeaders)->toContain('POST')
        ->and($c->allowedHeaders)->toContain('PATCH');
});

/**
 * Prerequisites:
 * - Admin; GET without rotate markers.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert model overview returned.
 */
test('CSC api_client routing — GET returns public overview', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new CscApiClientModelStub();
    $model->publicOverview = ['provisioned' => false, 'note' => 'unit'];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['provisioned' => false, 'note' => 'unit']);
});

/**
 * Prerequisites:
 * - route query contains rotate_secret substring; rotate permission missing.
 *
 * Steps:
 * 1. POST index() with route=...rotate_secret....
 * 2. Assert 403 access_denied for rotate path (not create).
 */
test('CSC api_client routing — rotate_secret substring routes to rotate permission', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $registry->get('request')->get = ['route' => 'publicapi/v1/csc/api_client/rotate_secret'];
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions(false); // no rotate permission
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - Unrelated route containing rotate_secret as substring (characterization).
 *
 * Steps:
 * 1. POST with route containing rotate_secret elsewhere.
 * 2. Assert rotate path is taken (substring detection).
 */
test('CSC api_client routing — unrelated substring containing rotate_secret still triggers rotate', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $registry->get('request')->get = ['route' => 'publicapi/v1/other/rotate_secret_debug'];
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions(false);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - _route_ uses rotate-secret hyphen form.
 *
 * Steps:
 * 1. POST with _route_ containing rotate-secret.
 * 2. Assert rotate permission gate applies.
 */
test('CSC api_client routing — _route_ rotate-secret hyphen form triggers rotate', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $registry->get('request')->get = ['_route_' => 'v1/csc/api-client/rotate-secret'];
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions(false);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - rotate_secret() entrypoint with permissions and no client.
 *
 * Steps:
 * 1. Call rotate_secret() with POST.
 * 2. Assert 409 integration_not_provisioned (routed into rotate handler).
 */
test('CSC api_client routing — rotate_secret() entry reaches rotate handler', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $registry->get('request')->get = ['route' => 'publicapi/v1/csc/api_client/rotate_secret'];
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->keycloakFake = new CscKeycloakProvisionerFake();
    $c->rotate_secret();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['integration_not_provisioned']);
});
