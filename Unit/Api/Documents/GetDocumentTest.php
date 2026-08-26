<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function documentFixture(array $overrides = []): array {
    return array_merge([
        'document_id' => 1,
        'uuid' => 'test-uuid',
        'owner_email' => 'owner@example.com',
        'owner_customer_role_id' => 10,
        'document_group_uuid' => '',
        'name' => 'Test Doc',
        'lifecycle_status_code' => 'DRAFT',
        'signature_level' => 'qualified',
        'date_added' => '2024-01-01 00:00:00',
        'sent_at' => null,
        'completed_at' => null,
        'cancelled_at' => null,
        'rejected_at' => null,
        'expires_at' => null,
        'original_file_code' => 'file-001',
        'current_file_code' => null,
        'final_file_code' => null,
        'cc_notification_emails' => '',
        'signing_order_type' => 'parallel',
        'owner_company_id' => null,
        'signature_request_annotations' => null,
        'files_purged_at' => null,
        'integrator_client_id' => null,
        'integrator_return_url' => null,
    ], $overrides);
}

it('returns 404 when document not found', function () {
    $controller = buildDocumentsController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };

    $controller->callGetDocument('non-existent-uuid');

    expect($controller->statusCode)->toBe(404);
    expect($controller->json['error'])->toContain('document_not_found');
});

it('returns 403 when user email is null', function () {
    $controller = buildDocumentsController();
    $controller->useremail = null;
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'uuid' => 'test-uuid', 'owner_email' => 'owner@example.com', 'owner_customer_role_id' => 10];
        }
    };

    $controller->callGetDocument('test-uuid');

    expect($controller->statusCode)->toBe(403);
    expect($controller->json['error'])->toContain('email_required');
});

it('returns 403 when user is not document owner', function () {
    $controller = buildDocumentsController(['email' => 'other@example.com', 'role_id' => 99]);
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'uuid' => 'test-uuid', 'owner_email' => 'owner@example.com', 'owner_customer_role_id' => 10];
        }
    };

    $controller->callGetDocument('test-uuid');

    expect($controller->statusCode)->toBe(403);
    expect($controller->json['error'])->toContain('document_owner_required');
});

it('returns 404 when visibility denies access', function () {
    $controller = buildDocumentsController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return ['document_id' => 1, 'uuid' => 'test-uuid', 'owner_email' => 'owner@example.com', 'owner_customer_role_id' => 10];
        }
    };
    $controller->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };

    $controller->callGetDocument('test-uuid');

    expect($controller->statusCode)->toBe(404);
    expect($controller->json['error'])->toContain('document_not_found');
});

it('returns data with document_group_uuid null and member_count 1 for ungrouped document', function () {
    $controller = buildDocumentsController();
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return documentFixture();
        }
    };

    $controller->callGetDocument('test-uuid');

    expect($controller->statusCode)->toBe(200);
    expect($controller->json['data']['document_group_uuid'])->toBeNull();
    expect($controller->json['data']['member_count'])->toBe(1);
    expect($controller->json['data']['current_document_uuid'])->toBe('test-uuid');
});

it('grants access via canActAsDocumentOwner role match', function () {
    $controller = buildDocumentsController(['email' => 'colleague@example.com', 'role_id' => 10]);
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {
            return documentFixture();
        }
    };

    $controller->callGetDocument('test-uuid');

    expect($controller->statusCode)->toBe(200);
    expect($controller->json['data']['document_group_uuid'])->toBeNull();
});
