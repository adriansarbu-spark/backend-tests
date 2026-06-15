<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/author/documents.php';
require_once __DIR__ . '/_support/AuthorDocumentsTestDoubles.php';
require_once dirname(__DIR__, 3) . '/Support/AuthorDocumentsApiHelper.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1EsignAuthorDocuments::createDocument()}.
 */

beforeEach(function () {
    $this->documentModel = new AuthorDocumentModelStub();
    $this->campaignModel = new AuthorCampaignModelStub();
});

/**
 * Prerequisites:
 * - POST body without document name.
 *
 * Steps:
 * 1. Call `createDocument()`.
 * 2. Assert **HTTP 422** `VALIDATION_ERROR` on field `name`.
 */
test('Author documents API — create without name returns 422', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'source_type' => 'scratch',
    ]);
    $c->createDocument();

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('name_required');
});

/**
 * Prerequisites:
 * - `source_type=library` without `library_uuid`.
 *
 * Steps:
 * 1. Call `createDocument()`.
 * 2. Assert **HTTP 422** on field `library_uuid`.
 */
test('Author documents API — library source without library_uuid returns 422', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'name' => 'Doc',
        'source_type' => 'library',
    ]);
    $c->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid): ?array
        {
            return null;
        }
    };
    $c->createDocument();

    expect($c->statusCode)->toBe(422);
    expect($c->json['error'] ?? [])->toContain('library_uuid_required');
});

/**
 * Prerequisites:
 * - Library uuid provided but library row is missing.
 *
 * Steps:
 * 1. Call `createDocument()`.
 * 2. Assert **HTTP 404** Library template not found.
 */
test('Author documents API — library not found returns 404', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'name' => 'Doc',
        'source_type' => 'library',
        'library_uuid' => 'lib-missing',
    ]);
    $c->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid): ?array
        {
            return null;
        }
    };
    $c->createDocument();

    expect($c->statusCode)->toBe(404);
    expect($c->json['error'] ?? [])->toContain('library_template_not_found');
});

/**
 * Prerequisites:
 * - Template belongs to another company.
 *
 * Steps:
 * 1. Call `createDocument()` with `source_type=template`.
 * 2. Assert **HTTP 403** template company mismatch.
 */
test('Author documents API — template from another company returns 403', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'name' => 'Doc',
        'source_type' => 'template',
        'template_uuid' => 'tpl-1',
    ], null, 10);
    $c->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid): ?array
        {
            return ['id' => 1, 'company_id' => 99, 'content' => '<p>x</p>'];
        }
    };
    $c->createDocument();

    expect($c->statusCode)->toBe(403);
    expect($c->json['error'] ?? [])->toContain('template_company_mismatch');
});

/**
 * Prerequisites:
 * - Scratch create succeeds in model stub.
 *
 * Steps:
 * 1. Call `createDocument()` with name and content.
 * 2. Assert **HTTP 200** draft uuid in `data`.
 */
test('Author documents API — scratch create returns draft uuid', function () {
    $c = author_docs_controller($this->documentModel, $this->campaignModel, [
        'name' => 'My draft',
        'source_type' => 'scratch',
        'content_snapshot' => AuthorDocumentsApiHelper::SAMPLE_CONTENT_WITH_SIGNATURE,
    ]);
    $c->createDocument();

    expect($c->statusCode)->toBe(200);
    expect((string)($c->json['data']['uuid'] ?? ''))->not->toBe('');
    expect($c->json['data']['status'] ?? null)->toBe('draft');
});
