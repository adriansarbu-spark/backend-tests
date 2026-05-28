<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'account/companies.php';
require_once __DIR__ . '/_support/AccountCompaniesTestDoubles.php';
require_once __DIR__ . '/_support/AccountCompaniesUnitHelpers.php';

/**
 * Unit tests: authentication, HTTP method routing, and framework hook ordering for
 * {@see ControllerPublicAPIV1AccountCompanies::index()}.
 */

beforeEach(function () {
    $this->acHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->acSavedRequestMethod = $this->acHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->acHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->acSavedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Unit-only harness (registry + stubs); no database.
 *
 * Steps:
 * 1. Run index() with customer id 0 and GET.
 * 2. Assert checkPlugin ran once, response is 401 unauthenticated, sendResponse once, no models loaded, getPost never used.
 */
test('Account companies API — unauthenticated customer id 0 returns 401 before routing', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(0));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([])
        ->and($c->getPostCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Unit-only harness.
 *
 * Steps:
 * 1. Run index() with negative customer id and POST.
 * 2. Assert 401 and no model loads or getPost.
 */
test('Account companies API — unauthenticated negative customer id returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(-1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($c->getPostCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Unit-only harness.
 *
 * Steps:
 * 1. Customer getId() returns null (cast to int becomes 0).
 * 2. Assert 401 unauthenticated.
 */
test('Account companies API — null customer id is treated as unauthenticated', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ac_registry_with_customer(new AccountCompaniesNullIdCustomerStub());
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated']);
});

/**
 * Prerequisites:
 * - Authenticated stub customer.
 *
 * Steps:
 * 1. Set unsupported HTTP method.
 * 2. Assert 405, allowedHeaders GET+POST, sendResponse once, no models, getPost not called.
 */
test('Account companies API — unsupported HTTP method returns 405', function (string $method) {
    $_SERVER['REQUEST_METHOD'] = $method;
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['GET', 'POST'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([])
        ->and($c->getPostCalls)->toBe(0);
})->with(['PUT', 'PATCH', 'DELETE', 'OPTIONS', '']);

/**
 * Prerequisites:
 * - Authenticated stub customer.
 *
 * Steps:
 * 1. Unset REQUEST_METHOD so controller sees empty method.
 * 2. Assert 405 (same branch as unknown methods).
 */
test('Account companies API — missing REQUEST_METHOD is treated as unsupported', function () {
    unset($_SERVER['REQUEST_METHOD']);
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->statusCode)->toBe(405);
});

/**
 * Prerequisites:
 * - Authenticated customer; valid POST models registered but payload invalid at name.
 *
 * Steps:
 * 1. POST with empty name.
 * 2. Assert no load->model routes were recorded (validation stops before persistence layer).
 */
test('Account companies API — validation failure does not load company or customer_role models', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => '']));
    $c->index();

    expect($load->loadedModels)->toBe([]);
});
