<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../tests_config.php';
require_once __DIR__ . '/../_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollments::index() routing:
 * bearer auth, client resolution, metering gate and HTTP method dispatch.
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
 * - Integrator auth double reports the bearer token as invalid.
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 401 integrator_unauthenticated returned before any model load.
 */
test('CSC enrollments — unauthenticated integrator returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, $load] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $fake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $fake->valid = false;
    $c->authFake = $fake;
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['integrator_unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Auth succeeds but the resolved client row carries csc_api_client_id 0.
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 500 internal_error (client id guard trips before the metering gate).
 */
test('CSC enrollments — invalid client id returns 500 internal_error', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $fake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $fake->clientRow = ['csc_api_client_id' => 0];
    $c->authFake = $fake;
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['internal_error']);
});

/**
 * Prerequisites:
 * - Authenticated client; company access state is "none" (never requested).
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 403 access_not_enabled from the metering gate.
 */
test('CSC enrollments — company access state none returns 403 access_not_enabled', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, , $access] = csc_integrator_registry();
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_NONE,
        'metering_mode' => ModelCscCompanyAccess::METER_UNMETERED,
    ];
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_not_enabled']);
});

/**
 * Prerequisites:
 * - Authenticated client; company access state is "suspended".
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 403 access_not_enabled — suspension cuts off live integrator traffic immediately.
 */
test('CSC enrollments — suspended company access returns 403 access_not_enabled', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, , $access] = csc_integrator_registry();
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_SUSPENDED,
        'metering_mode' => ModelCscCompanyAccess::METER_UNMETERED,
    ];
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_not_enabled']);
});

/**
 * Prerequisites:
 * - Authenticated client with enabled access; unsupported HTTP method.
 *
 * Steps:
 * 1. DELETE index().
 * 2. Assert 405 method_not_allowed and Allow headers advertise GET/POST.
 */
test('CSC enrollments — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toContain('GET')
        ->and($c->allowedHeaders)->toContain('POST');
});

/**
 * Prerequisites:
 * - REQUEST_METHOD superglobal entirely unset (edge case, e.g. CLI/test harness).
 *
 * Steps:
 * 1. Call index() with no $_SERVER['REQUEST_METHOD'].
 * 2. Assert the missing method defaults to GET routing (400 enrollment_uuid_required,
 *    not 405), proving the `?? 'GET'` fallback in index().
 */
test('CSC enrollments — missing request method defaults to GET routing', function () {
    unset($_SERVER['REQUEST_METHOD']);
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollments($registry);
    $c->authFake = csc_install_integrator_auth(new CscIntegratorAuthFake());
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['enrollment_uuid_required']);
});
