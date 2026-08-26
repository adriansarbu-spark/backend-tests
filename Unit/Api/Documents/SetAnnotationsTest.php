<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function annotationDoc(array $overrides = []): array
{
    return array_merge([
        'document_id' => 1,
        'uuid' => 'abc-123',
        'lifecycle_status_code' => 'DRAFT',
        'owner_customer_role_id' => 10,
        'document_group_uuid' => 'grp-1',
        'owner_company_id' => null,
        'name' => 'Test',
    ], $overrides);
}

function wireAnnotationPost(TestableControllerPublicAPIV1Documents $controller, array $postData): void
{
    $json = json_encode($postData);
    $controller->apiRequest = new class($json) extends \Symfony\Component\HttpFoundation\Request {
        private string $jsonBody;
        public function __construct(string $json) {
            parent::__construct();
            $this->jsonBody = $json;
        }
        public function getContent($asResource = false) { return $this->jsonBody; }
    };
}

function buildAnnotationController(array $docOverrides = [], array $ctrlOverrides = []): TestableControllerPublicAPIV1Documents
{
    $controller = buildDocumentsController($ctrlOverrides);
    $doc = annotationDoc($docOverrides);
    $controller->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function updateAnnotations($id, $annotations) { return true; }
    };
    return $controller;
}

it('returns 404 when document not found for annotations', function () {
    $controller = buildDocumentsController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };
    wireAnnotationPost($controller, ['annotations' => []]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(404);
    expect($controller->json['error'])->toContain('document_not_found');
});

it('returns 403 when not owner for annotations', function () {
    $controller = buildAnnotationController(['owner_customer_role_id' => 99]);
    wireAnnotationPost($controller, ['annotations' => [['type' => 'SIGNATURE']]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(403);
    expect($controller->json['error'])->toContain('document_owner_required');
});

it('returns 422 when not DRAFT for annotations', function () {
    $controller = buildAnnotationController(['lifecycle_status_code' => 'SENT']);
    wireAnnotationPost($controller, ['annotations' => [['type' => 'SIGNATURE']]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_not_draft');
});

it('returns 422 when group locked for annotations', function () {
    $controller = buildDocumentsController();
    $doc = annotationDoc();
    $controller->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function isGroupLocked($document) { return true; }
    };
    wireAnnotationPost($controller, ['annotations' => [['type' => 'SIGNATURE']]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('document_group_locked');
});

it('returns 422 when annotations missing', function () {
    $controller = buildAnnotationController();
    wireAnnotationPost($controller, []);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('annotations_required');
});

it('returns 422 for non-array annotation entry', function () {
    $controller = buildAnnotationController();
    wireAnnotationPost($controller, ['annotations' => ['not-an-array']]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_annotation');
});

it('returns 422 for invalid annotation type', function () {
    $controller = buildAnnotationController();
    wireAnnotationPost($controller, ['annotations' => [['type' => 'STAMP']]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_annotation_type');
});

it('accepts SIGNATURE and SEAL annotation types', function () {
    $controller = buildAnnotationController();
    wireAnnotationPost($controller, ['annotations' => [
        ['type' => 'SIGNATURE', 'x' => 10, 'y' => 20],
        ['type' => 'SEAL', 'x' => 30, 'y' => 40],
    ]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(200);
    expect($controller->json['data']['message'])->toBe('Annotations updated successfully');
});

it('coerces numeric fields to float and page to int', function () {
    $updated = null;
    $controller = buildDocumentsController();
    $doc = annotationDoc();
    $controller->model_signing_document = new class($doc, $updated) extends TestDocumentsDocumentModel {
        private array $doc;
        private $ref;
        public function __construct(array $doc, &$ref) { $this->doc = $doc; $this->ref = &$ref; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function updateAnnotations($id, $annotations) { $this->ref = $annotations; return true; }
    };
    wireAnnotationPost($controller, ['annotations' => [
        ['type' => 'SIGNATURE', 'x' => '100', 'y' => '200', 'width' => '50', 'height' => '25', 'page' => '3'],
    ]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(200);
    expect($updated[0]['x'])->toBe(100.0);
    expect($updated[0]['y'])->toBe(200.0);
    expect($updated[0]['width'])->toBe(50.0);
    expect($updated[0]['height'])->toBe(25.0);
    expect($updated[0]['page'])->toBe(3);
});

it('defaults required to true when omitted', function () {
    $updated = null;
    $controller = buildDocumentsController();
    $doc = annotationDoc();
    $controller->model_signing_document = new class($doc, $updated) extends TestDocumentsDocumentModel {
        private array $doc;
        private $ref;
        public function __construct(array $doc, &$ref) { $this->doc = $doc; $this->ref = &$ref; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function updateAnnotations($id, $annotations) { $this->ref = $annotations; return true; }
    };
    wireAnnotationPost($controller, ['annotations' => [
        ['type' => 'SIGNATURE'],
    ]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(200);
    expect($updated[0]['required'])->toBeTrue();
});

it('returns 422 for self-signature conflict', function () {
    $controller = buildAnnotationController();
    $controller->model_signing_signer = new class extends TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) {
            return [['email' => 'owner@example.com', 'draft_signature_data' => 'base64data']];
        }
    };
    wireAnnotationPost($controller, ['annotations' => [
        ['type' => 'SIGNATURE', 'email' => 'owner@example.com'],
    ]]);

    $controller->callSetAnnotations('abc-123');

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('self_signature_request_conflict');
});
