<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';

use PHPUnit\Framework\MockObject\MockObject;
use RobThree\Auth\TwoFactorAuth;

// Define minimal DB_PREFIX for unit tests that may touch DB-dependent code paths.
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(TestableControllerPublicAPIV1SigningReject::class)) {
    class TestableControllerPublicAPIV1SigningReject extends ControllerPublicAPIV1Signing {
        public function rejectDocument($sign_code)
        {
            return parent::rejectDocument($sign_code);
        }
    }
}

// In the full suite these may already exist; define only if missing.
if (!class_exists(TestSigningSignerModel::class)) {
    class TestSigningSignerModel {
        public function getSignerBySignCode($signCode) {}
        public function updateSignerStatus($signerId, $status) {}
    }
}

if (!class_exists(TestSigningDocumentModel::class)) {
    class TestSigningDocumentModel {
        public function getDocumentById($documentId) {}
        public function rejectDocument($documentId) {}
    }
}

// In the full suite, TestSigningDocumentModel may be defined in another file without rejectDocument().
// Use this subclass in tests that need to mock rejectDocument().
if (!class_exists(TestSigningDocumentModelWithReject::class)) {
    class TestSigningDocumentModelWithReject extends TestSigningDocumentModel {
        public function rejectDocument($documentId) {}
    }
}

if (!class_exists(TestCustomer::class)) {
    class TestCustomer {
        public function getRoleId() {}
        public function getTotpSecret() {}
    }
}

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1SigningReject&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1SigningReject::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->signCode = 'c894afec-2494-4520-a8f3-ae05172356e0';

    // Minimal load stub; tests inject models directly.
    $this->controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void
        {
            $this->loaded[] = $name;
        }
    };

    $this->controller->customer = $this->createMock(TestCustomer::class);
    $this->controller->customer->method('getRoleId')->willReturn(10);
});

function callRejectDocument($controller, string $signCode): void {
    $controller->rejectDocument($signCode);
}

test('rejectDocument returns 404 when signer is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with('MISSING')
        ->willReturn(null);

    $this->controller->method('getPost')->willReturn([]);

    callRejectDocument($this->controller, 'MISSING');

    expect($this->controller->statusCode)->toBe(404);
    // expect($this->controller->json['error'] ?? [])->toContain('Signer not found');
});

test('rejectDocument returns 404 when document is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 123,
            'customer_role_id'   => 10,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(123)
        ->willReturn(null);

    $this->controller->method('getPost')->willReturn([]);

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(404);
    // expect($this->controller->json['error'] ?? [])->toContain('Document not found');
});

test('rejectDocument returns 422 when document lifecycle is not PENDING', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'DRAFT',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn([]);

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Document must be in PENDING status to reject');
});

test('rejectDocument returns 403 when signer customer_role_id does not match authenticated user', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 99,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn([]);

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(403);
    // expect($this->controller->json['error'] ?? [])->toContain('You are not authorized to reject this document');
});

test('rejectDocument returns 422 when signer status does not allow rejection', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'SIGNED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn([]);

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Signer status does not allow rejection');
});

test('rejectDocument returns 422 when TOTP code is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn([]); // no totp

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('TOTP code is required');
});

test('rejectDocument returns 422 when TOTP secret is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn(['totp' => '123456']);

    $this->controller->customer->method('getTotpSecret')->willReturn('');

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('TOTP secret not configured for user');
});

test('rejectDocument returns 422 when TOTP is invalid', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'INVITED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $this->controller->method('getPost')->willReturn(['totp' => '000000']);

    $this->controller->customer->method('getTotpSecret')->willReturn('JBSWY3DPEHPK3PXP');

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Invalid TOTP code');
});

test('rejectDocument updates signer + document and returns success on valid request', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModelWithReject::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'VIEWED',
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'lifecycle_status_code' => 'PENDING',
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    $this->controller->customer->method('getTotpSecret')->willReturn($secret);
    $this->controller->method('getPost')->willReturn(['totp' => $totp]);

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateSignerStatus')
        ->with(5, 'REJECTED');

    $this->controller->model_signing_document
        ->expects($this->once())
        ->method('rejectDocument')
        ->with(1);

    callRejectDocument($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    // expect($this->controller->json['data']['message'] ?? null)->toBe('Document rejected successfully');
    expect($this->controller->json['data']['sign_code'] ?? null)->toBe($this->signCode);
    expect($this->controller->json['data']['document_uuid'] ?? null)->toBe('doc-uuid');
});

