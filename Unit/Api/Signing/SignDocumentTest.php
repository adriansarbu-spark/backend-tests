<?php

declare(strict_types=1);


// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';
require_once __DIR__ . '/_support/SigningTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;
use RobThree\Auth\TwoFactorAuth;

/**
 * Unit tests for ControllerPublicAPIV1Signing::signDocument()
 *
 * These tests cover the validation and authorization branches (1–6 in the
 * testing strategy) without exercising the heavy DocumentSigner / filesystem
 * logic.
 */

beforeEach(function () {
    $this->originalTimezone = date_default_timezone_get();
    selectWritableSigningUploadTimezone();

    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1Signing&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost', 'createDocumentSigner', 'getUploadRoot'])
        ->getMock();

    $this->controller
        ->method('getUploadRoot')
        ->willReturn(rtrim(DIR_UPLOAD, '/\\') . '/testing');

    $this->controller->json = [];
    $this->controller->statusCode = null;

    // Shared fake file code used in document rows.
    $this->fileCode = '6daec775-4139-4666-85be-cc8e10da732a';
    // Shared UUID-style sign_code used across tests for readability.
    $this->signCode = 'c894afec-2494-4520-a8f3-ae05172356e0';


    // Minimal load stub; tests inject models directly.
    $this->controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void
        {
            $this->loaded[] = $name;
        }
    };

    // Fake API request object with controllable request/headers content.
    $this->controller->apiRequest = new class {
        public object $request;
        public object $headers;

        public function __construct()
        {
            $this->request = new class {
                public function all(): array
                {
                    return [];
                }
            };
            $this->headers = new class {
                public function get(string $name): string
                {
                    return 'text/plain';
                }
            };
        }

        public function getContent(): string
        {
            return '';
        }
    };

    $this->controller->customer = $this->createMock(TestCustomerWithIds::class);
    $this->controller->customer
        ->method('getRoleId')
        ->willReturn(10);
});

afterEach(function () {
    if (isset($this->originalTimezone) && is_string($this->originalTimezone) && $this->originalTimezone !== '') {
        date_default_timezone_set($this->originalTimezone);
    }
});

function selectWritableSigningUploadTimezone(): void
{
    $uploadRoot = rtrim(DIR_UPLOAD, '/');
    $isolatedRoot = $uploadRoot . '/testing';
    if (!is_dir($isolatedRoot)) {
        @mkdir($isolatedRoot, 0777, true);
    }

    $candidates = [
        date_default_timezone_get(),
        'Pacific/Kiritimati',
        'Etc/GMT+12',
    ];

    foreach ($candidates as $timezone) {
        date_default_timezone_set($timezone);
        $dateFolder = date('Y-m-d');
        $isolatedDateDir = $isolatedRoot . '/' . $dateFolder;
        if (!is_dir($isolatedDateDir) && !@mkdir($isolatedDateDir, 0777, true)) {
            continue;
        }

        if (!is_writable($isolatedDateDir)) {
            continue;
        }

        $targetDir = $uploadRoot . '/' . $dateFolder;

        if (is_link($targetDir)) {
            $linkTarget = readlink($targetDir);
            if ($linkTarget === $isolatedDateDir) {
                return;
            }
            @unlink($targetDir);
        }

        if (!file_exists($targetDir) && @symlink($isolatedDateDir, $targetDir)) {
            return;
        }

        if (is_dir($targetDir) && is_writable($targetDir)) {
            return;
        }

        if (!is_dir($targetDir) && @mkdir($targetDir, 0777, true) && is_writable($targetDir)) {
            return;
        }
    }
}

test('signDocument rejects owner when document status is not DRAFT or PENDING', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'COMPLETED',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Document must be in DRAFT or PENDING status to sign');
});

test('signDocument rejects non-owner when document is not PENDING', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 20,
            'status_code'        => 'INVITED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Document must be in PENDING status to sign');
});

test('signDocument rejects non-owner when customer_role_id does not match signer', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 20,
            'status_code'        => 'INVITED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(403);
    // expect($this->controller->json['error'] ?? [])->toContain('You are not authorized to sign this document');
});

test('signDocument rejects owner when signer status is not allowed', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'SIGNED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Signer status does not allow signing');
});

test('signDocument rejects non-owner when signer status is not allowed', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 0,
            'status_code'        => 'SIGNED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Signer status does not allow signing');
});

test('signDocument rejects when it is not the signer turn (no next signer)', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithNext::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 0,
            'status_code'        => 'INVITED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller->model_signing_signer
        ->method('getNextSigner')
        ->with(1)
        ->willReturn(null);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('It is not your turn to sign');
});

test('signDocument rejects when next signer group does not match', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithNext::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 0,
            'status_code'        => 'INVITED',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller->model_signing_signer
        ->method('getNextSigner')
        ->with(1)
        ->willReturn([
            'signing_order'  => 2,
            'parallel_group' => 1,
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('It is not your turn to sign');
});

test('signDocument rejects when TOTP code is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn([]); // no 'totp'

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('TOTP code is required');
});

test('signDocument rejects when TOTP secret is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller
        ->method('getPost')
        ->willReturn(['totp' => '123456']);

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn('');

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('TOTP secret not configured for user');
});

test('signDocument returns 404 when signer is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with('MISSING')
        ->willReturn(null);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument('MISSING');

    expect($this->controller->statusCode)->toBe(404);
    // expect($this->controller->json['error'] ?? [])->toContain('Signer not found');
});

test('signDocument returns 404 when document is not found', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 123,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(123)
        ->willReturn(null);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(404);
    // expect($this->controller->json['error'] ?? [])->toContain('Document not found');
});

test('signDocument rejects when signature_payload is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    // Raw POST contains only a valid TOTP, no signature_payload.
    $this->controller->apiRequest = new class($totp) {
        private string $totp;
        public function __construct(string $totp)
        {
            $this->totp = $totp;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp) {
                    private string $totp;
                    public function __construct(string $totp) { $this->totp = $totp; }
                    public function all(): array { return ['totp' => $this->totp]; }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    $this->controller
        ->method('getPost')
        ->willReturn([]); // no fallback signature_payload

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('signature_payload is required and must be an array');
});

test('signDocument rejects when signature_payload JSON is invalid', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    // Raw POST has invalid JSON string for signature_payload.
    $this->controller->apiRequest = new class($totp) {
        private string $totp;
        public function __construct(string $totp)
        {
            $this->totp = $totp;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp) {
                    private string $totp;
                    public function __construct(string $totp) { $this->totp = $totp; }
                    public function all(): array {
                        return [
                            'totp'             => $this->totp,
                            'signature_payload'=> '{invalid-json}',
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Invalid signature_payload JSON format');
});

test('signDocument rejects when certificate is missing or not valid', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    // Valid new-format signature payload JSON.
    $payload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $payload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload)
        {
            $this->totp = $totp;
            $this->payload = $payload;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) {
                        $this->totp = $totp;
                        $this->payload = $payload;
                    }
                    public function all(): array {
                        return [
                            'totp'              => $this->totp,
                            'signature_payload' => $this->payload,
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    // Simulate missing/invalid certificate.
    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return null;
        }
    };

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Valid certificate required for signing');
});

test('signDocument returns 422 when current file upload record is missing', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
            'email'              => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    $payload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $payload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload)
        {
            $this->totp = $totp;
            $this->payload = $payload;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) {
                        $this->totp = $totp;
                        $this->payload = $payload;
                    }
                    public function all(): array {
                        return [
                            'totp'              => $this->totp,
                            'signature_payload' => $this->payload,
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    $this->controller->customer
        ->method('getId')
        ->willReturn(123);

    $this->controller->customer
        ->method('getCompanyId')
        ->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'      => 'valid',
                'sam_last_keypair' => 'kp',
                'sam_signer_jws'   => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'      => 'sub',
                'cert_pem'         => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return null;
        }
    };

    $this->controller
        ->method('createDocumentSigner')
        ->willReturn(new class {
            public function sign()
            {
                return base64_encode('%PDF-1.4 test');
            }
        });

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    // expect($this->controller->json['error'] ?? [])->toContain('Current file not found');
});

test('signDocument returns 500 when DocumentSigner::sign returns false', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
            'email'              => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    $payload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $payload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload)
        {
            $this->totp = $totp;
            $this->payload = $payload;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) {
                        $this->totp = $totp;
                        $this->payload = $payload;
                    }
                    public function all(): array {
                        return [
                            'totp'              => $this->totp,
                            'signature_payload' => $this->payload,
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    $this->controller->customer
        ->method('getId')
        ->willReturn(123);

    $this->controller->customer
        ->method('getCompanyId')
        ->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'      => 'valid',
                'sam_last_keypair' => 'kp',
                'sam_signer_jws'   => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'      => 'sub',
                'cert_pem'         => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class ($this) {
        private $testCase;
        public function __construct($testCase)
        {
            $this->testCase = $testCase;
        }
        public function getUploadByCodeForSigning($code)
        {
            return [
                'relative_path' => 'unitTests',
                'filename'      => 'original.pdf',
                'name'          => 'Original.pdf',
            ];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller
        ->method('createDocumentSigner')
        ->willReturn(new class {
            public function sign()
            {
                return false;
            }
        });

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors[0] ?? '')->toStartWith('Signing failed:');
});

test('signDocument returns 500 when DocumentSigner::sign throws', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModel::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
            'email'              => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    $payload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $payload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload)
        {
            $this->totp = $totp;
            $this->payload = $payload;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) {
                        $this->totp = $totp;
                        $this->payload = $payload;
                    }
                    public function all(): array {
                        return [
                            'totp'              => $this->totp,
                            'signature_payload' => $this->payload,
                        ];
                    }
                };
            }
            if ($name === 'headers') {
                return new class {
                    public function get(string $name): string { return 'text/plain'; }
                };
            }
            return null;
        }
    };

    $this->controller->customer
        ->method('getTotpSecret')
        ->willReturn($secret);

    $this->controller->customer
        ->method('getId')
        ->willReturn(123);

    $this->controller->customer
        ->method('getCompanyId')
        ->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'      => 'valid',
                'sam_last_keypair' => 'kp',
                'sam_signer_jws'   => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'      => 'sub',
                'cert_pem'         => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class ($this) {
        private $testCase;
        public function __construct($testCase)
        {
            $this->testCase = $testCase;
        }
        public function getUploadByCodeForSigning($code)
        {
            return [
                'relative_path' => 'unitTests',
                'filename'      => 'original.pdf',
                'name'          => 'Original.pdf',
            ];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller
        ->method('createDocumentSigner')
        ->willReturn(new class {
            public function sign()
            {
                throw new Exception('Test DocumentSigner failure');
            }
        });

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors[0] ?? '')->toStartWith('Signing failed:');
});

test('signDocument performs post-signing updates (new file, status, annotations, visibility role)', function () {
    selectWritableSigningUploadTimezone();

    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelFull::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModelWithUpdates::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
            'email'              => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);

    $rawPayload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $rawPayload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload)
        {
            $this->totp = $totp;
            $this->payload = $payload;
        }
        public function getContent(): string
        {
            return '';
        }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
                    public function all(): array { return ['totp' => $this->totp, 'signature_payload' => $this->payload]; }
                };
            }
            if ($name === 'headers') {
                return new class { public function get(string $name): string { return 'text/plain'; } };
            }
            return null;
        }
    };

    $this->controller->customer->method('getTotpSecret')->willReturn($secret);
    $this->controller->customer->method('getId')->willReturn(123);
    $this->controller->customer->method('getCompanyId')->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'        => 'valid',
                'sam_last_keypair'   => 'kp',
                'sam_signer_jws'     => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'        => 'sub',
                'cert_pem'           => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return [
                'relative_path' => 'unitTests',
                'filename'      => 'original.pdf',
                'name'          => 'Original.pdf',
            ];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller->method('createDocumentSigner')->willReturn(new class {
        public function sign()
        {
            return base64_encode('%PDF-1.4 test');
        }
    });

    $this->controller->model_signing_document
        ->expects($this->once())
        ->method('updateCurrentFileCode')
        ->with(1, 'new-code');

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateSignerStatus')
        ->with(5, 'SIGNED');

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateSignatureResultAnnotations')
        ->with(5, $this->callback(function ($payload) {
            if (!is_array($payload) || count($payload) !== 1) {
                return false;
            }
            $sig = $payload[0];
            return ($sig['pageNumber'] ?? null) === 0
                && isset($sig['bounds']['left'], $sig['bounds']['top'], $sig['bounds']['width'], $sig['bounds']['height'])
                && (float)$sig['bounds']['left'] === 100.0
                && (float)$sig['bounds']['top'] === 200.0
                && (float)$sig['bounds']['width'] === 150.0
                && (float)$sig['bounds']['height'] === 50.0;
        }));

    $this->controller->model_signing_visibility
        ->expects($this->once())
        ->method('updateCustomerRoleId')
        ->with(1, 'owner@example.com', 10);

    // Keep lifecycle neutral for this test.
    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(false);
    $this->controller->model_signing_signer->method('areAllSignersSigned')->willReturn(false);

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBeNull();
});

test('signDocument invites next group, sends draft to pending, and creates visibility', function () {
    selectWritableSigningUploadTimezone();

    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelFullWithInvite::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModelWithUpdates::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->willReturn([
            'document_id'        => 1,
            'customer_role_id'   => 10,
            'status_code'        => 'DRAFT',
            'signing_order'      => 1,
            'parallel_group'     => 1,
            'document_signer_id' => 5,
            'email'              => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
            'current_file_code'     => $this->fileCode,
            'uuid'                  => 'doc-uuid',
        ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);
    $rawPayload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $rawPayload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
        public function getContent(): string { return ''; }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
                    public function all(): array { return ['totp' => $this->totp, 'signature_payload' => $this->payload]; }
                };
            }
            if ($name === 'headers') {
                return new class { public function get(string $name): string { return 'text/plain'; } };
            }
            return null;
        }
    };

    $this->controller->customer->method('getTotpSecret')->willReturn($secret);
    $this->controller->customer->method('getId')->willReturn(123);
    $this->controller->customer->method('getCompanyId')->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'        => 'valid',
                'sam_last_keypair'   => 'kp',
                'sam_signer_jws'     => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'        => 'sub',
                'cert_pem'           => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return ['relative_path' => 'unitTests', 'filename' => 'original.pdf', 'name' => 'Original.pdf'];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller->method('createDocumentSigner')->willReturn(new class {
        public function sign() { return base64_encode('%PDF-1.4 test'); }
    });

    // Neutralize other updates.
    $this->controller->model_signing_document->method('updateCurrentFileCode');
    $this->controller->model_signing_signer->method('updateSignerStatus');
    $this->controller->model_signing_signer->method('updateSignatureResultAnnotations');
    $this->controller->model_signing_visibility->method('updateCustomerRoleId');

    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(true);
    $this->controller->model_signing_signer->method('getNextGroupToInvite')->willReturn([
        'signing_order'  => 2,
        'parallel_group' => 1,
    ]);

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('inviteGroup')
        ->with(1, 2, 1);

    $this->controller->model_signing_document
        ->expects($this->once())
        ->method('sendDocument')
        ->with(1, $this->isType('string'));

    $this->controller->model_signing_signer->method('getSignersByDocumentId')->willReturn([
        ['signing_order' => 2, 'parallel_group' => 1, 'email' => 'a@example.com', 'customer_role_id' => 7],
        ['signing_order' => 2, 'parallel_group' => 1, 'email' => 'b@example.com', 'customer_role_id' => 0],
        ['signing_order' => 3, 'parallel_group' => 1, 'email' => 'c@example.com', 'customer_role_id' => 9],
    ]);

    $this->controller->model_signing_visibility
        ->expects($this->exactly(2))
        ->method('createVisibility')
        ->with(
            1,
            $this->logicalOr('a@example.com', 'b@example.com'),
            $this->anything()
        );

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBeNull();
});

test('signDocument completes document when no next group and all signers are signed', function () {
    selectWritableSigningUploadTimezone();

    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelFull::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModelWithUpdates::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer->method('getSignerBySignCode')->willReturn([
        'document_id'        => 1,
        'customer_role_id'   => 0,
        'status_code'        => 'DRAFT',
        'signing_order'      => 1,
        'parallel_group'     => 1,
        'document_signer_id' => 5,
        'email'              => 'owner@example.com',
    ]);

    $this->controller->model_signing_document->method('getDocumentById')->willReturn([
        'document_id'           => 1,
        'owner_customer_role_id'=> 10,
        'lifecycle_status_code' => 'PENDING',
        'current_file_code'     => $this->fileCode,
        'uuid'                  => 'doc-uuid',
    ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);
    $rawPayload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $rawPayload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
        public function getContent(): string { return ''; }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
                    public function all(): array { return ['totp' => $this->totp, 'signature_payload' => $this->payload]; }
                };
            }
            if ($name === 'headers') {
                return new class { public function get(string $name): string { return 'text/plain'; } };
            }
            return null;
        }
    };

    $this->controller->customer->method('getTotpSecret')->willReturn($secret);
    $this->controller->customer->method('getId')->willReturn(123);
    $this->controller->customer->method('getCompanyId')->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'        => 'valid',
                'sam_last_keypair'   => 'kp',
                'sam_signer_jws'     => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'        => 'sub',
                'cert_pem'           => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return ['relative_path' => 'unitTests', 'filename' => 'original.pdf', 'name' => 'Original.pdf'];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller->method('createDocumentSigner')->willReturn(new class {
        public function sign() { return base64_encode('%PDF-1.4 test'); }
    });

    // Keep post-signing updates neutral for this test.
    $this->controller->model_signing_document->method('updateCurrentFileCode');
    $this->controller->model_signing_signer->method('updateSignerStatus');
    $this->controller->model_signing_signer->method('updateSignatureResultAnnotations');
    $this->controller->model_signing_visibility->method('updateCustomerRoleId');

    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(true);
    $this->controller->model_signing_signer->method('getNextGroupToInvite')->willReturn(null);
    $this->controller->model_signing_signer->method('areAllSignersSigned')->willReturn(true);

    $this->controller->model_signing_document
        ->expects($this->once())
        ->method('completeDocument')
        ->with(1);

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBeNull();
});

test('signDocument completes document when group not complete but all signers are signed', function () {
    selectWritableSigningUploadTimezone();

    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelFullWithNext::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModelWithUpdates::class);
    $this->controller->model_signing_visibility = $this->createMock(TestSigningVisibilityModel::class);

    $this->controller->model_signing_signer->method('getSignerBySignCode')->willReturn([
        'document_id'        => 1,
        'customer_role_id'   => 0,
        'status_code'        => 'INVITED',
        'signing_order'      => 1,
        'parallel_group'     => 1,
        'document_signer_id' => 5,
        'email'              => 'owner@example.com',
    ]);

    $this->controller->model_signing_document->method('getDocumentById')->willReturn([
        'document_id'           => 1,
        'owner_customer_role_id'=> 10,
        'lifecycle_status_code' => 'PENDING',
        'current_file_code'     => $this->fileCode,
        'uuid'                  => 'doc-uuid',
    ]);

    $secret = 'JBSWY3DPEHPK3PXP';
    $tfa = new TwoFactorAuth();
    $totp = $tfa->getCode($secret);
    $rawPayload = json_encode([[
        'page'   => 1,
        'x'      => 100,
        'y'      => 200,
        'width'  => 150,
        'height' => 50,
        'data'   => 'sig',
    ]]);

    $this->controller->apiRequest = new class($totp, $rawPayload) {
        private string $totp;
        private string $payload;
        public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
        public function getContent(): string { return ''; }
        public function __get($name)
        {
            if ($name === 'request') {
                return new class($this->totp, $this->payload) {
                    private string $totp;
                    private string $payload;
                    public function __construct(string $totp, string $payload) { $this->totp = $totp; $this->payload = $payload; }
                    public function all(): array { return ['totp' => $this->totp, 'signature_payload' => $this->payload]; }
                };
            }
            if ($name === 'headers') {
                return new class { public function get(string $name): string { return 'text/plain'; } };
            }
            return null;
        }
    };

    $this->controller->customer->method('getTotpSecret')->willReturn($secret);
    $this->controller->customer->method('getId')->willReturn(123);
    $this->controller->customer->method('getCompanyId')->willReturn(456);

    $this->controller->model_certificate_certificate = new class {
        public function getCustomerCertificate($customerId)
        {
            return [
                'cert_status'        => 'valid',
                'sam_last_keypair'   => 'kp',
                'sam_signer_jws'     => 'jws',
                'sam_signing_key_id' => 'key',
                'sam_subject'        => 'sub',
                'cert_pem'           => 'pem',
            ];
        }
    };

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return ['relative_path' => 'unitTests', 'filename' => 'original.pdf', 'name' => 'Original.pdf'];
        }
        public function addUpload($name, $filename, $a, $b, $c, $customerId, $companyId, $customerRoleId, $folder)
        {
            return ['code' => 'new-code'];
        }
    };

    $this->controller->method('createDocumentSigner')->willReturn(new class {
        public function sign() { return base64_encode('%PDF-1.4 test'); }
    });

    // Ensure turn-based check passes (non-owner or owner not in DRAFT).
    $this->controller->model_signing_signer->method('getNextSigner')->willReturn([
        'signing_order'  => 1,
        'parallel_group' => 1,
    ]);

    // Keep post-signing updates neutral for this test.
    $this->controller->model_signing_document->method('updateCurrentFileCode');
    $this->controller->model_signing_signer->method('updateSignerStatus');
    $this->controller->model_signing_signer->method('updateSignatureResultAnnotations');
    $this->controller->model_signing_visibility->method('updateCustomerRoleId');

    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(false);
    $this->controller->model_signing_signer->method('areAllSignersSigned')->willReturn(true);

    $this->controller->model_signing_document
        ->expects($this->once())
        ->method('completeDocument')
        ->with(1);

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBeNull();
});

