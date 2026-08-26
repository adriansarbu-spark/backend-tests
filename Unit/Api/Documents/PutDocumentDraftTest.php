<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function draftDocument(array $overrides = []): array
{
    return array_merge([
        'document_id' => 1,
        'uuid' => 'abc-123',
        'lifecycle_status_code' => 'DRAFT',
        'owner_customer_role_id' => 10,
        'document_group_uuid' => 'grp-1',
        'owner_company_id' => null,
        'name' => 'Test',
        'expires_at' => null,
        'signature_level' => 'QUALIFIED',
        'cc_notification_emails' => null,
    ], $overrides);
}

function wireRequestBody(TestableControllerPublicAPIV1Documents $controller, array $data): void
{
    $json = json_encode($data);
    $controller->apiRequest = new class($json) extends \Symfony\Component\HttpFoundation\Request {
        private string $jsonBody;
        public function __construct(string $json) {
            parent::__construct();
            $this->jsonBody = $json;
        }
        public function getContent($asResource = false) { return $this->jsonBody; }
    };
}

function buildDraftController(array $docOverrides = [], array $ctrlOverrides = []): TestableControllerPublicAPIV1Documents
{
    $controller = buildDocumentsController($ctrlOverrides);
    $doc = draftDocument($docOverrides);
    $controller->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function updateDocumentDraft($id, $patch) {
            $this->doc = array_merge($this->doc, $patch);
            return true;
        }
        public function detachDocumentFromGroup($id, $newGroupUuid) {
            $this->doc['document_group_uuid'] = $newGroupUuid;
            return true;
        }
    };
    return $controller;
}

it('returns 404 when document not found', function () {
    $controller = buildDocumentsController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };
    wireRequestBody($controller, ['name' => 'X']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(404);
    expect($controller->json['error'])->toContain('document_not_found');
});

it('returns 404 when visibility denied', function () {
    $controller = buildDraftController();
    $controller->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };
    wireRequestBody($controller, ['name' => 'X']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(404);
    expect($controller->json['error'])->toContain('document_not_found');
});

it('returns 403 when not owner', function () {
    $controller = buildDraftController(['owner_customer_role_id' => 99]);
    wireRequestBody($controller, ['name' => 'X']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(403);
    expect($controller->json['error'])->toContain('document_owner_required');
});

it('returns 422 when not DRAFT', function () {
    $controller = buildDraftController(['lifecycle_status_code' => 'SENT']);
    wireRequestBody($controller, ['name' => 'X']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_not_draft');
});

it('returns 422 when group locked', function () {
    $controller = buildDraftController();
    $controller->model_signing_document = new class(draftDocument()) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function isGroupLocked($document) { return true; }
    };
    wireRequestBody($controller, ['name' => 'X']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_group_locked');
});

it('returns 422 when patch is empty', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['unrecognized_field' => 'value']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_patch_required');
});

it('returns 422 when name is empty', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['name' => '']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('name_required');
});

it('returns 422 for invalid signature_level', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['signature_level' => 'INVALID']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_signature_level');
});

it('returns 422 for invalid ownership', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['ownership' => 'bogus']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_ownership');
});

it('returns 422 for invalid cc_notification_emails', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['cc_notification_emails' => 'not-an-email']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_cc_notification_emails');
});

it('detaches via document_group_uuid=null', function () {
    $detachCalled = false;
    $controller = buildDocumentsController();
    $doc = draftDocument();
    $controller->model_signing_document = new class($doc, $detachCalled) extends TestDocumentsDocumentModel {
        private array $doc;
        private $flag;
        public function __construct(array $doc, &$flag) { $this->doc = $doc; $this->flag = &$flag; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function detachDocumentFromGroup($id, $newGroupUuid) {
            $this->flag = true;
            $this->doc['document_group_uuid'] = $newGroupUuid;
            return true;
        }
    };
    wireRequestBody($controller, ['document_group_uuid' => null]);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(200);
    expect($detachCalled)->toBeTrue();
    expect($controller->json['data']['detached_from_document_group_uuid'])->toBe('grp-1');
});

it('detaches via detach_from_document_group=true', function () {
    $detachCalled = false;
    $controller = buildDocumentsController();
    $doc = draftDocument();
    $controller->model_signing_document = new class($doc, $detachCalled) extends TestDocumentsDocumentModel {
        private array $doc;
        private $flag;
        public function __construct(array $doc, &$flag) { $this->doc = $doc; $this->flag = &$flag; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function detachDocumentFromGroup($id, $newGroupUuid) {
            $this->flag = true;
            $this->doc['document_group_uuid'] = $newGroupUuid;
            return true;
        }
    };
    wireRequestBody($controller, ['detach_from_document_group' => true]);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(200);
    expect($detachCalled)->toBeTrue();
});

it('returns 422 for document_group_uuid with non-empty value', function () {
    $controller = buildDraftController();
    wireRequestBody($controller, ['document_group_uuid' => 'some-other-group']);

    $controller->callPutDocumentDraft('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_group_reassign_not_supported');
});
