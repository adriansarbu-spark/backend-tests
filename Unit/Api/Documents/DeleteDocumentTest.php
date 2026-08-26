<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function makeDocument(array $overrides = []): array
{
    return array_merge([
        'document_id'            => 100,
        'uuid'                   => 'doc-uuid-1',
        'owner_customer_role_id' => 10,
        'owner_email'            => 'owner@example.com',
        'lifecycle_status_code'  => 'DRAFT',
        'name'                   => 'Test Doc',
    ], $overrides);
}

it('returns 404 when document not found', function () {
    $ctrl = buildDocumentsController();
    $ctrl->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $ctrl->callDeleteDocument('no-such-uuid');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('document_not_found');
});

it('returns 403 when customer has no role and no email', function () {
    $ctrl = buildDocumentsController(['role_id' => 0, 'email' => 'anonymous@simplifi.ro']);
    $ctrl->useremail = null;
    $doc = makeDocument();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(403);
    expect($ctrl->json['error'])->toContain('customer_role_or_email_required');
});

it('returns 403 when user is not owner and not signer', function () {
    $doc = makeDocument(['owner_customer_role_id' => 99]);
    $ctrl = buildDocumentsController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) { return []; }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(403);
    expect($ctrl->json['error'])->toContain('access_denied');
});

it('returns 409 for owner deleting PENDING document', function () {
    $doc = makeDocument(['lifecycle_status_code' => 'PENDING']);
    $ctrl = buildDocumentsController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(409);
    expect($ctrl->json['error'])->toContain('cancel_required_before_delete');
});

it('succeeds for owner deleting DRAFT document', function () {
    $doc = makeDocument(['lifecycle_status_code' => 'DRAFT']);
    $ctrl = buildDocumentsController();
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(200);
    expect($ctrl->json['data']['uuid'])->toBe('doc-uuid-1');
});

it('returns 409 for signer with INVITED status on PENDING document', function () {
    $doc = makeDocument([
        'lifecycle_status_code'  => 'PENDING',
        'owner_customer_role_id' => 99,
    ]);
    $ctrl = buildDocumentsController(['email' => 'signer@example.com']);
    $ctrl->useremail = 'signer@example.com';
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) {
            return [['email' => 'signer@example.com', 'customer_role_id' => 0, 'status_code' => 'INVITED']];
        }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(409);
    expect($ctrl->json['error'])->toContain('reject_required_before_delete');
});

it('succeeds for signer on COMPLETED document (visibility hidden)', function () {
    $doc = makeDocument([
        'lifecycle_status_code'  => 'COMPLETED',
        'owner_customer_role_id' => 99,
    ]);
    $ctrl = buildDocumentsController(['email' => 'signer@example.com']);
    $ctrl->useremail = 'signer@example.com';
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) {
            return [['email' => 'signer@example.com', 'customer_role_id' => 0, 'status_code' => 'SIGNED']];
        }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(200);
    expect($ctrl->json['data']['uuid'])->toBe('doc-uuid-1');
});

it('returns 422 for invalid delete mode', function () {
    $doc = makeDocument(['lifecycle_status_code' => 'COMPLETED']);
    $ctrl = buildDocumentsController();
    $ctrl->request = (object)['get' => ['mode' => 'wipe']];
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('invalid_delete_mode');
});

it('returns 403 for purge mode when not owner', function () {
    $doc = makeDocument([
        'lifecycle_status_code'  => 'COMPLETED',
        'owner_customer_role_id' => 99,
    ]);
    $ctrl = buildDocumentsController(['email' => 'signer@example.com']);
    $ctrl->useremail = 'signer@example.com';
    $ctrl->request = (object)['get' => ['mode' => 'purge']];
    $ctrl->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) {
            return [['email' => 'signer@example.com', 'customer_role_id' => 0, 'status_code' => 'SIGNED']];
        }
    };

    $ctrl->callDeleteDocument('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(403);
    expect($ctrl->json['error'])->toContain('document_owner_required');
});
