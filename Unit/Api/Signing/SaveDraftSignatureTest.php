<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';
require_once __DIR__ . '/_support/SigningTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1SigningDraft&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1SigningDraft::class)
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

    // Default apiRequest that is NOT json; tests override as needed.
    $this->controller->apiRequest = new class {
        public object $headers;
        public function __construct()
        {
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
});

function callSaveDraftSignature($controller, string $signCode): void {
    $ref = new ReflectionClass($controller);
    $m = $ref->getMethod('saveDraftSignature');
    $m->setAccessible(true);
    $m->invoke($controller, $signCode);
}

test('saveDraftSignature parses JSON body and treats [] as empty draft (clears when signer exists)', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);

    $this->controller->apiRequest = new class {
        public object $headers;
        public function __construct()
        {
            $this->headers = new class {
                public function get(string $name): string
                {
                    return 'application/json';
                }
            };
        }
        public function getContent(): string
        {
            return json_encode(['draft_signature' => []]);
        }
    };

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn(['document_id' => 1]);

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateDraftSignature')
        ->with($this->signCode, []);

    $this->controller->method('getPost')->willReturn([]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature cleared successfully');
});

test('saveDraftSignature falls back to getPost and decodes HTML-encoded JSON string (treated as non-empty and saved)', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    // draft_signature string that is HTML-entity encoded JSON
    $encoded = htmlspecialchars(json_encode(['signatures' => []]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateDraftSignature')
        ->with($this->signCode, ['signatures' => []]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(1)
        ->willReturn([
            'document_id'            => 1,
            'owner_customer_role_id' => 10, // owner to bypass email/lifecycle checks
            'lifecycle_status_code'  => 'DRAFT',
        ]);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => $encoded,
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature saved successfully');
});

test('saveDraftSignature treats null/empty string as empty draft and is idempotent when signer does not exist', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn(null);

    $this->controller->model_signing_signer
        ->expects($this->never())
        ->method('updateDraftSignature');

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => '',
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature cleared successfully');
});

test('saveDraftSignature returns 404 when signer is not found for non-empty draft', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn(null);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => ['anything' => 'non-empty'],
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->toContain('Signer not found');
});

test('saveDraftSignature returns 404 when document is not found for non-empty draft', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 123,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(123)
        ->willReturn(null);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => ['anything' => 'non-empty'],
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->toContain('Document not found');
});

test('saveDraftSignature allows owner to save non-empty draft regardless of email match', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->with(1)
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller->useremail = 'other@example.com';

    $draft = ['foo' => 'bar'];

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateDraftSignature')
        ->with($this->signCode, $draft);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => $draft,
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature saved successfully');
});

test('saveDraftSignature denies non-owner when user_email missing/anonymous/mismatched', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller->useremail = 'anonymous@simplifi.ro';

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => ['foo' => 'bar'],
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->toContain('Access denied. Email does not match signer email.');
});

test('saveDraftSignature denies non-owner when document is still DRAFT', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'signer@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller->useremail = 'signer@example.com';

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => ['foo' => 'bar'],
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->toContain('Document has not been sent yet. Signers can only save drafts after the document has been sent.');
});

test('saveDraftSignature treats missing draft_signature key as empty draft (idempotent clear)', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn(null);

    $this->controller->method('getPost')->willReturn([]); // no draft_signature key at all

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature cleared successfully');
});

test('saveDraftSignature returns 422 when draft_signature is not array/object', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'owner@example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 10,
            'lifecycle_status_code' => 'DRAFT',
        ]);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => 'not-json-structure',
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->toContain('draft_signature must be a valid JSON object or array');
});

test('saveDraftSignature saves non-empty draft for authorized non-owner', function () {
    $this->controller->model_signing_signer = $this->createMock(TestSigningSignerModelWithDraft::class);
    $this->controller->model_signing_document = $this->createMock(TestSigningDocumentModel::class);

    $this->controller->customer->method('getRoleId')->willReturn(20);

    $this->controller->model_signing_signer
        ->method('getSignerBySignCode')
        ->with($this->signCode)
        ->willReturn([
            'document_id' => 1,
            'email'       => 'Signer@Example.com',
        ]);

    $this->controller->model_signing_document
        ->method('getDocumentById')
        ->willReturn([
            'document_id'           => 1,
            'owner_customer_role_id'=> 99,
            'lifecycle_status_code' => 'PENDING',
        ]);

    $this->controller->useremail = ' signer@example.com ';

    $draft = ['foo' => 'bar'];

    $this->controller->model_signing_signer
        ->expects($this->once())
        ->method('updateDraftSignature')
        ->with($this->signCode, $draft);

    $this->controller->method('getPost')->willReturn([
        'draft_signature' => $draft,
    ]);

    callSaveDraftSignature($this->controller, $this->signCode);

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data']['message'] ?? null)->toBe('Draft signature saved successfully');
});

