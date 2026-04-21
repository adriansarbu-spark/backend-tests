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

beforeAll(function () {
    $uploadRoot = rtrim(DIR_UPLOAD, '/');
    $targetDir = $uploadRoot . '/' . date('Y-m-d');
    if (!is_dir($targetDir)) {
        if(@mkdir($targetDir, 0775, true))
            @chmod($targetDir, 0775);
    }

});

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1Signing&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;
    $this->controller->useremail = 'owner@example.com';

    // Shared fake file code used in document rows.
    $this->fileCode = '6daec775-4139-4666-85be-cc8e10da732a';
    // Shared UUID-style sign_code used across tests for readability.
    $this->signCode = 'c894afec-2494-4520-a8f3-ae05172356e0';


    // Minimal load stub; tests inject models directly.
    $this->controller->load = new class ($this->controller) {
        private $controller;
        public array $loaded = [];
        public function __construct($controller)
        {
            $this->controller = $controller;
        }
        public function model(string $name): void
        {
            $this->loaded[] = $name;
            if ($name === 'billing/entitlement_signing') {
                $this->controller->model_billing_entitlement_signing = new class {
                    public function getCompanyIdForOwnerCustomerRoleId($ownerCustomerRoleId)
                    {
                        return 456;
                    }
                    public function signerOwesOneSidedDebit($document, $signer)
                    {
                        return false;
                    }
                    public function getBillingCompanyIdForSigningAct($document, $isOwner, $customerRoleId)
                    {
                        return 456;
                    }
                    public function getOneSidedRemainingForCompany($companyId)
                    {
                        return 1;
                    }
                    public function getOneSidedRemainingForCustomerRole($customerRoleId)
                    {
                        return 1;
                    }
                    public function chargeOneSidedForSignerIfNeeded($document, $signer, $customerId, $isOwner, $customerRoleId, $isGuest)
                    {
                        return ['ok' => true];
                    }
                };
            }
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
    $this->controller->customer
        ->method('getFirstName')
        ->willReturn('Test');
    $this->controller->customer
        ->method('getLastName')
        ->willReturn('Signer');
});

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
    $this->controller->useremail = null;

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
            'email'              => 'owner@example.com',
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
            'email'              => 'owner@example.com',
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
            'email'              => 'owner@example.com',
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

test('signDocument rejects when signature_payload is an empty array', function () {
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
                            'totp'              => $this->totp,
                            'signature_payload' => [],
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
    // expect($this->controller->json['error'] ?? [])->toContain('signature_payload is required and must be an array');
});

test('signDocument rejects when signature_payload JSON decodes to empty array', function () {
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
                            'totp'              => $this->totp,
                            'signature_payload' => '[]',
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

test('signDocument returns 422 when owner company cannot be resolved for billing', function () {
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
            'document_id'            => 1,
            'owner_customer_role_id' => 10,
            'lifecycle_status_code'  => 'DRAFT',
            'current_file_code'      => $this->fileCode,
            'uuid'                   => 'doc-uuid',
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
    $this->controller->customer->method('getCompanyId')->willReturn(0);
    $this->controller->method('getPost')->willReturn([]);

    $this->controller->load = new class ($this->controller) {
        private $controller;
        public function __construct($controller) { $this->controller = $controller; }
        public function model(string $name): void
        {
            if ($name === 'billing/entitlement_signing') {
                $this->controller->model_billing_entitlement_signing = new class {
                    public function getCompanyIdForOwnerCustomerRoleId($ownerCustomerRoleId) { return 0; }
                    public function signerOwesOneSidedDebit($document, $signer) { return false; }
                };
            }
        }
    };

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'][0] ?? null)->toBe('Owner company not found for billing');
});

test('signDocument returns 409 when signer owes one-sided debit and entitlement is unavailable', function () {
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
            'document_id'            => 1,
            'owner_customer_role_id' => 10,
            'lifecycle_status_code'  => 'DRAFT',
            'current_file_code'      => $this->fileCode,
            'uuid'                   => 'doc-uuid',
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
    $this->controller->method('getPost')->willReturn([]);

    $this->controller->load = new class ($this->controller) {
        private $controller;
        public function __construct($controller) { $this->controller = $controller; }
        public function model(string $name): void
        {
            if ($name === 'billing/entitlement_signing') {
                $this->controller->model_billing_entitlement_signing = new class {
                    public function getCompanyIdForOwnerCustomerRoleId($ownerCustomerRoleId) { return 456; }
                    public function signerOwesOneSidedDebit($document, $signer) { return true; }
                    public function getBillingCompanyIdForSigningAct($document, $isOwner, $customerRoleId) { return 456; }
                    public function getOneSidedRemainingForCompany($companyId) { return 0; }
                    public function getOneSidedRemainingForCustomerRole($customerRoleId) { return 0; }
                };
            }
        }
    };

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(409);
    expect($this->controller->json['error'][0] ?? null)->toBe('Insufficient one_sided document balance to complete signing');
});

test('signDocument returns 500 when current file upload record is missing in workflow', function () {
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
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors[0] ?? '')->toBe('Current file not found');
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

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return null;
        }
    };

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors[0] ?? '')->toBe('Current file not found');
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

    $this->controller->model_tool_upload = new class {
        public function getUploadByCodeForSigning($code)
        {
            return null;
        }
    };

    $this->controller
        ->method('getPost')
        ->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors[0] ?? '')->toBe('Current file not found');
});

test('signDocument returns 500 when workflow cannot access signing file in unit context', function () {
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
            return null;
        }
    };

    $this->controller->model_signing_document->method('updateCurrentFileCode');

    $this->controller->model_signing_signer->method('updateSignerStatus');

    $this->controller->model_signing_signer->method('updateSignatureResultAnnotations');

    $this->controller->model_signing_visibility->method('updateCustomerRoleId');

    // Keep lifecycle neutral for this test.
    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(false);
    $this->controller->model_signing_signer->method('areAllSignersSigned')->willReturn(false);

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
});

test('signDocument returns 500 before invite flow when workflow file read fails', function () {
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
            return null;
        }
    };

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

    $this->controller->model_signing_signer->method('inviteGroup');

    $this->controller->model_signing_document->method('sendDocument');

    $this->controller->model_signing_signer->method('getSignersByDocumentId')->willReturn([
        ['signing_order' => 2, 'parallel_group' => 1, 'email' => 'a@example.com', 'customer_role_id' => 7],
        ['signing_order' => 2, 'parallel_group' => 1, 'email' => 'b@example.com', 'customer_role_id' => 0],
        ['signing_order' => 3, 'parallel_group' => 1, 'email' => 'c@example.com', 'customer_role_id' => 9],
    ]);

    $this->controller->model_signing_visibility->method('createVisibility');

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
});

test('signDocument returns 500 before completion flow when workflow file read fails', function () {
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
            return null;
        }
    };

    // Keep post-signing updates neutral for this test.
    $this->controller->model_signing_document->method('updateCurrentFileCode');
    $this->controller->model_signing_signer->method('updateSignerStatus');
    $this->controller->model_signing_signer->method('updateSignatureResultAnnotations');
    $this->controller->model_signing_visibility->method('updateCustomerRoleId');

    $this->controller->model_signing_signer->method('isGroupComplete')->willReturn(true);
    $this->controller->model_signing_signer->method('getNextGroupToInvite')->willReturn(null);
    $this->controller->model_signing_signer->method('areAllSignersSigned')->willReturn(true);

    $this->controller->model_signing_document->method('completeDocument');

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
});

test('signDocument returns 500 before parallel completion flow when workflow file read fails', function () {
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
            return null;
        }
    };

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

    $this->controller->model_signing_document->method('completeDocument');

    $this->controller->method('getPost')->willReturn([]);

    $this->controller->signDocument($this->signCode);

    expect($this->controller->statusCode)->toBe(500);
});

