<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/AuthorDocumentsTestDoubles.php';

beforeEach(function () {
    $this->documentModel = new AuthorDocumentModelStub();
    $this->documentModel->documentByUuid = [
        'id' => 7,
        'uuid' => 'doc-uuid-1',
        'company_id' => 10,
        'status' => 'draft',
    ];
    $this->campaignModel = new AuthorCampaignModelStub();
});

/**
 * Prerequisites:
 * - Document uuid is unknown.
 *
 * Steps:
 * 1. PUT save on missing document.
 * 2. Assert **HTTP 404**.
 */
test('Author documents API — save unknown document returns 404', function () {
    $this->documentModel->documentByUuid = null;
    $c = author_docs_controller($this->documentModel, $this->campaignModel, ['name' => 'Updated']);
    $c->saveDocument('missing-uuid');

    expect($c->statusCode)->toBe(404);
    expect($c->json['error'] ?? [])->toContain('document_not_found');
});

/**
 * Prerequisites:
 * - Document belongs to another company.
 *
 * Steps:
 * 1. PUT save.
 * 2. Assert **HTTP 403** Access denied.
 */
test('Author documents API — save document from another company returns 403', function () {
    $this->documentModel->documentByUuid['company_id'] = 99;
    $c = author_docs_controller($this->documentModel, $this->campaignModel, ['name' => 'Updated']);
    $c->saveDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(403);
    expect($c->json['error'] ?? [])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - Document is no longer a draft.
 *
 * Steps:
 * 1. PUT save.
 * 2. Assert **HTTP 422** draft-only rule.
 */
test('Author documents API — save non-draft document returns 422', function () {
    $this->documentModel->documentByUuid['status'] = 'sent';
    $c = author_docs_controller($this->documentModel, $this->campaignModel, ['name' => 'Updated']);
    $c->saveDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('document_not_draft');
});

/**
 * Prerequisites:
 * - Draft document in caller company.
 *
 * Steps:
 * 1. PUT name and parties with required `signing_order`.
 * 2. Assert **HTTP 200** and draft status in response.
 */
test('Author documents API — save draft updates parties and returns draft status', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'name' => 'Renamed',
        'content_snapshot' => '<p>Updated</p>',
        'parties' => [
            [
                'role_code' => 'signer',
                'role_label' => 'Signer',
                'name' => 'Pat',
                'email' => 'pat@example.com',
                'signing_order' => 1,
                'parallel_group' => 0,
            ],
        ],
    ]);
    $c->saveDocument('doc-uuid-1');

    expect($c->statusCode)->toBe(200);
    expect($c->json['data']['uuid'] ?? null)->toBe('doc-uuid-1');
    expect($c->json['data']['status'] ?? null)->toBe('draft');
});
