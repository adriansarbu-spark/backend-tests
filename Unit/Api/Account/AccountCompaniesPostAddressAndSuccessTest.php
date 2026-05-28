<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'account/companies.php';
require_once __DIR__ . '/_support/AccountCompaniesTestDoubles.php';
require_once __DIR__ . '/_support/AccountCompaniesUnitHelpers.php';
require_once __DIR__ . '/_support/AccountCompaniesModelMocks.php';

uses(AccountCompaniesModelMocks::class);
/**
 * Unit tests: POST address mapping, successful company creation, and permissive edge cases for
 * {@see ControllerPublicAPIV1AccountCompanies}.
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
 * - Successful create mocks.
 *
 * Steps:
 * 1. Rich fiscal + HQ address payloads.
 * 2. Assert first address_co is dom_fiscal with mapped fields; second is sed_social.
 */
test('Account companies API — POST maps structured addresses to address_co rows', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(function (array $d): bool {
        $co = $d['address_co'];
        if (count($co) !== 2) {
            return false;
        }
        $f = $co[0];
        $h = $co[1];
        if ($f['denumire_Strada'] !== 'Main Street' || $f['numar_Strada'] !== '10A' || $f['other_details'] !== 'Building B') {
            return false;
        }
        if ($f['JUD_COD'] !== 12 || $f['LOC_COD'] !== 345) {
            return false;
        }
        if ($f['address_co_type'] !== 'dom_fiscal' || $f['friendly_name'] !== 'dom_fiscal') {
            return false;
        }
        if ($h['address_co_type'] !== 'sed_social' || $h['friendly_name'] !== 'sed_social') {
            return false;
        }
        if ($h['denumire_Strada'] !== 'HQ' || $h['numar_Strada'] !== '2') {
            return false;
        }

        return true;
    }))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post([
        'fiscal_address' => [
            'street'    => ' Main Street ',
            'street_no' => ' 10A ',
            'details'   => ' Building B ',
            'jud_cod'   => '12',
            'loc_cod'   => '345',
        ],
        'headquarters_address' => [
            'street'    => 'HQ',
            'street_no' => '2',
            'details'   => '',
            'jud_cod'   => 1,
            'loc_cod'   => 1,
        ],
    ]));
    $c->index();
});

/**
 * Prerequisites:
 * - Successful create mocks; fiscal row varies per dataset.
 *
 * Steps:
 * 1. POST with fiscal_address partial keys.
 * 2. Assert first address_co row matches expected defaults / casts.
 */
test('Account companies API — POST fiscal address defaults and jud loc casting', function (array $addrIn, array $expectSubset) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(function (array $d) use ($expectSubset): bool {
        $first = $d['address_co'][0];
        foreach ($expectSubset as $k => $v) {
            if (($first[$k] ?? null) !== $v) {
                return false;
            }
        }
        if (($first['address_co_type'] ?? null) !== 'dom_fiscal') {
            return false;
        }

        return true;
    }))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post([
        'fiscal_address'       => $addrIn,
        'headquarters_address' => ['street' => 'H', 'street_no' => '1'],
    ]));
    $c->index();
})->with([
    [[], ['denumire_Strada' => '-', 'numar_Strada' => '-', 'other_details' => '', 'JUD_COD' => 0, 'LOC_COD' => 0]],
    [['street' => ''], ['denumire_Strada' => '-']],
    [['street' => " \t "], ['denumire_Strada' => '-']],
    [['street' => 'X'], ['numar_Strada' => '-']],
    [['street' => 'X', 'street_no' => ''], ['numar_Strada' => '-']],
    [['street' => 'X', 'street_no' => '1'], ['other_details' => '']],
    [['street' => 'X', 'street_no' => '1', 'details' => ' '], ['other_details' => '']],
    [['street' => 'X', 'street_no' => '1'], ['JUD_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 0], ['JUD_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => -1], ['JUD_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 'abc'], ['JUD_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => '12abc'], ['JUD_COD' => 12]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 5], ['LOC_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 5, 'loc_cod' => 0], ['LOC_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 5, 'loc_cod' => -3], ['LOC_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 5, 'loc_cod' => 'abc'], ['LOC_COD' => 0]],
    [['street' => 'X', 'street_no' => '1', 'jud_cod' => 5, 'loc_cod' => '345abc'], ['LOC_COD' => 345]],
]);

/**
 * Prerequisites:
 * - Customer 99; mocks return ids and UUIDs.
 *
 * Steps:
 * 1. Valid RO payload.
 * 2. Assert load order, addCompany payload shape, json.data keys only company_uuid and role_uuid.
 */
test('Account companies API — POST success loads models and returns UUID payload for RO company', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry, $load] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(99));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->expects($this->once())->method('addCompany')->with($this->callback(function (array $d): bool {
        if ($d['customer_id'] !== 99 || $d['country'] !== 'RO' || $d['cui'] !== '123456') {
            return false;
        }
        if (count($d['address_co']) !== 2) {
            return false;
        }
        if ($d['address_co'][0]['address_co_type'] !== 'dom_fiscal') {
            return false;
        }
        if ($d['address_co'][1]['address_co_type'] !== 'sed_social') {
            return false;
        }

        return true;
    }))->willReturn(500);
    $r->expects($this->once())
        ->method('getAdminRoleUuidsForCompany')
        ->with(99, 500)
        ->willReturn(['company_uuid' => 'uuid-c', 'role_uuid' => 'uuid-r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post());
    $c->index();

    expect($load->loadedModels)->toBe(['account/company', 'account/customer_role'])
        ->and($c->json['data'])->toBe(['company_uuid' => 'uuid-c', 'role_uuid' => 'uuid-r'])
        ->and(array_keys($c->json['data']))->toBe(['company_uuid', 'role_uuid'])
        ->and($c->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Non-RO DE company.
 *
 * Steps:
 * 1. Assert response echoes model UUIDs.
 */
test('Account companies API — POST success for non-RO company returns UUIDs', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(2));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->method('addCompany')->willReturn(10);
    $r->method('getAdminRoleUuidsForCompany')->with(2, 10)->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post(['country' => 'DE', 'tin' => 'TIN-1']));
    $c->index();

    expect($c->json['data']['company_uuid'])->toBe('c');
});

/**
 * Prerequisites:
 * - addCompany returns string numeric id.
 *
 * Steps:
 * 1. Assert role lookup receives int company id.
 * 2. Assert UUID strings pass through unchanged.
 */
test('Account companies API — POST casts string company id to int for role UUID lookup', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(3));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->method('addCompany')->willReturn('123');
    $r->expects($this->once())->method('getAdminRoleUuidsForCompany')->with(3, 123)->willReturn([
        'company_uuid' => 'exact-uuid',
        'role_uuid'    => 'role-uuid',
    ]);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post());
    $c->index();

    expect($c->json['data']['company_uuid'])->toBe('exact-uuid')
        ->and($c->json['data']['role_uuid'])->toBe('role-uuid');
});

/**
 * Prerequisites:
 * - Role lookup returns null after successful insert.
 *
 * Steps:
 * 1. Assert empty-string UUID placeholders and still HTTP 200 with no error array entries.
 */
test('Account companies API — POST success with null admin role UUIDs returns empty strings', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->method('addCompany')->willReturn(7);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(null);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post());
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['company_uuid' => '', 'role_uuid' => ''])
        ->and($c->json['error'])->toBe([]);
});

/**
 * Prerequisites:
 * - Edge payload: long name, XX country, empty address arrays, letters-only non-RO TIN, no VAT.
 *
 * Steps:
 * 1. Assert controller accepts payload and passes normalized row to addCompany.
 */
test('Account companies API — POST accepts permissive edge payload without extra validation', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $long = str_repeat('N', 5000);
    $m->expects($this->once())->method('addCompany')->with($this->callback(function (array $d) use ($long): bool {
        return $d['country'] === 'XX'
            && $d['name'] === $long
            && $d['cui'] === 'LETTERSONLY'
            && $d['vat_number'] === ''
            && $d['address_co'][0]['JUD_COD'] === 0
            && $d['address_co'][0]['LOC_COD'] === 0
            && $d['address_co'][0]['denumire_Strada'] === '-';
    }))->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post([
        'name'                 => $long,
        'country'              => 'XX',
        'tin'                  => 'LETTERSONLY',
        'fiscal_address'       => [],
        'headquarters_address' => [],
    ]));
    $c->index();
});

/**
 * Prerequisites:
 * - Valid POST with mocks.
 *
 * Steps:
 * 1. Assert getPost() is invoked exactly once for POST create path.
 */
test('Account companies API — POST calls getPost exactly once on successful create', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ac_registry_with_customer(new AccountCompaniesTestCustomerStub(1));
    $m = $this->acCompanyModel();
    $r = $this->acCustomerRoleModel();
    $m->method('addCompany')->willReturn(1);
    $r->method('getAdminRoleUuidsForCompany')->willReturn(['company_uuid' => 'c', 'role_uuid' => 'r']);
    $registry->set('model_account_company', $m);
    $registry->set('model_account_customer_role', $r);

    $c = new TestableControllerPublicAPIV1AccountCompanies($registry);
    $c->setPostPayload(ac_valid_post());
    $c->index();

    expect($c->getPostCalls)->toBe(1);
});
