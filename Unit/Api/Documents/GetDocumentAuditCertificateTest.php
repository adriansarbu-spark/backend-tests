<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function getAuditCertificateController(array $overrides = []): TestableControllerPublicAPIV1Documents
{
    $controller = buildDocumentsController($overrides);
    $controller->response = new class {
        public array $headers = [];
        public string $output = '';
        public function addHeader(string $h): void { $this->headers[] = $h; }
        public function setOutput(string $o): void { $this->output = $o; }
    };
    return $controller;
}

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

afterEach(function () {
    if ($this->hadRequestMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

test('getDocumentAuditCertificate returns 404 when document not found', function () {
    $controller = getAuditCertificateController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $controller->callGetDocumentAuditCertificate('nonexistent-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('document_not_found');
});

test('getDocumentAuditCertificate returns 404 when visibility denied', function () {
    $controller = getAuditCertificateController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'owner_customer_role_id' => 10, 'lifecycle_status_code' => 'COMPLETED'];
        }
    };
    $controller->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };

    $controller->callGetDocumentAuditCertificate('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('document_not_found');
});

test('getDocumentAuditCertificate returns 403 when not owner', function () {
    $controller = getAuditCertificateController(['role_id' => 99]);
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'owner_customer_role_id' => 10, 'lifecycle_status_code' => 'COMPLETED'];
        }
    };

    $controller->callGetDocumentAuditCertificate('some-uuid');

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toContain('document_owner_required');
});

test('getDocumentAuditCertificate returns 422 when document not completed', function () {
    $controller = getAuditCertificateController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'owner_customer_role_id' => 10, 'lifecycle_status_code' => 'PENDING'];
        }
    };

    $controller->callGetDocumentAuditCertificate('some-uuid');

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toContain('document_not_completed');
});

test('getDocumentAuditCertificate returns 404 when audit certificate not available', function () {
    $controller = getAuditCertificateController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'lifecycle_status_code' => 'COMPLETED',
                'audit_certificate_file_code' => 'some-code',
            ];
        }
    };
    $controller->model_tool_upload = new class extends TestDocumentsUploadModel {
        public function getUploadByCodeForSigning($code) { return null; }
    };

    $controller->callGetDocumentAuditCertificate('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('audit_certificate_not_available');
});
