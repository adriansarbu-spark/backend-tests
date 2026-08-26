<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

afterEach(function () {
    if ($this->hadRequestMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - An owner can open a personal document that has owner and invitee signing links.
 * - The request is not authenticated as the integrator that created the document.
 *
 * Steps:
 * 1. Open the document through its UUID as the owner.
 * 2. Inspect both signer rows in the response.
 * 3. Assert the owner sees only their own signing code and receives no integrator return URL or invitee secret.
 */
test('Documents API — UUID detail hides non-originating integrator sign codes', function () {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $documentModel->document = documents_test_document();
    $signerModel->signers = documents_test_signers();
    $controller->request->get = ['uuid' => 'document-owner-uuid'];

    $controller->index();

    $detail = $controller->json['data']['documents'][0];
    expect($controller->statusCode)->toBe(200)
        ->and($detail['uuid'])->toBe('document-owner-uuid')
        ->and($detail)->not->toHaveKey('return_url')
        ->and($detail['signers'][0]['sign_code'])->toBe('owner-sign-code')
        ->and($detail['signers'][0])->not->toHaveKey('signing_url')
        ->and($detail['signers'][1])->not->toHaveKey('sign_code')
        ->and($detail['signers'][1])->not->toHaveKey('signing_url')
        ->and(json_encode($detail))->not->toContain('invitee-sign-code');
});

/**
 * Prerequisites:
 * - A company document originated from CSC API client 700 and contains two signer links plus a return URL.
 * - The current service principal belongs to a different CSC API client in the same owner role.
 *
 * Steps:
 * 1. Open the UUID detail as non-originating client 701.
 * 2. Assert owner-level document metadata remains available.
 * 3. Assert neither signer code, signing URL, nor the originating client's return URL is disclosed.
 */
test('Documents API — another integrator cannot read originating integrator secrets', function () {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $documentModel->document = documents_test_document();
    $signerModel->signers = documents_test_signers();
    $controller->useremail = 'service-principal@example.com';
    $controller->integratorClientRow = ['csc_api_client_id' => 701];
    $controller->request->get = ['uuid' => 'document-owner-uuid'];

    $controller->index();

    $detail = $controller->json['data']['documents'][0];
    $encoded = json_encode($detail);
    expect($controller->statusCode)->toBe(200)
        ->and($detail)->not->toHaveKey('return_url')
        ->and($encoded)->not->toContain('owner-sign-code')
        ->and($encoded)->not->toContain('invitee-sign-code')
        ->and($encoded)->not->toContain('integrator.example.test');
});

/**
 * Prerequisites:
 * - A company document originated from CSC API client 700.
 * - The current owner service principal is the same client that created it.
 *
 * Steps:
 * 1. Open the document detail as originating client 700.
 * 2. Assert each signer receives the integrator-only redirect data.
 * 3. Assert the configured return URL is exposed only in this originating-client response.
 */
test('Documents API — originating integrator receives its signing redirects', function () {
    [$controller, $documentModel, $signerModel] = documents_test_controller();
    $documentModel->document = documents_test_document();
    $signerModel->signers = documents_test_signers();
    $controller->useremail = 'service-principal@example.com';
    $controller->integratorClientRow = ['csc_api_client_id' => 700];
    $controller->request->get = ['uuid' => 'document-owner-uuid'];

    $controller->index();

    $detail = $controller->json['data']['documents'][0];
    expect($controller->statusCode)->toBe(200)
        ->and($detail['return_url'])->toBe('https://integrator.example.test/return')
        ->and($detail['signers'][0]['sign_code'])->toBe('owner-sign-code')
        ->and($detail['signers'][0]['signing_url'])->toContain('owner-sign-code')
        ->and($detail['signers'][1]['sign_code'])->toBe('invitee-sign-code')
        ->and($detail['signers'][1]['signing_url'])->toContain('invitee-sign-code');
});

/**
 * Prerequisites:
 * - A company document lists a CC recipient but belongs to another company role.
 * - The caller uses that CC email and a foreign role.
 *
 * Steps:
 * 1. Attempt to open the UUID detail using the CC address.
 * 2. Assert access is refused before visibility or signer lookup.
 * 3. Assert the response contains no document UUID, signer code, or integrator URL.
 */
test('Documents API — CC email and foreign tenant role grant no UUID access', function () {
    $customer = new DocumentsCustomerStub(customerId: 202, roleId: 99, companyId: 88);
    [$controller, $documentModel, $signerModel, $visibilityModel] = documents_test_controller($customer);
    $documentModel->document = documents_test_document();
    $documentModel->accessibleOwnerRole = true;
    $signerModel->signers = documents_test_signers();
    $controller->useremail = 'cc@example.com';
    $controller->request->get = ['uuid' => 'document-owner-uuid'];

    $controller->index();

    $encoded = json_encode($controller->json);
    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toContain('document_owner_required')
        ->and($visibilityModel->checks)->toBe(0)
        ->and($encoded)->not->toContain('document-owner-uuid')
        ->and($encoded)->not->toContain('owner-sign-code')
        ->and($encoded)->not->toContain('integrator.example.test');
});
