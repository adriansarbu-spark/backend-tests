<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscAccess::request().
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
 * - Master CSC API disabled.
 *
 * Steps:
 * 1. POST request() with valid answers.
 * 2. Assert 403 csc_api_disabled; submitRequest never called.
 */
test('CSC access request — master disabled returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $access->masterEnabled = false;
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload(['answers' => csc_minimal_valid_answers()]);
    $c->request();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['csc_api_disabled'])
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Already approved company.
 *
 * Steps:
 * 1. POST request().
 * 2. Assert 409 already_approved; no submit.
 */
test('CSC access request — already approved returns 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = ['access_state' => ModelCscCompanyAccess::STATE_APPROVED];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload(['answers' => csc_minimal_valid_answers()]);
    $c->request();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['already_approved'])
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Pending (requested) state.
 *
 * Steps:
 * 1. POST request().
 * 2. Assert 409 request_already_pending.
 */
test('CSC access request — pending state returns 409 request_already_pending', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = ['access_state' => ModelCscCompanyAccess::STATE_REQUESTED];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload(['answers' => csc_minimal_valid_answers()]);
    $c->request();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['request_already_pending'])
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Allowable state; missing answers.
 *
 * Steps:
 * 1. POST empty body.
 * 2. Assert 422 answers_required.
 */
test('CSC access request — missing answers returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload([]);
    $c->request();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['answers_required'])
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Invalid select value for data_residency.
 *
 * Steps:
 * 1. POST answers with invalid residency.
 * 2. Assert 422 with invalid_data_residency; no submit.
 */
test('CSC access request — invalid answers return 422 validation errors', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload([
        'answers' => [
            'has_iso27001'          => true,
            'has_soc2'              => false,
            'end_user_auth_binding' => 'ok',
            'data_residency'        => 'not_a_region',
        ],
    ]);
    $c->request();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('invalid_data_residency')
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - DPA published; submission omits accepted UUIDs.
 *
 * Steps:
 * 1. POST valid answers without legal acceptance.
 * 2. Assert 422 with pending_documents data; no submit.
 */
test('CSC access request — missing DPA acceptance returns 422 with pending documents', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $legal = new CscLegalDocumentModelStub();
    $legal->activeForFlowRows = csc_dpa_legal_documents();
    [$registry] = csc_access_registry(
        new CscApiCustomerStub(1, 10, 5),
        $access,
        null,
        null,
        $legal,
    );
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload(['answers' => csc_minimal_valid_answers()]);
    $c->request();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->not->toBeEmpty()
        ->and($c->json['data']['flow'])->toBe(ControllerPublicAPIV1CscAccess::DPA_FLOW)
        ->and($c->json['data']['pending_documents'])->not->toBeEmpty()
        ->and($access->submitRequestCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - No DPA configured; valid ISO-shortcut answers.
 *
 * Steps:
 * 1. POST request().
 * 2. Assert 201; normalized answers persisted; questionnaire_id returned; status requested.
 */
test('CSC access request — succeeds without DPA and persists normalized submission', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $access->submitRequestReturnId = 77;
    $acceptance = new CscCustomerLegalAcceptanceStub();
    [$registry] = csc_access_registry(
        new CscApiCustomerStub(1, 10, 5, null, 'contact@example.com'),
        $access,
        null,
        null,
        new CscLegalDocumentModelStub(),
        $acceptance,
    );
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setPostPayload([
        'answers' => csc_minimal_valid_answers(),
        'contact_email' => 'ops-contact@example.com',
    ]);
    $c->request();

    expect($c->statusCode)->toBe(201)
        ->and($access->submitRequestCalls)->toBe(1)
        ->and($access->lastSubmitRequest['meta']['schema_version'])->toBe(CscSecurityQuestionnaire::SCHEMA_VERSION)
        ->and($access->lastSubmitRequest['meta']['contact_email'])->toBe('ops-contact@example.com')
        ->and($access->lastSubmitRequest['meta']['has_iso27001'])->toBeTrue()
        ->and($c->json['data']['questionnaire_id'])->toBe(77)
        ->and($c->json['data']['access_state'])->toBe(ModelCscCompanyAccess::STATE_REQUESTED)
        ->and($acceptance->addFlowAcceptancesCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - DPA published; correct accepted UUID; empty contact_email falls back to customer email.
 *
 * Steps:
 * 1. POST with accepted_legal_document_uuids.
 * 2. Assert 201; DPA acceptance recorded once; contact email fallback used.
 */
test('CSC access request — records DPA acceptance and falls back to customer email', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    $legal = new CscLegalDocumentModelStub();
    $legal->activeForFlowRows = csc_dpa_legal_documents();
    $acceptance = new CscCustomerLegalAcceptanceStub();
    [$registry] = csc_access_registry(
        new CscApiCustomerStub(42, 10, 5, null, 'fallback@example.com'),
        $access,
        null,
        null,
        $legal,
        $acceptance,
    );
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $uuids = array_map(static fn (array $d): string => (string) $d['uuid'], csc_dpa_legal_documents());
    $c->setPostPayload([
        'answers' => csc_minimal_valid_answers(),
        'accepted_legal_document_uuids' => $uuids,
    ]);
    $c->request();

    expect($c->statusCode)->toBe(201)
        ->and($access->lastSubmitRequest['meta']['contact_email'])->toBe('fallback@example.com')
        ->and($access->lastSubmitRequest['meta']['dpa_version'])->toBe('1.0')
        ->and($acceptance->addFlowAcceptancesCalls)->toHaveCount(1)
        ->and($acceptance->addFlowAcceptancesCalls[0]['customer_id'])->toBe(42)
        ->and($acceptance->addFlowAcceptancesCalls[0]['flow_code'])->toBe(ControllerPublicAPIV1CscAccess::DPA_FLOW);
});

/**
 * Prerequisites:
 * - GET method on request endpoint.
 *
 * Steps:
 * 1. GET request().
 * 2. Assert 405.
 */
test('CSC access request — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->request();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});
