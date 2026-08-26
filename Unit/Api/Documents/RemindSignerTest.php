<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function makeRemindDocument(array $overrides = []): array
{
    return array_merge([
        'document_id'            => 100,
        'uuid'                   => 'doc-uuid-1',
        'owner_customer_role_id' => 10,
        'lifecycle_status_code'  => 'PENDING',
        'name'                   => 'Test Doc',
        'document_group_uuid'    => '',
    ], $overrides);
}

function buildRemindDocModel(array $doc): TestDocumentsDocumentModel
{
    return new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
    };
}

function buildRemindController(array $postData = [], array $overrides = []): TestableControllerPublicAPIV1Documents
{
    $ctrl = buildDocumentsController($overrides);
    $ctrl->apiRequest = \Symfony\Component\HttpFoundation\Request::create(
        '/api/documents/doc-uuid-1/remind',
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
    $ctrl = buildRemindController();
    $ctrl->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $ctrl->callRemindSigner('no-such-uuid');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('document_not_found');
});

it('returns 404 when visibility denied', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController();
    $ctrl->model_signing_document = buildRemindDocModel($doc);
    $ctrl->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('document_not_found');
});

it('returns 403 when not owner', function () {
    $doc = makeRemindDocument(['owner_customer_role_id' => 99]);
    $ctrl = buildRemindController();
    $ctrl->model_signing_document = buildRemindDocModel($doc);

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(403);
    expect($ctrl->json['error'])->toContain('document_owner_required');
});

it('returns 422 when document is not PENDING', function () {
    $doc = makeRemindDocument(['lifecycle_status_code' => 'DRAFT']);
    $ctrl = buildRemindController();
    $ctrl->model_signing_document = buildRemindDocModel($doc);

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('invalid_document_lifecycle_for_reminder');
});

it('returns 422 when email is missing', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController();
    $ctrl->model_signing_document = buildRemindDocModel($doc);

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('valid_email_required');
});

it('returns 422 when email is invalid', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController(['email' => 'not-an-email']);
    $ctrl->model_signing_document = buildRemindDocModel($doc);

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('valid_email_required');
});

it('returns 404 when signer not found', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController(['email' => 'nobody@example.com']);
    $ctrl->model_signing_document = buildRemindDocModel($doc);
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignerByDocumentAndEmail($documentId, $email) { return null; }
    };

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(404);
    expect($ctrl->json['error'])->toContain('signer_not_found');
});

it('returns 422 when signer status is not INVITED or VIEWED', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController(['email' => 'signer@example.com']);
    $ctrl->model_signing_document = buildRemindDocModel($doc);
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignerByDocumentAndEmail($documentId, $email) {
            return ['email' => 'signer@example.com', 'status_code' => 'SIGNED', 'sign_code' => 'abc'];
        }
    };

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(422);
    expect($ctrl->json['error'])->toContain('invalid_signer_status_for_reminder');
});

it('returns 500 when signer sign_code is missing', function () {
    $doc = makeRemindDocument();
    $ctrl = buildRemindController(['email' => 'signer@example.com']);
    $ctrl->model_signing_document = buildRemindDocModel($doc);
    $ctrl->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignerByDocumentAndEmail($documentId, $email) {
            return ['email' => 'signer@example.com', 'status_code' => 'INVITED', 'sign_code' => ''];
        }
    };

    $ctrl->callRemindSigner('doc-uuid-1');

    expect($ctrl->statusCode)->toBe(500);
    expect($ctrl->json['error'])->toContain('signer_sign_code_missing');
});
