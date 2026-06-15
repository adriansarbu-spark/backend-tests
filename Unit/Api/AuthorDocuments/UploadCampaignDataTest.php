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
    $this->campaignModel->campaignByUuid = [
        'id' => 3,
        'uuid' => 'camp-uuid-1',
        'esign_author_document_id' => 7,
        'status' => 'draft',
    ];
});

/**
 * Prerequisites:
 * - Upload body has no rows.
 *
 * Steps:
 * 1. POST campaign data with empty `rows`.
 * 2. Assert **HTTP 422** rows required.
 */
test('Author documents API — upload campaign data without rows returns 422', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, ['rows' => []]);
    $c->uploadCampaignData('doc-uuid-1', 'camp-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('campaign_rows_required');
});

/**
 * Prerequisites:
 * - Campaign uuid does not belong to the document.
 *
 * Steps:
 * 1. POST rows for unknown campaign.
 * 2. Assert **HTTP 404** Campaign not found.
 */
test('Author documents API — upload for unknown campaign returns 404', function () {
    $this->campaignModel->campaignByUuid = null;
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'rows' => [
            ['party_values' => ['signer' => ['name' => 'A', 'email' => 'a@example.com']], 'field_values' => []],
        ],
    ]);
    $c->uploadCampaignData('doc-uuid-1', 'camp-uuid-1');

    expect($c->statusCode)->toBe(404);
    expect($c->json['error'] ?? [])->toContain('campaign_not_found');
});

/**
 * Prerequisites:
 * - Model upload returns failure.
 *
 * Steps:
 * 1. POST valid-looking rows.
 * 2. Assert **HTTP 422** with model error string.
 */
test('Author documents API — upload campaign data model failure returns 422', function () {
    $this->campaignModel->uploadResult = ['success' => false, 'error' => 'Only one value per party allowed'];
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'rows' => [
            ['party_values' => ['signer' => ['name' => 'A', 'email' => 'a@example.com']], 'field_values' => []],
        ],
    ]);
    $c->uploadCampaignData('doc-uuid-1', 'camp-uuid-1');

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('campaign_upload_failed');
});

/**
 * Prerequisites:
 * - Model upload succeeds.
 *
 * Steps:
 * 1. POST one recipient row.
 * 2. Assert **HTTP 200** and `total_recipients`.
 */
test('Author documents API — upload campaign data success returns total_recipients', function () {
    $this->campaignModel->uploadResult = ['success' => true, 'total_recipients' => 2];
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'rows' => [
            ['party_values' => ['signer' => ['name' => 'A', 'email' => 'a@example.com']], 'field_values' => []],
        ],
    ]);
    $c->uploadCampaignData('doc-uuid-1', 'camp-uuid-1');

    expect($c->statusCode)->toBe(200);
    expect($c->json['data']['total_recipients'] ?? null)->toBe(2);
});
