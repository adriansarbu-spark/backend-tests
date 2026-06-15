<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/AuthorDocumentsTestDoubles.php';
require_once dirname(__DIR__, 3) . '/Support/AuthorDocumentsApiHelper.php';

beforeEach(function () {
    $this->documentModel = new AuthorDocumentModelStub();
    $this->documentModel->documentByUuid = [
        'id' => 7,
        'uuid' => 'doc-uuid-1',
        'company_id' => 10,
        'status' => 'draft',
        'name' => 'Contract',
        'content_snapshot' => AuthorDocumentsApiHelper::SAMPLE_CONTENT_WITH_SIGNATURE,
    ];
    $this->campaignModel = new AuthorCampaignModelStub();
    $this->campaignModel->campaignByDocumentUuid = [
        'id' => 3,
        'uuid' => 'camp-uuid-1',
        'esign_author_document_id' => 7,
        'status' => 'draft',
    ];
    $this->campaignModel->recipientCount = 1;
});

/**
 * Prerequisites:
 * - Document uuid not found.
 *
 * Steps:
 * 1. POST send.
 * 2. Assert **HTTP 404** before PDF pipeline.
 */
test('Author documents API — send missing document returns 404', function () {
    $this->documentModel->documentByUuid = null;
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->sendDocument('missing');

    expect($c->statusCode)->toBe(404);
});

/**
 * Prerequisites:
 * - Document belongs to another company.
 *
 * Steps:
 * 1. POST send.
 * 2. Assert **HTTP 403**.
 */
test('Author documents API — send document from another company returns 403', function () {
    $this->documentModel->documentByUuid['company_id'] = 99;
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->sendDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(403);
});

/**
 * Prerequisites:
 * - Document is not a draft.
 *
 * Steps:
 * 1. POST send.
 * 2. Assert **HTTP 422** draft-only rule.
 */
test('Author documents API — send non-draft document returns 422', function () {
    $this->documentModel->documentByUuid['status'] = 'sent';
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->sendDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('document_not_draft');
});

/**
 * Prerequisites:
 * - Draft has no parties.
 *
 * Steps:
 * 1. POST send.
 * 2. Assert **HTTP 422** parties required.
 */
test('Author documents API — send without parties returns 422', function () {
    $this->documentModel->parties = [];
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->sendDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('parties_required');
});

/**
 * Prerequisites:
 * - Campaign has zero recipients.
 *
 * Steps:
 * 1. POST send with parties present.
 * 2. Assert **HTTP 422** campaign recipient guard.
 */
test('Author documents API — send without campaign recipients returns 422', function () {
    $this->documentModel->parties = [
        ['role_code' => 'signer', 'name' => 'Pat', 'email' => 'pat@example.com', 'signing_order' => 1],
    ];
    $this->campaignModel->recipientCount = 0;
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->sendDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('campaign_recipients_required');
});

/**
 * Prerequisites:
 * - PDF service base URL is not configured.
 *
 * Steps:
 * 1. POST send with parties and recipients stubbed.
 * 2. Assert **HTTP 503** PDF service not configured (stops before Chromium).
 */
test('Author documents API — send without PDF service returns 503', function () {
    $this->documentModel->parties = [
        ['role_code' => 'signer', 'name' => 'Pat', 'email' => 'pat@example.com', 'signing_order' => 1],
    ];
    $c = author_docs_controller(
        $this->documentModel,
        $this->campaignModel,
        [],
        new AuthorDocumentsConfigStub(''),
    );
    $c->sendDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(503);
    $errors = is_array($c->json['error'] ?? null) ? implode(' ', $c->json['error']) : (string)($c->json['error'] ?? '');
    expect($errors)->toContain('pdf_service_not_configured');
});
