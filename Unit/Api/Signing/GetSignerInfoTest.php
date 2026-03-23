<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';

// Define minimal DB_PREFIX for unit tests that hit DB-dependent code paths.
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(TestableControllerPublicAPIV1Signing::class)) {
    /**
     * Test-only subclass that exposes protected controller methods
     * as public so we can call them directly in unit tests without
     * changing the visibility on the production controller.
     */
    class TestableControllerPublicAPIV1Signing extends ControllerPublicAPIV1Signing {
        public function getSignerInfo($sign_code)
        {
            return parent::getSignerInfo($sign_code);
        }

        public function getDocumentFile($sign_code)
        {
            return parent::getDocumentFile($sign_code);
        }

        public function signDocument($sign_code)
        {
            return parent::signDocument($sign_code);
        }

        protected function streamPdfFile($file_path, $filename)
        {
            return parent::streamPdfFile($file_path, $filename);
        }
    }
}

if (!class_exists(TestSigningSignerModel::class)) {
    /**
     * Lightweight test doubles for the models and collaborators used by
     * the signing controller, giving PHPUnit concrete method signatures
     * so we can mock them without the deprecated addMethods().
     */
    class TestSigningSignerModel {
        public function getSignerBySignCode($signCode) {}
        public function getNextSigner($documentId) {}
        public function isGroupComplete($documentId, $signingOrder, $parallelGroup) {}
        public function getNextGroupToInvite($documentId, $signingOrder) {}
        public function getSignersByDocumentId($documentId) {}
        public function areAllSignersSigned($documentId) {}
        public function updateSignerStatus($signerId, $status) {}
        public function updateSignatureResultAnnotations($signerId, $annotations) {}
    }
}

if (!class_exists(TestSigningDocumentModel::class)) {
    class TestSigningDocumentModel {
        public function getDocumentById($documentId) {}
        public function updateCurrentFileCode($documentId, $fileCode) {}
        public function sendDocument($documentId, $lockToken) {}
        public function completeDocument($documentId) {}
    }
}

if (!class_exists(TestSigningVisibilityModel::class)) {
    class TestSigningVisibilityModel {
        public function isVisible($documentId, $customerRoleId, $email) {}
        public function updateCustomerRoleId($documentId, $email, $customerRoleId) {}
        public function createVisibility($documentId, $email, $customerRoleId) {}
    }
}

if (!class_exists(TestCertificateModel::class)) {
    class TestCertificateModel {
        public function getCustomerCertificate($customerId) {}
    }
}

if (!class_exists(TestUploadModel::class)) {
    class TestUploadModel {
        public function getUploadByCodeForSigning($code) {}
        public function addUpload($name, $filename, $arg1, $arg2, $arg3, $customerId, $companyId, $customerRoleId, $folder) {}
    }
}

if (!class_exists(TestCustomer::class)) {
    class TestCustomer {
        public function getRoleId() {}
        public function getTotpSecret() {}
        public function getId() {}
        public function getCompanyId() {}
    }
}

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1Signing::getSignerInfo()
 *
 * These tests isolate the authorization and visibility logic by mocking
 * all external dependencies (models, customer, etc.).
 */

// Common setup for all tests in this file. We keep the controller very light
// and replace its collaborators with simple mocks so we can exercise only the
// authorization / visibility rules in ::getSignerInfo().
// We pass a real Registry instance into the OpenCart Controller base class so
// its magic __get/__set work during tests.
beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1Signing&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    // Mock models and collaborators as simple properties that tests can override.
    $this->controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void
        {
            $this->loaded[] = $name;
        }
    };

    $this->controller->customer = $this->createMock(TestCustomer::class);
});

test('getSignerInfo returns 404 when signer is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with('MISSING')
        ->willReturn(null);

    // When no signer is found, controller should return a 404 without leaking
    // any additional information about documents.
    $this->controller->getSignerInfo('MISSING');

    expect($this->controller->statusCode)->toBe(404);
});

test('getSignerInfo returns 404 when document is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn(['document_id' => 123]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(123)
        ->willReturn(null);

    $this->controller->getSignerInfo('ANY');

    expect($this->controller->statusCode)->toBe(404);
});

test('getSignerInfo denies access when user email does not match signer email', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id' => 1,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'               => 1,
            'lifecycle_status_code'     => 'PENDING',
            'signature_request_annotations' => '[]',
            'name'                      => 'Doc',
        ]);

    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(10);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    // Authenticated user has a different email than the signer; access denied.
    $this->controller->useremail = 'other@example.com';

    $this->controller->getSignerInfo('CODE123');

    expect($this->controller->statusCode)->toBe(403);
});

test('getSignerInfo denies access when visibility hides the document', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id' => 1,
            'email'       => 'user@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'               => 1,
            'lifecycle_status_code'     => 'PENDING',
            'signature_request_annotations' => '[]',
            'name'                      => 'Doc',
        ]);

    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(20);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->with(1, 20, 'user@example.com')
        ->willReturn(false);

    $this->controller->useremail = 'user@example.com';

    $this->controller->getSignerInfo('CODE123');

    expect($this->controller->statusCode)->toBe(404);
});

test('getSignerInfo denies access when document is still in draft for signer', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id' => 1,
            'email'       => 'user@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'               => 1,
            'lifecycle_status_code'     => 'DRAFT',
            'signature_request_annotations' => '[]',
            'name'                      => 'Doc',
        ]);

    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(20);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->willReturn(true);

    $this->controller->useremail = 'user@example.com';

    $this->controller->getSignerInfo('CODE123');

    expect($this->controller->statusCode)->toBe(403);
});

test('getSignerInfo returns signer-scoped data on success', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $signCodeUUID = 'c894afec-2494-4520-a8f3-ae05172356e0';

    $signerRow = [
        'document_id'        => 1,
        'document_signer_id' => 5,
        'sign_code'          => $signCodeUUID,
        'email'              => 'user@example.com',
        'signing_order'      => 1,
        'parallel_group'     => 1,
        'signing_type'       => 'SIGNATURE',
        'status_code'        => 'INVITED',
        'invited_at'         => '2024-01-01 00:00:00',
        'viewed_at'          => null,
        'signed_at'          => null,
        'rejected_at'        => null,
    ];

    $annotations = json_encode([
        ['email' => 'user@example.com', 'field' => 'A'],
        ['email' => 'other@example.com', 'field' => 'B'],
    ]);

    $documentRow = [
        'document_id'                 => 1,
        'name'                        => 'Contract',
        'lifecycle_status_code'       => 'PENDING',
        'signature_request_annotations' => $annotations,
        'signature_level'             => 'STANDARD',
        'expires_at'                  => null,
        'completed_at'                => null,
        'cancelled_at'                => null,
        'rejected_at'                 => null,
        'date_added'                  => '2024-01-01 00:00:00',
    ];

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn($signerRow);

    // Controller iterates all signers for the document; provide explicit array
    // to avoid null foreach warnings in this success-path test.
    $this->controller->model_signing_signer
        ->method('getSignersByDocumentId')
        ->with(1)
        ->willReturn([$signerRow]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn($documentRow);

    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(99);

    $this->controller->model_signing_visibility
        ->method('isVisible')
        ->with(1, 99, 'user@example.com')
        ->willReturn(true);

    $this->controller->useremail = 'user@example.com';

    $this->controller->getSignerInfo($signCodeUUID);

    expect($this->controller->statusCode)->toBeNull(); // success path leaves it unset / 200
    $data = $this->controller->json['data'] ?? null;
    expect($data)->not->toBeNull();
    expect($data['sign_code'])->toBe($signCodeUUID);
    expect($data['name'])->toBe('Contract');
    expect($data['signature_request_annotations'])->toHaveCount(1);
    expect($data['signature_request_annotations'][0]['field'] ?? null)->toBe('A');
});

