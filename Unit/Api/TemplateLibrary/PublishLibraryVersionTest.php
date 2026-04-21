<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::publishLibraryVersion()
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

test('publishLibraryVersion returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->publishLibraryVersion('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishLibraryVersion returns 404 when version is not found or in different group', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'group-1'];
            }
            // version row missing / wrong group
            return null;
        }
    };

    $this->controller->publishLibraryVersion('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishLibraryVersion returns 422 when version is not draft', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'group-1'];
            }
            return ['uuid' => $uuid, 'library_group_uuid' => 'group-1', 'status' => 'published'];
        }
    };

    $this->controller->publishLibraryVersion('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishLibraryVersion publishes draft version and returns payload', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'group-1'];
            }
            // version row (draft) both before and after publish
            return ['uuid' => $uuid, 'library_group_uuid' => 'group-1', 'status' => 'draft', 'version' => 3];
        }
        public function publishVersion(string $uuid): bool { return true; }
    };

    $this->controller->publishLibraryVersion('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('v1');
    expect($data['library_uuid'] ?? null)->toBe('group-1');
    expect($data['status'] ?? null)->toBe('published');
    expect($data['version_number'] ?? null)->toBe(3);
});

