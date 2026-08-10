<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for CSC api_client create (POST).
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
 * - Cache already has 3 create timestamps in the last hour.
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 429 rate_limited.
 */
test('CSC api_client create — rate limit returns 429', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $cache = new CscApiCacheStub();
    $cache->store['csc_api_client_create:10'] = [time(), time() - 10, time() - 20];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), null, null, null, $cache);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->keycloakFake = new CscKeycloakProvisionerFake();
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['rate_limited']);
});

/**
 * Prerequisites:
 * - Existing client for company.
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 409 api_client_already_exists.
 */
test('CSC api_client create — duplicate returns 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->byCompany = ['api_client_uuid' => 'existing'];
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->keycloakFake = new CscKeycloakProvisionerFake();
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['api_client_already_exists']);
});

/**
 * Prerequisites:
 * - Keycloak fake reports not configured.
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 503 keycloak_admin_not_configured.
 */
test('CSC api_client create — keycloak unconfigured returns 503', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $fake->configured = false;
    $c->keycloakFake = $fake;
    $c->setPostPayload(['name' => 'My Integration']);
    $c->index();

    expect($c->statusCode)->toBe(503)
        ->and($c->json['error'])->toBe(['keycloak_admin_not_configured']);
});

/**
 * Prerequisites:
 * - Keycloak create returns null.
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 502 keycloak_client_create_failed.
 */
test('CSC api_client create — keycloak create failure returns 502', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $fake->createResult = null;
    $c->keycloakFake = $fake;
    $c->setPostPayload(['name' => 'My Integration']);
    $c->index();

    expect($c->statusCode)->toBe(502)
        ->and($c->json['error'])->toBe(['keycloak_client_create_failed']);
});

/**
 * Prerequisites:
 * - DB insert throws after Keycloak success.
 *
 * Steps:
 * 1. POST create.
 * 2. Assert 500; Keycloak rollback called once.
 */
test('CSC api_client create — DB failure rolls back keycloak client', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $model->insertThrows = true;
    [$registry] = csc_api_client_registry(new CscApiCustomerStub(1, 10, 5), $model);
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $fake = new CscKeycloakProvisionerFake();
    $c->keycloakFake = $fake;
    $c->setPostPayload(['name' => 'My Integration']);
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['api_client_persist_failed'])
        ->and($fake->rollbackCalled)->toBeTrue()
        ->and($fake->rollbackUuid)->toBe('kc-uuid-1');
});

/**
 * Prerequisites:
 * - Empty name; company name available; Keycloak + DB succeed.
 *
 * Steps:
 * 1. POST create with empty body.
 * 2. Assert 200/default name; secret exposed once; oauth_client_id present.
 */
test('CSC api_client create — success uses default name and returns secret once', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new CscApiClientModelStub();
    $company = new CscAccountCompanyStub();
    $company->companyName = 'Acme';
    // Empty email skips provisioning notification (avoids email queue deps in unit tests).
    [$registry] = csc_api_client_registry(
        new CscApiCustomerStub(1, 10, 5, null, ''),
        $model,
        null,
        $company,
    );
    $c = new TestableControllerPublicAPIV1CscApiClient($registry);
    $c->permission = csc_api_client_permissions();
    $c->keycloakFake = new CscKeycloakProvisionerFake();
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['client_secret'])->toBe('secret-once')
        ->and($c->json['data']['oauth_client_id'])->toBe('sim_api_testclient')
        ->and($c->json['data']['name'])->toBe('Simplifi CSC API — Acme')
        ->and($c->json['data']['status'])->toBe('active')
        ->and($model->lastInsert)->not->toBeNull()
        ->and($model->lastInsert)->not->toHaveKey('client_secret');
});
