<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::archiveLibrary()
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

test('archiveLibrary returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->archiveLibrary('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('archiveLibrary archives published library', function () {
    $this->controller->model_esign_tpl_library = new class {
        private $row;
        public function __construct() {
            $this->row = [
                'uuid'   => 'lib-uuid',
                'status' => 'published',
            ];
        }
        public function getLibraryByUuid(string $uuid) {
            return $this->row;
        }
        public function archiveLibrary(string $uuid) {
            if ($uuid === 'lib-uuid') {
                $this->row['status'] = 'archived';
                return true;
            }
            return false;
        }
    };

    $this->controller->archiveLibrary('lib-uuid');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['archived'] ?? null)->toBeTrue();
}
);

