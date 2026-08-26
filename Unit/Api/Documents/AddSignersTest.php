<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

function addSignersDocFixture(array $overrides = []): array
{
    return array_merge([
        'document_id'                   => 1,
        'uuid'                          => 'abc-123',
        'lifecycle_status_code'         => 'DRAFT',
        'owner_customer_role_id'        => 10,
        'document_group_uuid'           => 'grp-1',
        'owner_company_id'             => null,
        'signature_request_annotations' => null,
    ], $overrides);
}

function addSignersApiRequest(array $data): object
{
    return new class($data) {
        public $request;
        public $headers;
        private string $content;

        public function __construct(array $data)
        {
            $this->content = json_encode($data);
            $this->request = new class($data) {
                private array $data;
                public function __construct(array $data) { $this->data = $data; }
                public function all() { return $this->data; }
            };
            $this->headers = new class {
                public function get($key) { return 'application/json'; }
            };
        }

        public function getContent() { return $this->content; }
    };
}

function addSignersController(array $ctrlOverrides = [], array $docOverrides = [], bool $returnDoc = true): TestableControllerPublicAPIV1Documents
{
    $controller = buildDocumentsController($ctrlOverrides);
    if ($returnDoc) {
        $doc = addSignersDocFixture($docOverrides);
        $controller->model_signing_document = new class($doc) extends TestDocumentsDocumentModel {
            private array $doc;
            public function __construct(array $doc) { $this->doc = $doc; }
            public function getDocumentByUuid($uuid) { return $this->doc; }
        };
    }
    return $controller;
}

// ── Document not found ──────────────────────────────────────────────

test('addSigners — document not found returns 404', function () {
    $c = buildDocumentsController();
    $c->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) { return null; }
    };
    $c->callAddSigners('no-such-uuid');

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toContain('document_not_found');
});

// ── Visibility denied ───────────────────────────────────────────────

test('addSigners — visibility denied returns 404', function () {
    $c = addSignersController();
    $c->model_signing_visibility = new class extends TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return false; }
    };
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toContain('document_not_found');
});

// ── Not owner ───────────────────────────────────────────────────────

test('addSigners — non-owner returns 403', function () {
    $c = addSignersController(['role_id' => 999]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toContain('document_owner_required');
});

// ── Not DRAFT ───────────────────────────────────────────────────────

test('addSigners — non-DRAFT document returns 422 document_not_draft', function () {
    $c = addSignersController([], ['lifecycle_status_code' => 'PENDING']);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('document_not_draft');
});

// ── Group locked ────────────────────────────────────────────────────

test('addSigners — group locked returns 422', function () {
    $c = addSignersController();
    $c->model_signing_document = new class(addSignersDocFixture()) extends TestDocumentsDocumentModel {
        private array $doc;
        public function __construct(array $doc) { $this->doc = $doc; }
        public function getDocumentByUuid($uuid) { return $this->doc; }
        public function isGroupLocked($document) { return true; }
    };
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('document_group_locked');
});

// ── Invalid signers (not array) ─────────────────────────────────────

test('addSigners — signers as string returns 422 invalid_signers_json', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest(['signers' => 'not-an-array']);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('invalid_signers_json');
});

test('addSigners — signers missing (null) returns 422 invalid_signers', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest(['other_field' => 'value']);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('invalid_signers');
});

// ── Empty signers array removes all ─────────────────────────────────

test('addSigners — empty signers array removes all and returns added_count=0', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest(['signers' => []]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['added_count'])->toBe(0)
        ->and($c->json['data']['signers'])->toBe([]);
});

// ── Duplicate email ─────────────────────────────────────────────────

test('addSigners — duplicate email returns 409 duplicate_signer_email', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest([
        'signers' => [
            ['email' => 'a@example.com'],
            ['email' => 'A@example.com'],
        ],
    ]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toContain('duplicate_signer_email');
});

// ── Duplicate customer_role_id ──────────────────────────────────────

test('addSigners — duplicate customer_role_id returns 409 duplicate_signer_customer_role', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest([
        'signers' => [
            ['email' => 'a@example.com', 'customer_role_id' => 5],
            ['email' => 'b@example.com', 'customer_role_id' => 5],
        ],
    ]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toContain('duplicate_signer_customer_role');
});

// ── Invalid invitation_type ─────────────────────────────────────────

test('addSigners — invalid invitation_type returns 422', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest([
        'signers' => [
            ['email' => 'a@example.com', 'signing_type' => 'SIGNATURE', 'invitation_type' => 'bogus'],
        ],
    ]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('invalid_invitation_type');
});

// ── Invalid signature_kind ──────────────────────────────────────────

test('addSigners — invalid signature_kind returns 422', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest([
        'signers' => [
            ['email' => 'a@example.com', 'signature_kind' => 'bogus'],
        ],
    ]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('invalid_signature_kind');
});

// ── signature_kind=simple with signing_type=NONE ────────────────────

test('addSigners — simple kind with NONE signing_type returns 422 signature_kind_requires_signature_type', function () {
    $c = addSignersController();
    $c->apiRequest = addSignersApiRequest([
        'signers' => [
            ['email' => 'a@example.com', 'signing_type' => 'NONE', 'signature_kind' => 'simple'],
        ],
    ]);
    $c->callAddSigners('abc-123');

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toContain('signature_kind_requires_signature_type');
});
