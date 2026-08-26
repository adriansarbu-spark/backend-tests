<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function getDocumentFileController(array $overrides = []): TestableControllerPublicAPIV1Documents
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

test('getDocumentFile returns 404 when document not found', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $controller->callGetDocumentFile('nonexistent-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('document_not_found');
});

test('getDocumentFile returns 404 when visibility denied', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'owner_customer_role_id' => 10, 'lifecycle_status_code' => 'DRAFT'];
        }
    };
    $controller->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('document_not_found');
});

test('getDocumentFile returns 403 when not owner', function () {
    $controller = getDocumentFileController(['role_id' => 99]);
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'owner_customer_role_id' => 10, 'lifecycle_status_code' => 'DRAFT'];
        }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toContain('document_owner_required');
});

test('getDocumentFile returns 410 when files purged', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'lifecycle_status_code' => 'COMPLETED',
                'files_purged_at' => '2025-01-01 00:00:00',
            ];
        }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(410)
        ->and($controller->json['error'])->toContain('document_files_purged');
});

test('getDocumentFile returns 404 when no file_code available', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'lifecycle_status_code' => 'DRAFT',
                'files_purged_at' => null,
                'final_file_code' => '',
                'current_file_code' => '',
                'original_file_code' => '',
            ];
        }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('document_file_not_found');
});

test('getDocumentFile uses final_file_code for COMPLETED documents', function () {
    $controller = getDocumentFileController();
    $queriedCodes = [];
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'name' => 'Test Doc',
                'lifecycle_status_code' => 'COMPLETED',
                'files_purged_at' => null,
                'final_file_code' => 'final-code-123',
                'current_file_code' => 'current-code-456',
                'original_file_code' => 'original-code-789',
            ];
        }
    };
    $controller->db = new class {
        public ?string $lastEscaped = null;
        public function query(string $sql) {
            return (object)['row' => null, 'rows' => [], 'num_rows' => 0];
        }
        public function escape(string $value): string {
            $this->lastEscaped = $value;
            return addslashes($value);
        }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->db->lastEscaped)->toBe('final-code-123')
        ->and($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('file_not_found');
});

test('getDocumentFile returns 404 when upload record not found in DB', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'name' => 'Test Doc',
                'lifecycle_status_code' => 'DRAFT',
                'files_purged_at' => null,
                'final_file_code' => '',
                'current_file_code' => 'current-code-456',
                'original_file_code' => '',
            ];
        }
    };
    $controller->db = new class {
        public function query(string $sql) {
            return (object)['row' => null, 'rows' => [], 'num_rows' => 0];
        }
        public function escape(string $value): string { return addslashes($value); }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('file_not_found');
});

test('getDocumentFile returns 404 when file not on disk', function () {
    $controller = getDocumentFileController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return [
                'document_id' => 1,
                'owner_customer_role_id' => 10,
                'name' => 'Test Doc',
                'lifecycle_status_code' => 'DRAFT',
                'files_purged_at' => null,
                'final_file_code' => '',
                'current_file_code' => 'current-code-456',
                'original_file_code' => '',
            ];
        }
    };
    $controller->db = new class {
        public function query(string $sql) {
            return (object)[
                'row' => [
                    'relative_path' => 'nonexistent/path',
                    'filename' => 'nonexistent-file.pdf',
                ],
                'rows' => [],
                'num_rows' => 1,
            ];
        }
        public function escape(string $value): string { return addslashes($value); }
    };

    $controller->callGetDocumentFile('some-uuid');

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toContain('file_not_found_on_disk');
});
