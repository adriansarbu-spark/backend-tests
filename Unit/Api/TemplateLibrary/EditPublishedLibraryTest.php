<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::editPublishedLibrary()
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('editPublishedLibrary returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->editPublishedLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedLibrary returns 422 when no published version exists', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return ['uuid' => $uuid]; }
        public function getLatestPublishedVersion(string $uuid) { return null; }
    };

    $this->controller->editPublishedLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedLibrary returns 500 when new version cannot be created', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return ['uuid' => $uuid]; }
        public function getLatestPublishedVersion(string $uuid) { return ['uuid' => 'ver-1']; }
        public function editPublishedLibrary(string $uuid, string $newUuid) { return 0; }
    };

    $this->controller->editPublishedLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBe(500);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedLibrary creates new draft version and returns payload', function () {
    $this->controller->model_esign_tpl_library = new class {
        private $new;
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid'];
            }
            return $this->new ?? ['uuid' => $uuid, 'version' => 2, 'status' => 'draft', 'library_group_uuid' => 'group-1'];
        }
        public function getLatestPublishedVersion(string $uuid) { return ['uuid' => 'ver-1']; }
        public function editPublishedLibrary(string $uuid, string $newUuid) {
            $this->new = [
                'uuid'               => $newUuid,
                'version'            => 2,
                'status'             => 'draft',
                'library_group_uuid' => 'group-1',
            ];
            return 123;
        }
    };

    $this->controller->editPublishedLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->not->toBeNull();
    expect($data['library_uuid'] ?? null)->toBe('group-1');
    expect($data['version_number'] ?? null)->toBe(2);
    expect($data['status'] ?? null)->toBe('draft');
});

