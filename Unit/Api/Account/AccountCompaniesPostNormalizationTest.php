<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'account/companies.php';
require_once __DIR__ . '/_support/AccountCompaniesTestDoubles.php';
require_once __DIR__ . '/_support/AccountCompaniesUnitHelpers.php';
require_once __DIR__ . '/_support/AccountCompaniesModelMocks.php';

uses(AccountCompaniesModelMocks::class);
/**
 * Unit tests: POST field normalization for {@see ControllerPublicAPIV1AccountCompanies}.
 * Each `test()` below includes Prerequisites and Steps per project testing guidelines.
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
 * - Successful POST path with mocks.
 *
 * Steps:
 * 1. Lowercase country with padding.
 * 2. Assert addCompany receives RO.
 */
test('Account companies API — POST normalizes lowercase country to ISO-2 uppercase', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['country'] === 'RO'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(null);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => '  ro  ']));
    $c->index();
});

/**
 * Prerequisites:
 * - Successful POST path with mocks.
 *
 * Steps:
 * 1. Mixed-case country rO.
 * 2. Assert addCompany receives RO.
 */
test('Account companies API — POST normalizes mixed-case country to uppercase', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['country'] === 'RO'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'x', 'role_uuid' => 'y']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => 'rO']));
    $c->index();
});

/**
 * Prerequisites:
 * - RO country; successful create mocks.
 *
 * Steps:
 * 1. Various raw TIN strings.
 * 2. Assert cui passed to addCompany matches digits-only normalization.
 */
test('Account companies API — POST RO TIN is normalized to digits only', function (string $raw, string $expectedCui) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $wantCui = $expectedCui;
    $m->expects($this->once())->method('addCompany')->with($this->callback(
        fn (array $d): bool => $d['cui'] === $wantCui
    ))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['tin' => $raw]));
    $c->index();
})->with([
    [' 123 456 ', '123456'],
    ['RO 123 456', '123456'],
    ['abc123def456', '123456'],
    ['12-34.56', '123456'],
]);

/**
 * Prerequisites:
 * - RO country; invalid or empty TIN after normalization.
 *
 * Steps:
 * 1. Each tin value yields tin_required.
 */
test('Account companies API — POST RO empty or non-digit TIN returns tin_required', function (?string $tin) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $payload = ac_valid_post();
    if ($tin === null) {
        $payload['tin'] = null;
    } else {
        $payload['tin'] = $tin;
    }
    $c->setPostPayload($payload);
    $c->index();

    expect($c->json['error'])->toBe(['tin_required']);
})->with([
    [null],
    [''],
    ['   '],
    ['RO'],
    ['abc'],
]);

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Omit tin key entirely.
 * 2. Assert tin_required.
 */
test('Account companies API — POST missing tin key returns tin_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['tin']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['tin_required']);
});

/**
 * Prerequisites:
 * - Non-RO country; successful create mocks.
 *
 * Steps:
 * 1. Assert cui is whitespace-stripped only (no digit stripping except internal spaces).
 */
test('Account companies API — POST non-RO TIN strips internal whitespace only', function (string $country, string $raw, string $expectedCui) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $wantCui = $expectedCui;
    $m->expects($this->once())->method('addCompany')->with($this->callback(
        fn (array $d): bool => $d['cui'] === $wantCui
    ))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => $country, 'tin' => $raw]));
    $c->index();
})->with([
    ['DE', ' DE 123 456 ', 'DE123456'],
    ['BG', " BG\t123\n456 ", 'BG123456'],
    ['FR', 'abc-123', 'abc-123'],
    ['FR', 'abc 123', 'abc123'],
]);

/**
 * Prerequisites:
 * - FR country; letters-only TIN allowed for non-RO.
 *
 * Steps:
 * 1. Assert cui stored as letters.
 */
test('Account companies API — POST non-RO letters-only TIN is accepted', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['cui'] === 'ABCDEF'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => 'FR', 'tin' => 'ABCDEF']));
    $c->index();
});

/**
 * Prerequisites:
 * - DE country.
 *
 * Steps:
 * 1. Whitespace-only tin after strip.
 * 2. Assert tin_required.
 */
test('Account companies API — POST non-RO whitespace-only TIN returns tin_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => 'DE', 'tin' => " \n\t "]));
    $c->index();

    expect($c->json['error'])->toBe(['tin_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Missing registration_number key.
 * 2. Assert registration_number_required.
 */
test('Account companies API — POST missing registration_number key returns registration_number_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $p = ac_valid_post();
    unset($p['registration_number']);
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($p);
    $c->index();

    expect($c->json['error'])->toBe(['registration_number_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Null registration_number.
 * 2. Assert registration_number_required.
 */
test('Account companies API — POST null registration_number returns registration_number_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['registration_number' => null]));
    $c->index();

    expect($c->json['error'])->toBe(['registration_number_required']);
});

/**
 * Prerequisites:
 * - Authenticated customer.
 *
 * Steps:
 * 1. Whitespace-only registration_number.
 * 2. Assert registration_number_required.
 */
test('Account companies API — POST whitespace registration_number returns registration_number_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['registration_number' => '  ']));
    $c->index();

    expect($c->json['error'])->toBe(['registration_number_required']);
});

/**
 * Prerequisites:
 * - Successful create mocks.
 *
 * Steps:
 * 1. Trimmed registration and numeric registration.
 * 2. Assert recom field passed to addCompany.
 */
test('Account companies API — POST trims registration_number and accepts numeric value', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['recom'] === 'J40/123/2024'))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['registration_number' => ' J40/123/2024 ']));
    $c->index();

    [$registry2] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m2 = $this->acCompanyModel();
    $r2 = $this->acCustomerRoleModel();
    $m2->expects($this->once())->method('addCompany')->with($this->callback(fn (array $d): bool => $d['recom'] === '999'))->willReturn(1);
    $r2->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry2->set('model_account_company', $m2);
    $registry2->set('model_account_customer_role', $r2);
    $c2 = new TestableControllerPublicAPIV1AccountCompanies($registry2);
    $c2->setPostPayload(ac_valid_post(['registration_number' => 999]));
    $c2->index();
});

/**
 * Prerequisites:
 * - Successful create mocks.
 *
 * Steps:
 * 1. vat_number unset, null, empty, whitespace, or trimmed value.
 * 2. Assert vat_number field on addCompany payload.
 */
test('Account companies API — POST vat_number optional handling matches contract', function ($vat, string $expected) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $wantVat = $expected;
    $m->expects($this->once())->method('addCompany')->with($this->callback(
        fn (array $d): bool => $d['vat_number'] === $wantVat
    ))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $payload = ac_valid_post();
    if ($vat === '__unset__') {
        unset($payload['vat_number']);
    } else {
        $payload['vat_number'] = $vat;
    }
    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload($payload);
    $c->index();
})->with([
    ['__unset__', ''],
    [null, ''],
    ['', ''],
    ["  \t ", ''],
    [' RO123456 ', 'RO123456'],
]);
