<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'account/companies.php';
require_once __DIR__ . '/_support/AccountCompaniesTestDoubles.php';
require_once __DIR__ . '/_support/AccountCompaniesUnitHelpers.php';
require_once __DIR__ . '/_support/AccountCompaniesModelMocks.php';

uses(AccountCompaniesModelMocks::class);
/**
 * Unit tests: POST validation order and required-field rules for
 * {@see ControllerPublicAPIV1AccountCompanies} (no model persistence).
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
 * - Authenticated customer; deliberately bad payload on several later fields.
 *
 * Steps:
 * 1. POST with blank name plus invalid country and broken fiscal payload.
 * 2. Assert only name_required (first validator wins) and sendResponse once with no model loads.
 */
test('Account companies API — POST validation stops at name_required when multiple fields are wrong', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload([
        'name'                  => '   ',
        'country'               => 'ROU',
        'tin'                   => '',
        'registration_number'   => '',
        'fiscal_address'        => 'not-array',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['name_required'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Valid name but blank country while tin is also empty.
 * 2. Assert country_required (tin is not evaluated yet).
 */
test('Account companies API — POST validation order returns country_required before tin', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(array_merge(
        ac_valid_post(['name' => 'OK', 'country' => '  ']),
        ['tin' => '']
    ));
    $c->index();

    expect($c->json['error'])->toBe(['country_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. POST three-letter country code.
 * 2. Assert country_invalid.
 */
test('Account companies API — POST returns country_invalid for non ISO-2 country', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => 'ROU']));
    $c->index();

    expect($c->json['error'])->toBe(['country_invalid']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Empty tin and registration while earlier fields valid.
 * 2. Assert tin_required (registration not evaluated yet).
 */
test('Account companies API — POST validation order returns tin_required before registration_number', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['tin' => '', 'registration_number' => '']));
    $c->index();

    expect($c->json['error'])->toBe(['tin_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Blank registration_number only.
 * 2. Assert registration_number_required.
 */
test('Account companies API — POST returns registration_number_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['registration_number' => '  ']));
    $c->index();

    expect($c->json['error'])->toBe(['registration_number_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer; headquarters present but fiscal missing path.
 *
 * Steps:
 * 1. Omit fiscal_address shape (headquarters array present).
 * 2. Assert fiscal_address_required fires before headquarters checks would pass alone.
 */
test('Account companies API — POST returns fiscal_address_required before headquarters branch', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload([
        'name'                 => 'X',
        'country'              => 'RO',
        'tin'                  => '1',
        'registration_number'  => 'R',
        'headquarters_address' => [],
    ]);
    $c->index();

    expect($c->json['error'])->toBe(['fiscal_address_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer; fiscal array present, headquarters missing.
 *
 * Steps:
 * 1. POST with fiscal_address array only.
 * 2. Assert headquarters_address_required.
 */
test('Account companies API — POST returns headquarters_address_required after fiscal passes', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload([
        'name'                => 'X',
        'country'             => 'RO',
        'tin'                 => '1',
        'registration_number' => 'R',
        'fiscal_address'      => [],
    ]);
    $c->index();

    expect($c->json['error'])->toBe(['headquarters_address_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Omit name key from otherwise valid payload.
 * 2. Assert name_required.
 */
test('Account companies API — POST missing name key returns name_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['name']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['name_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Null and empty/whitespace name variants.
 * 2. Assert name_required for each.
 */
test('Account companies API — POST null name returns name_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => null]));
    $c->index();

    expect($c->json['error'])->toBe(['name_required']);
});

test('Account companies API — POST empty string name returns name_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => '']));
    $c->index();

    expect($c->json['error'])->toBe(['name_required']);
});

test('Account companies API — POST whitespace-only name returns name_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => "\t  \n"]));
    $c->index();

    expect($c->json['error'])->toBe(['name_required']);
});

/**
 * Prerequisites:
 * - Mocks for successful create path.
 *
 * Steps:
 * 1. Numeric name in JSON decodes as int; controller casts to string.
 * 2. Assert addCompany receives name 12345 and HTTP 200 path.
 */
test('Account companies API — POST numeric name is cast to string and accepted', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['name'] === '12345'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => 12345]));
    $c->index();

    expect($c->statusCode)->toBe(200);
});

/**
 * Prerequisites:
 * - Mocks for successful create path.
 *
 * Steps:
 * 1. Name with surrounding spaces.
 * 2. Assert addCompany receives trimmed name ACME SRL.
 */
test('Account companies API — POST trims name before addCompany', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['name'] === 'ACME SRL'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['name' => '  ACME SRL  ']));
    $c->index();
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Omit country key.
 * 2. Assert country_required.
 */
test('Account companies API — POST missing country key returns country_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['country']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['country_required']);
});

test('Account companies API — POST null country returns country_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => null]));
    $c->index();

    expect($c->json['error'])->toBe(['country_required']);
});

test('Account companies API — POST whitespace-only country returns country_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => '   ']));
    $c->index();

    expect($c->json['error'])->toBe(['country_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Invalid country shapes (not two A–Z letters after normalization).
 * 2. Assert country_invalid for each dataset row.
 */
test('Account companies API — POST invalid country codes return country_invalid', function (string $country, string $code) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => $country]));
    $c->index();

    expect($c->json['error'])->toBe([$code]);
})->with([
    ['R', 'country_invalid'],
    ['ROU', 'country_invalid'],
    ['12', 'country_invalid'],
    ['R1', 'country_invalid'],
    ['R-', 'country_invalid'],
]);

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. fiscal_address null, missing key, and non-array string.
 * 2. Assert fiscal_address_required each time.
 */
test('Account companies API — POST fiscal_address null returns fiscal_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['fiscal_address' => null]));
    $c->index();

    expect($c->json['error'])->toBe(['fiscal_address_required']);
});

test('Account companies API — POST missing fiscal_address key returns fiscal_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['fiscal_address']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['fiscal_address_required']);
});

test('Account companies API — POST string fiscal_address returns fiscal_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['fiscal_address' => 'x']));
    $c->index();

    expect($c->json['error'])->toBe(['fiscal_address_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. headquarters_address null, missing, and string.
 * 2. Assert headquarters_address_required.
 */
test('Account companies API — POST headquarters_address null returns headquarters_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['headquarters_address' => null]));
    $c->index();

    expect($c->json['error'])->toBe(['headquarters_address_required']);
});

test('Account companies API — POST missing headquarters_address key returns headquarters_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['headquarters_address']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['headquarters_address_required']);
});

test('Account companies API — POST string headquarters_address returns headquarters_address_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['headquarters_address' => 'y']));
    $c->index();

    expect($c->json['error'])->toBe(['headquarters_address_required']);
});
