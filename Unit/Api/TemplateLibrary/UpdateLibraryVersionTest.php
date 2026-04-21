<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::updateLibraryVersion()
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

test('updateLibraryVersion returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->updateLibraryVersion('missing', 'v1');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('updateLibraryVersion returns 422 when version is not draft', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) {
            // First call: library row
            if ($uuid === 'lib-uuid') {
                return [
                    'uuid'               => 'lib-uuid',
                    'library_group_uuid' => 'group-1',
                ];
            }
            // Second call: version row in same group but non-draft
            return [
                'uuid'               => $uuid,
                'library_group_uuid' => 'group-1',
                'status'             => 'published',
            ];
        }
    };

    $this->controller
        ->method('getPost')
        ->willReturn(['content' => 'body']);

    $this->controller->updateLibraryVersion('lib-uuid', 'v1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

