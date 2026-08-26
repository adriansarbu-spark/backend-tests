<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

it('rejects invalid scope', function () {
    $controller = buildDocumentsController(['request_get' => ['scope' => 'bogus']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_scope');
});

it('accepts all valid scopes', function ($scope) {
    $controller = buildDocumentsController(['request_get' => ['scope' => $scope]]);
    $controller->model_signing_document = new class extends TestDocumentsDocumentModel {
        public function listDocuments($params) { return ['documents' => [], 'total' => 0]; }
    };

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(200);
})->with([
    'needs_my_signature',
    'signed_by_me',
    'rejected_by_me',
    'waiting_for_others',
    'drafts',
    'completed_sent',
    'cancelled_or_rejected_sent',
    'all_related',
]);

it('rejects invalid sort field', function () {
    $controller = buildDocumentsController(['request_get' => ['sort' => 'invalid_field']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_sort_field');
});

it('rejects invalid order', function () {
    $controller = buildDocumentsController(['request_get' => ['order' => 'SIDEWAYS']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_order');
});

it('rejects invalid lifecycle filter', function () {
    $controller = buildDocumentsController(['request_get' => ['lifecycle' => 'INVALID']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_lifecycle');
});

it('rejects invalid envelope_ownership', function () {
    $controller = buildDocumentsController(['request_get' => ['envelope_ownership' => 'invalid']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_envelope_ownership');
});

it('rejects action_required with non-all_related scope', function () {
    $controller = buildDocumentsController(['request_get' => ['scope' => 'drafts', 'action_required' => 'true']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_action_required_scope');
});

it('rejects invalid action_required value', function () {
    $controller = buildDocumentsController(['request_get' => ['scope' => 'all_related', 'action_required' => 'bogus']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_action_required');
});

it('rejects invalid verification_status', function () {
    $controller = buildDocumentsController(['request_get' => ['verification_status' => 'bogus']]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_verification_status');
});

it('rejects signers filter with more than 50 entries', function () {
    $emails = implode(',', array_map(fn($i) => "user{$i}@example.com", range(1, 51)));
    $controller = buildDocumentsController(['request_get' => ['signers' => $emails]]);

    $controller->callListDocuments();

    expect($controller->statusCode)->toBe(422);
    expect($controller->json['error'])->toContain('invalid_signers');
});
