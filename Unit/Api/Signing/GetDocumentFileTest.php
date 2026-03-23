<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';
require_once __DIR__ . '/_support/SigningTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1Signing::getDocumentFile()
 *
 * These tests exercise the authorization, visibility, lifecycle and
 * signer-status rules for downloading a document file via sign_code.
 * They stop before filesystem / DB interaction by relying on the early
 * returns in getDocumentFile().
 */

beforeEach(function () {
    $registry = new Registry();

    // Shared UUID-style sign_code used across tests for readability.
    $this->signCode = 'c894afec-2494-4520-a8f3-ae05172356e0';

    // Shared fake file code used in document rows.
    $this->fileCode = '6daec775-4139-4666-85be-cc8e10da732a';

    /** @var TestableControllerPublicAPIV1Signing&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'streamPdfFile'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    // Minimal load stub; tests inject models directly.
    $this->controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void
        {
            $this->loaded[] = $name;
        }
    };

    $this->controller->customer = $this->createMock(TestCustomer::class);
    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(10);
});

test('getDocumentFile returns 404 when signer is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with('MISSING')
        ->willReturn(null);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile('MISSING');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->toContain('Signer not found');
});

test('getDocumentFile returns 404 when document is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id' => 123,
            'email'       => 'user@example.com',
            'status_code' => 'INVITED',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(123)
        ->willReturn(null);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile('ANY');

    expect($this->controller->statusCode)->toBe(404);
});

test('getDocumentFile hides document with 404 when visibility denies access', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->with(1, 10, 'user@example.com')
        ->willReturn(false);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(404);
});

test('getDocumentFile returns 422 when document is cancelled', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'CANCELLED',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
});

test('getDocumentFile returns 422 when document is expired', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'expires_at'            => '2000-01-01 00:00:00',
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
});

test('getDocumentFile returns 422 when document status is not allowed', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            // Keep this intentionally outside the controller's allowed set.
            'lifecycle_status_code' => 'ARCHIVED',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
});

test('getDocumentFile returns 422 when signer status does not allow viewing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            // Keep this intentionally outside the controller's allowed set.
            'status_code'        => 'DECLINED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
});

test('getDocumentFile returns 404 when file is missing on disk', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    // Stub DB upload lookup to point to a non-existing path.
    $this->controller->db = new class {
        public function query(string $sql)
        {
            return (object)[
                'row' => [
                    'relative_path' => 'nonexistent-folder',
                    'filename'      => 'missing-file.pdf',
                    'name'          => 'missing-file.pdf',
                ],
            ];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }
    };

    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->toContain('File not found on disk');
});

test('getDocumentFile streams PDF when file exists', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'email'              => 'user@example.com',
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    // When signer is INVITED, status should be updated to VIEWED on successful file access.
    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateSignerStatus')
        ->with(5, 'VIEWED');

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'expires_at'            => null,
            'owner_customer_role_id'=> 10,
            'current_file_code'     => $this->fileCode,
        ]);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    // Create a real temporary PDF file under DIR_UPLOAD so file_exists() passes.
    $relativePath = 'unit-test-signing';
    $folderPath = DIR_UPLOAD . '/' . $relativePath;
    if (!is_dir($folderPath)) {
        mkdir($folderPath, 0777, true);
    }
    $filename = 'document.pdf';
    $filePath = DIR_UPLOAD . '/' . $relativePath . '/' . $filename;
    file_put_contents($filePath, '%PDF-1.4 test');

    // Stub DB upload lookup to return this file.
    $this->controller->db = new class($relativePath, $filename, $this) {
        private string $relativePath;
        private string $filename;
        private $testCase;

        public function __construct(string $relativePath, string $filename, $testCase)
        {
            $this->relativePath = $relativePath;
            $this->filename = $filename;
            $this->testCase = $testCase;
        }

        public function query(string $sql)
        {
            return (object)[
                'row' => [
                    'relative_path' => $this->relativePath,
                    'filename'      => $this->filename,
                    'name'          => 'My Document.pdf',
                ],
            ];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }
    };

    // Expect streamPdfFile to be called with the resolved path and name.
    $this->controller
        ->expects($this->once())
        ->method('streamPdfFile')
        ->with($filePath, 'My Document.pdf');

    // sendResponse should not be used on the happy-path stream.
    $this->controller
        ->method('sendResponse')
        ->willReturn(null);

    $this->controller->getDocumentFile($this->signCode);
});

