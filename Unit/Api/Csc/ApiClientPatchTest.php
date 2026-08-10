<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for CSC api_client PATCH.
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
 * - No provisioned client.
 *
 * Steps:
 * 1. PATCH name.
 * 2. Assert 409 integration_not_provisioned.
 */
test('CSC api_client patch — unprovisioned returns 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->setPostPayload(['name' => 'N']);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['integration_not_provisioned']);
});

/**
 * Prerequisites:
 * - Provisioned client; empty patch.
 *
 * Steps:
 * 1. PATCH {}.
 * 2. Assert 400 nothing_to_update.
 */
test('CSC api_client patch — empty update returns 400 nothing_to_update', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'keycloak_client_id'   => 'sim_api_u1',
        'name'                 => 'Old',
        'status'               => 'active',
    ];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['nothing_to_update']);
});

/**
 * Prerequisites:
 * - Invalid status value.
 *
 * Steps:
 * 1. PATCH status=paused.
 * 2. Assert 400 invalid_status.
 */
test('CSC api_client patch — invalid status returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'name'                 => 'Old',
        'status'               => 'active',
    ];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->setPostPayload(['status' => 'paused']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_status']);
});

/**
 * Prerequisites:
 * - Name-only patch.
 *
 * Steps:
 * 1. PATCH name.
 * 2. Assert updateName called; Keycloak setEnabled not called; overview returned.
 */
test('CSC api_client patch — name only updates without keycloak sync', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'name'                 => 'Old',
        'status'               => 'active',
    ];
    $model->publicOverview = ['provisioned' => true, 'name' => 'Old', 'status' => 'active'];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $c->keycloakFake = $fake;
    $c->setPostPayload(['name' => 'New Name']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($model->updateNameCalls)->toBe(1)
        ->and($model->lastUpdatedName)->toBe('New Name')
        ->and($model->updateStatusCalls)->toBe(0)
        ->and($fake->setEnabledCalls)->toBe([])
        ->and($c->json['data']['name'])->toBe('New Name');
});

/**
 * Prerequisites:
 * - Disable status patch.
 *
 * Steps:
 * 1. PATCH status=disabled.
 * 2. Assert DB updated before Keycloak; setClientEnabled(false) called; overview status disabled.
 */
test('CSC api_client patch — disable updates DB and synchronizes keycloak', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'name'                 => 'Old',
        'status'               => 'active',
    ];
    $model->publicOverview = ['provisioned' => true, 'name' => 'Old', 'status' => 'active'];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $c->keycloakFake = $fake;
    $c->setPostPayload(['status' => 'disabled']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($model->updateStatusCalls)->toBe(1)
        ->and($model->lastUpdatedStatus)->toBe('disabled')
        ->and($fake->setEnabledCalls)->toBe([['uuid' => 'kc1', 'enabled' => false]])
        ->and($c->json['data']['status'])->toBe('disabled');
});

/**
 * Prerequisites:
 * - Empty name with valid status still updates status (empty name ignored).
 *
 * Steps:
 * 1. PATCH name='' status=active.
 * 2. Assert only status update; name update skipped.
 */
test('CSC api_client patch — empty name with status updates status only', function () {
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    $model = new CscApiClientModelStub();
    $model->byCompany = [
        'api_client_uuid'      => 'u1',
        'keycloak_client_uuid' => 'kc1',
        'name'                 => 'Old',
        'status'               => 'disabled',
    ];
    $model->publicOverview = ['provisioned' => true, 'name' => 'Old', 'status' => 'disabled'];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $c->keycloakFake = $fake;
    $c->setPostPayload(['name' => '   ', 'status' => 'active']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($model->updateNameCalls)->toBe(0)
        ->and($model->updateStatusCalls)->toBe(1)
        ->and($fake->setEnabledCalls[0]['enabled'])->toBeTrue();
});
