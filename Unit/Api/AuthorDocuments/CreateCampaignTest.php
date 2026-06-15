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
 * - Document uuid not found.
 *
 * Steps:
 * 1. POST create campaign.
 * 2. Assert **HTTP 404**.
 */
test('Author documents API — create campaign for missing document returns 404', function () {
    $this->documentModel->documentByUuid = null;
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->createCampaign('missing');

    expect($c->statusCode)->toBe(404);
});

/**
 * Prerequisites:
 * - Document belongs to another company.
 *
 * Steps:
 * 1. POST create campaign.
 * 2. Assert **HTTP 403**.
 */
test('Author documents API — create campaign for another company returns 403', function () {
    $this->documentModel->documentByUuid['company_id'] = 99;
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->createCampaign('doc-uuid-1');

    expect($c->statusCode)->toBe(403);
});

/**
 * Prerequisites:
 * - Document is already sent.
 *
 * Steps:
 * 1. POST create campaign.
 * 2. Assert **HTTP 422** draft-only rule.
 */
test('Author documents API — create campaign on non-draft returns 422', function () {
    $this->documentModel->documentByUuid['status'] = 'sent';
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->createCampaign('doc-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('document_not_draft');
});

/**
 * Prerequisites:
 * - Draft document in caller company.
 *
 * Steps:
 * 1. POST create campaign.
 * 2. Assert **HTTP 200** with `campaign_uuid` and draft status.
 */
test('Author documents API — create campaign on draft returns campaign uuid', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel);
    $c->createCampaign('doc-uuid-1');

    expect($c->statusCode)->toBe(200);
    expect((string)($c->json['data']['campaign_uuid'] ?? ''))->not->toBe('');
    expect($c->json['data']['status'] ?? null)->toBe('draft');
});
