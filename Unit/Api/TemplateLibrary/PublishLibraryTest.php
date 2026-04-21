<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::publishLibrary()
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

test('publishLibrary returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->publishLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
}
);

test('publishLibrary publishes draft library directly', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return [
                'uuid' => $uuid,
                'status' => 'draft',
                'version' => 1,
                'library_group_uuid' => 'group-1',
            ];
        }
        public function getLibraryVersions(string $uuid, array $params) {
            return ['versions' => [], 'total' => 0];
        }
        public function publishVersion(string $uuid): bool { return true; }
    };

    $this->controller->publishLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('lib-uuid');
    expect($data['library_uuid'] ?? null)->toBe('group-1');
    expect($data['status'] ?? null)->toBe('published');
    expect($data['version_number'] ?? null)->toBe(1);
});

test('publishLibrary returns 422 when there is no draft version to publish', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            return [
                'uuid' => $uuid,
                'status' => 'published',
            ];
        }
        public function getLibraryVersions(string $uuid, array $params) {
            return ['versions' => [], 'total' => 0];
        }
    };

    $this->controller->publishLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

