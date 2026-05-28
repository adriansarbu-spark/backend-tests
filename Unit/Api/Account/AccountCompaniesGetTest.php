<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'account/companies.php';
require_once __DIR__ . '/_support/AccountCompaniesTestDoubles.php';
require_once __DIR__ . '/_support/AccountCompaniesUnitHelpers.php';
require_once __DIR__ . '/_support/AccountCompaniesModelMocks.php';

uses(AccountCompaniesModelMocks::class);
/**
 * Unit tests: GET /publicapi/v1/account/companies list behaviour for
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
 * - Mock company model returns a non-empty list; customer id 42.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert model route loaded, getCompaniesForCustomerApi receives int 42, json.data.companies matches, getPost never called.
 */
test('Account companies API — GET loads company model and returns companies payload', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(42));
    $m = $this->acCompanyModel();
    $payload = [
        ['company_uuid' => 'cu-1', 'roles' => [['role_uuid' => 'ru-1']]],
    ];
    $m->expects($this->once())
        ->method('getCompaniesForCustomerApi')
        ->with($this->callback(static fn ($id): bool => $id === 42 && is_int($id)))
        ->willReturn($payload);
    $registry->set('model_account_company', $m);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($load->loadedModels)->toBe(['account/company'])
        ->and($c->json['data'])->toBe(['companies' => $payload])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($c->getPostCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Mock returns empty array.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert companies is an empty list.
 */
test('Account companies API — GET returns empty companies array', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $m->method('getCompaniesForCustomerApi')->willReturn([]);
    $registry->set('model_account_company', $m);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->json['data']['companies'])->toBe([]);
});

/**
 * Prerequisites:
 * - Mock returns multiple rows with nested roles.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert list equality and data subtree has no unexpected customer_role key.
 */
test('Account companies API — GET returns multiple companies with nested roles', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(7));
    $m = $this->acCompanyModel();
    $list = [
        ['company_uuid' => 'a', 'roles' => []],
        ['company_uuid' => 'b', 'roles' => [['role_uuid' => 'x']]],
    ];
    $m->method('getCompaniesForCustomerApi')->willReturn($list);
    $registry->set('model_account_company', $m);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect($c->json['data']['companies'])->toBe($list)
        ->and(array_key_exists('customer_role', $c->json['data']))->toBeFalse();
});

/**
 * Prerequisites:
 * - Successful GET with empty list.
 *
 * Steps:
 * 1. Assert json.data exposes only the companies key (shape contract).
 */
test('Account companies API — GET success data contains only companies key', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $m->method('getCompaniesForCustomerApi')->willReturn([]);
    $registry->set('model_account_company', $m);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->index();

    expect(array_keys($c->json['data']))->toBe(['companies']);
});
