<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function sendDocDocFixture(array $overrides = []): array
{
    return array_merge([
        'document_id'                   => 1,
        'uuid'                          => 'abc-123',
        'lifecycle_status_code'         => 'DRAFT',
        'owner_customer_role_id'        => 10,
        'document_group_uuid'           => 'grp-1',
        'owner_company_id'             => null,
        'signature_request_annotations' => null,
    ], $overrides);
}

function sendDocController(array $ctrlOverrides = [], array $docOverrides = [], bool $returnDoc = true): TestableControllerPublicAPIV1Documents
{
    $controller = buildDocumentsController($ctrlOverrides);
    if ($returnDoc) {
        $doc = sendDocDocFixture($docOverrides);
        $controller->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
            private array $doc;
            public function __construct(array $doc) { $this->doc = $doc; }
            public function getDocumentByUuid($uuid) { return $this->doc; }
        };
    }
    return $controller;
}

// ── Document not found ──────────────────────────────────────────────

test('sendDocument — document not found returns 404', function () {
    $c = buildDocumentsController();
    $c->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };
    $c->callSendDocument('no-such-uuid');

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toContain('document_not_found');
});

// ── Visibility denied ───────────────────────────────────────────────

test('sendDocument — visibility denied returns 404', function () {
    $c = sendDocController();
    $c->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toContain('document_not_found');
});

// ── Not owner ───────────────────────────────────────────────────────

test('sendDocument — non-owner returns 403', function () {
    $c = sendDocController(['role_id' => 999]);
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('document_owner_required');
});

// ── Multi-member group ──────────────────────────────────────────────

test('sendDocument — multi-member group returns 422 use_document_group_send', function () {
    $c = sendDocController();
    $c->model_signing_document = new class(sendDocDocFixture()) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function countGroupMembers($uuid) { return 3; }
    };
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('use_document_group_send');
});

// ── COMPLETED document via sendDocumentCore ──────────────────────────

test('sendDocument — COMPLETED document returns 422 document_already_completed', function () {
    $c = sendDocController([], ['lifecycle_status_code' => 'COMPLETED']);
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('document_already_completed');
});

// ── No signers via sendDocumentCore ─────────────────────────────────

test('sendDocument — no signers returns 422 signer_required', function () {
    $c = sendDocController([], [
        'signature_request_annotations' => json_encode([['type' => 'SIGNATURE', 'email' => 'a@example.com']]),
    ]);
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('signer_required');
});

// ── No annotations via sendDocumentCore ─────────────────────────────

test('sendDocument — no annotations returns 422 signature_annotation_required', function () {
    $doc = sendDocDocFixture(['signature_request_annotations' => null]);
    $c = sendDocController();
    $c->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $c->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) {
            return [['email' => 'a@example.com', 'signing_type' => 'SIGNATURE', 'status_code' => 'DRAFT', 'customer_role_id' => 20, 'signing_order' => 1, 'parallel_group' => 0]];
        }
    };
    $c->callSendDocument('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('signature_annotation_required');
});
