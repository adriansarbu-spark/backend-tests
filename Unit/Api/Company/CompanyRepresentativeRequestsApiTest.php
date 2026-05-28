<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CompanyApiTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1CompanyRepresentativeRequests}.
 */

beforeEach(function () {
    $this->cqHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->cqSavedRequestMethod = $this->cqHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->cqHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->cqSavedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Unit harness; no database.
 *
 * Steps:
 * 1. GET with customer id 0.
 * 2. Assert 401 unauthenticated.
 */
test('Company representative-requests API — unauthenticated returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    [$registry, $load] = company_req_registry(new CompanyApiCustomerStub(0, 10, 1), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - customer_role_id 0 triggers company context error.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 company_context_required.
 */
test('Company representative-requests API — missing role id returns 400 company_context_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    [$registry, $load] = company_req_registry(new CompanyApiCustomerStub(1, 10, 0), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - roleBelongsToCompany false.
 *
 * Steps:
 * 1. GET list.
 * 2. Assert 403 not_a_company_member.
 */
test('Company representative-requests API — non-member role returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->roleBelongs = false;
    [$registry, $load] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['not_a_company_member'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Admin viewer; GET list with filters.
 *
 * Steps:
 * 1. Pass status and pagination.
 * 2. Assert listRequestsPaged admin_view 1 and response requests + pagination.
 */
test('Company representative-requests API — GET list forwards query and admin_view', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->listRequestsResult = [
        'items'    => [['request_uuid' => 'q1', 'status' => 'pending']],
        'page'     => 1,
        'per_page' => 20,
        'total'    => 3,
    ];
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 88, 5), $rep);
    $registry->get('request')->get = [
        'status'   => 'pending',
        'page'     => '1',
        'per_page' => '30',
        'search'   => ' doc ',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($rep->lastListRequestsArgs['admin_view'])->toBe(1)
        ->and($rep->lastListRequestsArgs['status'])->toBe('pending')
        ->and($rep->lastListRequestsArgs['search'])->toBe('doc')
        ->and($c->json['data']['requests'])->toHaveCount(1)
        ->and($c->json['pagination']['total'])->toBe(3);
});

/**
 * Prerequisites:
 * - GET with request_uuid; model returns null.
 *
 * Steps:
 * 1. request_uuid in query.
 * 2. Assert 404 representative_request_not_found.
 */
test('Company representative-requests API — GET single returns 404 when missing', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->requestByUuidResult = null;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = ['request_uuid' => 'missing-uuid'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['representative_request_not_found']);
});

/**
 * Prerequisites:
 * - GET single success.
 *
 * Steps:
 * 1. request_uuid set; stub returns row.
 * 2. Assert data.request present.
 */
test('Company representative-requests API — GET single returns request payload', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rep = new CompanyRepresentativeModelStub();
    $rep->requestByUuidResult = ['request_uuid' => 'found-1', 'status' => 'pending'];
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = ['request_uuid' => 'found-1'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['request']['request_uuid'])->toBe('found-1');
});

/**
 * Prerequisites:
 * - POST cancel; cancelRequest returns false.
 *
 * Steps:
 * 1. request_uuid + representative_request_action cancel.
 * 2. Assert 409 representative_request_not_cancellable.
 */
test('Company representative-requests API — POST cancel returns 409 when not cancellable', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->cancelRequestResult = false;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = [
        'request_uuid'                  => 'req-1',
        'representative_request_action' => 'cancel',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['representative_request_not_cancellable']);
});

/**
 * Prerequisites:
 * - POST cancel success.
 *
 * Steps:
 * 1. cancel with stub returning true.
 * 2. Assert data.cancelled true.
 */
test('Company representative-requests API — POST cancel succeeds', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->cancelRequestResult = true;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = [
        'request_uuid'                  => 'req-1',
        'representative_request_action' => 'cancel',
    ];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['cancelled'])->toBeTrue();
});

/**
 * Prerequisites:
 * - Non-admin POST create (no request_uuid).
 *
 * Steps:
 * 1. POST body with candidate_role_uuid.
 * 2. Assert 403 admin_role_required before createRequest branch uses uploads.
 */
test('Company representative-requests API — POST create requires admin', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = false;
    [$registry, $load] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload(['candidate_role_uuid' => 'role-uuid-1']);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Admin POST; missing candidate_role_uuid.
 *
 * Steps:
 * 1. POST empty candidate.
 * 2. Assert 400 candidate_role_uuid_required.
 */
test('Company representative-requests API — POST create requires candidate_role_uuid', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload(['title' => 'x']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['candidate_role_uuid_required']);
});

/**
 * Prerequisites:
 * - Admin; getRoleContext returns null.
 *
 * Steps:
 * 1. POST unknown candidate uuid.
 * 2. Assert 404 candidate_role_not_found.
 */
test('Company representative-requests API — POST create returns 404 for unknown candidate role', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->roleContextByUuid = null;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload(['candidate_role_uuid' => 'no-such-role']);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['candidate_role_not_found']);
});

/**
 * Prerequisites:
 * - Candidate already active representative.
 *
 * Steps:
 * 1. POST valid candidate uuid.
 * 2. Assert 409 candidate_already_active_representative.
 */
test('Company representative-requests API — POST create rejects active representative candidate', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->roleContextByUuid = ['customer_role_id' => 42];
    $rep->isActiveRepresentativeReturn = true;
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload(['candidate_role_uuid' => 'role-42']);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['candidate_already_active_representative']);
});

/**
 * Prerequisites:
 * - Admin; valid candidate; createRequest returns row.
 *
 * Steps:
 * 1. POST minimal create payload without documents.
 * 2. Assert 200 and data.request from stub.
 */
test('Company representative-requests API — POST create succeeds with empty documents', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->roleContextByUuid = ['customer_role_id' => 42];
    $rep->isActiveRepresentativeReturn = false;
    $rep->createRequestResult = ['request_uuid' => 'new-req', 'status' => 'pending'];
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload([
        'candidate_role_uuid' => 'role-42',
        'title'               => 'Need seal',
        'justification'       => 'Ops approved',
    ]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['request']['request_uuid'])->toBe('new-req');
});

/**
 * Prerequisites:
 * - document_refs with unknown file_code.
 *
 * Steps:
 * 1. POST with document_refs pointing at missing upload.
 * 2. Assert 400 invalid_document_file_code.
 */
test('Company representative-requests API — POST create rejects invalid document file_code', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    $rep->isAdmin = true;
    $rep->roleContextByUuid = ['customer_role_id' => 42];
    $rep->isActiveRepresentativeReturn = false;
    $upload = new ToolUploadModelStub();
    $upload->knownFileCodes = [];
    [$registry] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep, $upload);
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload([
        'candidate_role_uuid' => 'role-42',
        'document_refs'       => [
            ['file_code' => 'missing-code', 'document_type' => 'id'],
        ],
    ]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_document_file_code']);
});

/**
 * Prerequisites:
 * - POST with request_uuid but action not cancel (unsupported combo).
 *
 * Steps:
 * 1. POST request_uuid only.
 * 2. Assert 405 method_not_allowed.
 */
test('Company representative-requests API — unsupported POST with uuid returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $rep = new CompanyRepresentativeModelStub();
    [$registry, $load] = company_req_registry(new CompanyApiCustomerStub(1, 10, 5), $rep);
    $registry->get('request')->get = ['request_uuid' => 'req-1'];
    $c = new TestableControllerPublicAPIV1CompanyRepresentativeRequests($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});
