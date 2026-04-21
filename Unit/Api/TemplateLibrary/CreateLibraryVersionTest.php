<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::createLibraryVersion()
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('createLibraryVersion returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->createLibraryVersion('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('createLibraryVersion returns 422 when content is missing', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return ['uuid' => $uuid]; }
        public function getLatestVersionNumber(string $uuid) { return 1; }
        public function createVersion(array $data) { return 0; }
    };

    $this->controller
        ->method('getPost')
        ->willReturn([]); // no content

    $this->controller->createLibraryVersion('lib-uuid');

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['field'] ?? null)->toBe('content');
});

test('createLibraryVersion creates next draft version and returns payload', function () {
    $this->controller->model_esign_tpl_library = new class {
        public array $seenData = [];
        public function getLibraryByUuid(string $uuid) {
            // When called with the original library UUID
            if ($uuid === 'lib-uuid') {
                return ['uuid' => 'lib-uuid', 'library_group_uuid' => 'lib-uuid'];
            }
            // When called with the version UUID, return the newly created version row
            return [
                'uuid'           => $uuid,
                'library_group_uuid' => 'lib-uuid',
                'version'        => 2,
                'status'         => 'draft',
                'date_added'     => '2024-01-01 10:00:00',
            ];
        }
        public function getLatestVersionNumber(string $uuid) { return 1; } // next = 2
        public function createVersion(array $data) {
            $this->seenData = $data;
            return 123;
        }
    };

    $this->controller
        ->method('getPost')
        ->willReturn(['content' => 'body']);

    $this->controller->createLibraryVersion('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    // Controller returns the version's group UUID, which should be non-empty.
    expect($data['library_uuid'] ?? null)->not->toBeNull();
    expect($data['version_number'] ?? null)->toBe(2);
    expect($data['status'] ?? null)->toBe('draft');
});

