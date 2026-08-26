<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function makeCancelDocument(array $overrides = []): array
{
    return array_merge([
        'document_id'            => 100,
        'uuid'                   => 'doc-uuid-1',
        'owner_customer_role_id' => 10,
        'lifecycle_status_code'  => 'PENDING',
    ], $overrides);
}

function buildCancelController(array $overrides = []): TestableControllerPublicAPIV1Documents
{
    $ctrl = buildDocumentsController($overrides);
    $ctrl->post = [];
    $postData = $overrides['post_data'] ?? [];
    $ctrl->apiRequest = \Symfony\Component\HttpFoundation\Request::create(
        '/api/documents/doc-uuid-1/cancel',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($postData) ?: ''
    );
    return $ctrl;
}

it('returns 404 when document not found', function () {
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $ctrl->callCancelDocument('no-such-uuid');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('document_not_found');
});

it('returns 404 when visibility denied', function () {
    $doc = makeCancelDocument();
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $ctrl->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('document_not_found');
});

it('returns 403 when not owner', function () {
    $doc = makeCancelDocument(['owner_customer_role_id' => 99]);
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(403);
    expect($ctrl->json['error'])->toContain('document_owner_required');
});

it('returns 422 for invalid lifecycle (COMPLETED)', function () {
    $doc = makeCancelDocument(['lifecycle_status_code' => 'COMPLETED']);
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('invalid_document_lifecycle_for_cancel');
});

it('accepts DRAFT lifecycle as cancellable', function () {
    $doc = makeCancelDocument(['lifecycle_status_code' => 'DRAFT']);
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('totp_code_required');
});

it('accepts PENDING lifecycle as cancellable', function () {
    $doc = makeCancelDocument(['lifecycle_status_code' => 'PENDING']);
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('totp_code_required');
});

it('returns 422 when TOTP code is missing', function () {
    $doc = makeCancelDocument();
    $ctrl = buildCancelController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('totp_code_required');
});

it('returns 422 when TOTP secret is not configured', function () {
    $doc = makeCancelDocument();
    $ctrl = buildCancelController(['post_data' => ['totp' => '123456']]);
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('totp_not_configured');
});

it('returns 422 for invalid TOTP code', function () {
    $doc = makeCancelDocument();
    $ctrl = buildCancelController([
        'totp_secret' => 'MLBN7FXGYFOY63AV',
        'post_data'   => ['totp' => '000000'],
    ]);
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callCancelDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('invalid_totp');
});
