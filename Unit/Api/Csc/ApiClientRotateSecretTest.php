<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for CSC api_client rotate_secret.
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

function csc_rotate_controller(
    CscApiClientModelStub $model,
    ?CscKeycloakProvisionerFake $fake = null,
    ?CscApiCacheStub $cache = null,
    ?string $totpOverride = '',
    string $email = '',
): TestableControllerPublicAPIV1CscApiClient {
    [$registry] = csc_api_client_registry(
        new CscApiCustomerStub(1, 10, 5, 'UNITTOTPSECRETUNITTOTPSEC', $email),
        $model,
        null,
        null,
        $cache,
    );
    $registry->get('request')->get = ['route' => 'publicapi/v1/csc/api_client/rotate_secret'];
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->keycloakFake = $fake ?? new CscKeycloakProvisionerFake();
    $c->totpOverride = $totpOverride;

    return $c;
}

/**
 * Prerequisites:
 * - Rotate route; GET method.
 *
 * Steps:
 * 1. Call index() as GET on rotate route.
 * 2. Assert 405.
 */
test('CSC api_client rotate — non-POST returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'active',
        'name'                 => 'N',
    ];
    $c = csc_rotate_controller($model);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Rate limit cache full for rotate.
 *
 * Steps:
 * 1. POST rotate.
 * 2. Assert 429.
 */
test('CSC api_client rotate — rate limit returns 429', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $cache = new CscApiCacheStub();
    $cache->store['csc_api_client_rotate:10'] = [time(), time() - 1, time() - 2, time() - 3, time() - 4];
    $model = new CscApiClientModelStub();
    $c = csc_rotate_controller($model, null, $cache);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['rate_limited']);
});

/**
 * Prerequisites:
 * - Client status disabled.
 *
 * Steps:
 * 1. POST rotate.
 * 2. Assert 409 api_client_disabled.
 */
test('CSC api_client rotate — disabled client returns 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'disabled',
        'name'                 => 'N',
    ];
    $c = csc_rotate_controller($model);
    $c->setPostPayload(['totp_code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['api_client_disabled']);
});

/**
 * Prerequisites:
 * - TOTP override returns invalid_totp.
 *
 * Steps:
 * 1. POST rotate.
 * 2. Assert 422 invalid_totp.
 */
test('CSC api_client rotate — invalid totp returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'active',
        'name'                 => 'N',
    ];
    $c = csc_rotate_controller($model, null, null, 'invalid_totp');
    $c->setPostPayload(['totp_code' => '000000']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['invalid_totp']);
});

/**
 * Prerequisites:
 * - Keycloak not credentials-capable.
 *
 * Steps:
 * 1. POST rotate with TOTP pass.
 * 2. Assert 502 keycloak_client_credentials_not_capable.
 */
test('CSC api_client rotate — incompatible client returns 502', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'active',
        'name'                 => 'N',
    ];
    $fake = new CscKeycloakProvisionerFake();
    $fake->credentialsCapable = false;
    $c = csc_rotate_controller($model, $fake, null, '');
    $c->setPostPayload(['totp' => '123456']); // totp fallback key
    $c->index();

    expect($c->statusCode)->toBe(502)
        ->and($c->json['error'])->toBe(['keycloak_client_credentials_not_capable']);
});

/**
 * Prerequisites:
 * - Empty rotated secret.
 *
 * Steps:
 * 1. POST rotate.
 * 2. Assert 502 keycloak_secret_rotate_failed.
 */
test('CSC api_client rotate — empty secret returns 502', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'active',
        'name'                 => 'N',
    ];
    $fake = new CscKeycloakProvisionerFake();
    $fake->rotateSecretResult = '';
    $c = csc_rotate_controller($model, $fake, null, '');
    $c->setPostPayload(['totp_code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(502)
        ->and($c->json['error'])->toBe(['keycloak_secret_rotate_failed']);
});

/**
 * Prerequisites:
 * - Happy path with TOTP overridden to pass.
 *
 * Steps:
 * 1. POST rotate.
 * 2. Assert secret returned once with oauth_client_id.
 */
test('CSC api_client rotate — success returns new secret once', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'status'               => 'active',
        'name'                 => 'N',
    ];
    $fake = new CscKeycloakProvisionerFake();
    $fake->rotateSecretResult = 'brand-new-secret';
    $c = csc_rotate_controller($model, $fake, null, '');
    $c->setPostPayload(['totp_code' => '123456']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['client_secret'])->toBe('brand-new-secret')
        ->and($c->json['data']['api_client_uuid'])->toBe('u1')
        ->and($c->json['data']['oauth_client_id'])->toBe('sim_api_u1');
});
