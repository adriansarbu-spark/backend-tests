<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::cloneLibraryToNewLibrary()
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

test('cloneLibraryToNew returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->cloneLibraryToNewLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('cloneLibraryToNew creates new draft library with copied name', function () {
    $this->controller->model_esign_tpl_library = new class {
        private $source;
        private $cloned;
        public function __construct() {
            $this->source = [
                'uuid' => 'lib-uuid',
                'name' => 'Source lib',
            ];
        }
        public function getLibraryByUuid(string $uuid) {
            if ($uuid === 'lib-uuid') {
                return $this->source;
            }
            return $this->cloned ?? null;
        }
        public function cloneLibraryToNew($uuid, $newUuid) {
            $this->cloned = [
                'uuid'   => $newUuid,
                'name'   => $this->source['name'],
                'status' => 'draft',
                'version'=> 1,
            ];
            return $this->cloned;
        }
    };

    $this->controller->cloneLibraryToNewLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->not->toBeNull();
    expect($data['name'] ?? null)->toBe('Source lib');
    expect($data['status'] ?? null)->toBe('draft');
}
);

