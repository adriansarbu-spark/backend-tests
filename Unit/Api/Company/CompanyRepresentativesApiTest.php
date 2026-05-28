<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CompanyApiTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1CompanyRepresentatives} (auth, membership, lists, seals, revoke).
 */

beforeEach(function () {
    $this->crHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->crSavedRequestMethod = $this->crHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $this->crHadRequestUri = array_key_exists('REQUEST_URI', $_SERVER);
    $this->crSavedRequestUri = $this->crHadRequestUri ? $_SERVER['REQUEST_URI'] : null;
});

afterEach(function () {
    if (! $this->crHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->crSavedRequestMethod;
    }
    if (! $this->crHadRequestUri) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $this->crSavedRequestUri;
    }
});

/**
 * Prerequisites:
 * - Unit harness; no database.
 *
 * Steps:
 * 1. GET index() with customer id 0.
 * 2. Assert 401 unauthenticated before membership or model branching.
 */
test('Company representatives API — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(0, 10, 1), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with company id 0 (invalid company context).
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 company_context_required.
 */
test('Company representatives API — missing company returns 400 company_context_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 0, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - roleBelongsToCompany returns false.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 403 not_a_company_member after representative model load.
 */
test('Company representatives API — role not in company returns 403 not_a_company_member', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->roleBelongs = false;
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['not_a_company_member'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Valid member; GET eligibility action.
 *
 * Steps:
 * 1. Set representative_action=eligibility.
 * 2. Assert JSON data equals stub eligibility payload; only company/representative loaded.
 */
test('Company representatives API — GET eligibility returns model payload', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->eligibilityData = ['can_request_seal' => true, 'notes' => 'unit'];
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = ['representative_action' => 'eligibility'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['can_request_seal' => true, 'notes' => 'unit'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Valid member; GET default list (no action).
 *
 * Steps:
 * 1. Pass status and pagination query params.
 * 2. Assert listRepresentativesPaged receives admin_view 0 for non-admin and forwards status.
 */
test('Company representatives API — GET list forwards filters and admin_view for non-admin', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = false;
    $rep->listRepresentativesResult = [
        'items'    => [['representative_uuid' => 'r1']],
        'page'     => 1,
        'per_page' => 20,
        'total'    => 1,
    ];
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(9, 88, 7), $rep);
    $registry->get('request')->get = [
        'status'   => 'active',
        'page'     => '1',
        'per_page' => '50',
        'search'   => ' ann ',
        'sort'     => 'lastname',
        'order'    => 'asc',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($rep->lastListRepresentativesArgs['company_id'])->toBe(88)
        ->and($rep->lastListRepresentativesArgs['admin_view'])->toBe(0)
        ->and($rep->lastListRepresentativesArgs['status'])->toBe('active')
        ->and($rep->lastListRepresentativesArgs['search'])->toBe('ann')
        ->and($c->json['data']['representatives'])->toHaveCount(1)
        ->and($c->json['pagination']['total'])->toBe(1);
});

/**
 * Prerequisites:
 * - Company admin; GET seals list.
 *
 * Steps:
 * 1. Set action=seals via query.
 * 2. Assert listSealsPaged receives admin_view 1.
 */
test('Company representatives API — GET seals list passes admin_view for company admin', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $seal = new CompanySealModelStub();
    $seal->listSealsResult = [
        'items'    => [['seal_uuid' => 's1']],
        'page'     => 1,
        'per_page' => 20,
        'total'    => 4,
    ];
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals', 'status' => 'active'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($load->loadedModels)->toBe(['company/representative', 'company/seal'])
        ->and($seal->lastListSealsArgs['admin_view'])->toBe(1)
        ->and($seal->lastListSealsArgs['status'])->toBe('active')
        ->and($c->json['data']['seals'])->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Active representative record present; POST /seals without legal acceptance.
 *
 * Steps:
 * 1. action=seals, POST empty acceptance.
 * 2. Assert 400 legal_document_acceptance_incomplete.
 */
test('Company representatives API — POST seals requires terms acceptance when not using versioned fields', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['legal_document_acceptance_incomplete']);
});

/**
 * Prerequisites:
 * - Partial accepted_legal_document_uuids (incomplete set).
 *
 * Steps:
 * 1. POST only one configured document UUID.
 * 2. Assert legal_document_acceptance_incomplete.
 */
test('Company representatives API — POST seals rejects incomplete versioned legal bundle', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([
        'accepted_legal_document_uuids' => [company_repr_seal_legal_document_uuids()[0]],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['legal_document_acceptance_incomplete']);
});

/**
 * Prerequisites:
 * - Submitted UUID set does not include every configured seal document.
 *
 * Steps:
 * 1. POST two configured UUIDs plus one unknown UUID (replacing the third required doc).
 * 2. Assert legal_document_acceptance_incomplete.
 */
test('Company representatives API — POST seals rejects version mismatch against configured documents', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $uuids = company_repr_seal_legal_document_uuids();
    $c->setPostPayload([
        'accepted_legal_document_uuids' => [
            $uuids[0],
            $uuids[1],
            '99999999-9999-4999-8999-999999999999',
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['legal_document_acceptance_incomplete']);
});

/**
 * Prerequisites:
 * - All configured legal document UUIDs accepted; no TOTP on customer; seal request succeeds in model.
 *
 * Steps:
 * 1. POST accepted_legal_document_uuids for every configured document.
 * 2. Assert 200 and data.seal from stub.
 */
test('Company representatives API — POST seals succeeds after versioned legal acceptance', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    $seal->requestSealResult = ['seal' => ['seal_uuid' => 'new-seal']];
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([
        'accepted_legal_document_uuids' => company_repr_seal_legal_document_uuids(),
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['seal']['seal_uuid'])->toBe('new-seal');
});

/**
 * Prerequisites:
 * - Model returns active_representative_seal_exists.
 *
 * Steps:
 * 1. POST seals with valid legal document acceptance.
 * 2. Assert HTTP 409.
 */
test('Company representatives API — POST seals maps active representative seal exists to 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    $seal->requestSealResult = ['error' => 'active_representative_seal_exists', 'seal' => ['seal_uuid' => 'existing']];
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([
        'accepted_legal_document_uuids' => company_repr_seal_legal_document_uuids(),
    ]);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['active_representative_seal_exists'])
        ->and($c->json['data']['seal']['seal_uuid'])->toBe('existing');
});

/**
 * Prerequisites:
 * - No active representative row.
 *
 * Steps:
 * 1. POST seals.
 * 2. Assert 403 active_representative_required before legal validation runs.
 */
test('Company representatives API — POST seals requires active representative', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = null;
    $seal = new CompanySealModelStub();
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = ['action' => 'seals'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload(['accept_terms' => true]);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['active_representative_required'])
        ->and($load->loadedModels)->toBe(['company/representative', 'company/seal']);
});

/**
 * Prerequisites:
 * - GET seal_request loads upload model and returns totp_required from customer secret presence.
 *
 * Steps:
 * 1. action=seal_request (via representative_action).
 * 2. Assert documents array shape and totp_required true when secret non-empty.
 */
test('Company representatives API — GET seals request exposes totp_required and document rows', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $upload = new ToolUploadModelStub();
    [$registry, $load] = company_repr_registry(
        new CompanyApiCustomerStub(1, 10, 5, 'SECRETNOTEMPTY'),
        $rep,
        null,
        $upload,
    );
    $registry->get('request')->get = ['representative_action' => 'seal_request'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['totp_required'])->toBeTrue()
        ->and($c->json['data']['documents'])->toHaveCount(3)
        ->and($c->json['data']['documents'][0]['uuid'] ?? null)->toBe(company_repr_seal_legal_document_uuids()[0])
        ->and($load->loadedModels)->toBe(['company/representative', 'legal/document', 'tool/upload']);
});

/**
 * Prerequisites:
 * - GET seal detail; model returns null (hidden or missing).
 *
 * Steps:
 * 1. route includes seals/{uuid} without download/revoke tail.
 * 2. Assert 404 seal_not_found.
 */
test('Company representatives API — GET seal detail returns 404 when model finds nothing', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $seal = new CompanySealModelStub();
    $seal->getSealDetailResult = null;
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = [
        'route' => 'publicapi/v1/company/representatives/seals/deadbeef-dead-beef-dead-beefdeadbeef',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['seal_not_found'])
        ->and($load->loadedModels)->toContain('company/seal');
});

/**
 * Prerequisites:
 * - Testable overrides downloadSealCertificate to avoid exit().
 *
 * Steps:
 * 1. GET .../seals/{uuid}/download via route.
 * 2. Assert harness invoked once with expected seal uuid.
 */
test('Company representatives API — GET seal download delegates to download handler', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $seal = new CompanySealModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = [
        'route' => 'publicapi/v1/company/representatives/seals/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/download',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->downloadSealCertificateCalls)->toBe(1)
        ->and($c->downloadSealCertificateArgs[0][5])->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and($c->json['data']['stub_download'])->toBeTrue();
});

/**
 * Prerequisites:
 * - Non-admin member attempts POST representative revoke.
 *
 * Steps:
 * 1. representative_action=representative_revoke with uuid.
 * 2. Assert 403 admin_role_required.
 */
test('Company representatives API — POST representative revoke requires admin', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = false;
    [$registry, $load] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = [
        'representative_uuid'     => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'representative_action' => 'representative_revoke',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Admin POST revoke without uuid.
 *
 * Steps:
 * 1. representative_action=representative_revoke without representative_uuid.
 * 2. Assert 400 representative_uuid_required.
 */
test('Company representatives API — POST representative revoke requires uuid', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = ['representative_action' => 'representative_revoke'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['representative_uuid_required']);
});

/**
 * Prerequisites:
 * - Admin; stub returns seal_revocation_failed with diagnostics.
 *
 * Steps:
 * 1. POST revoke with uuid.
 * 2. Assert 502 and data.error_seal_uuid.
 */
test('Company representatives API — POST representative revoke maps seal_revocation_failed to 502', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->revokeRepresentativeResult = [
        'error'           => 'seal_revocation_failed',
        'error_seal_uuid' => 'seal-uuid-1',
        'seals_revoked'   => 0,
    ];
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = [
        'representative_uuid'     => 'rep-uuid-1',
        'representative_action' => 'representative_revoke',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload(['reason' => 'policy']);
    $c->index();

    expect($c->statusCode)->toBe(502)
        ->and($c->json['data']['error_seal_uuid'])->toBe('seal-uuid-1');
});

/**
 * Prerequisites:
 * - Active representative; POST seal revoke with invalid revocation_reason_id.
 *
 * Steps:
 * 1. seal revoke action, body revocation_reason_id 9999.
 * 2. Assert 400 invalid_revocation_reason before model revokeSeal.
 */
test('Company representatives API — POST seal revoke rejects invalid revocation reason id', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->activeRepresentative = ['company_representative_id' => 1];
    $seal = new CompanySealModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $seal);
    $registry->get('request')->get = [
        'route'    => 'publicapi/v1/company/representatives/seals/my-seal-uuid/revoke',
        'seal_uuid' => 'my-seal-uuid',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->setPostPayload(['revocation_reason_id' => 9999]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_revocation_reason']);
});

/**
 * Prerequisites:
 * - Unsupported HTTP verb on base list route.
 *
 * Steps:
 * 1. PUT index().
 * 2. Assert 405 method_not_allowed.
 */
test('Company representatives API — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    $rep = new CompanyRepresentativeModelStub();
    [$registry] = company_repr_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentatives($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS']);
});
