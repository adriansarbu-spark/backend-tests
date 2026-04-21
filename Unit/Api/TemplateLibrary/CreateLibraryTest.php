<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::createLibrary()
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

test('createLibrary returns 422 when name is missing', function () {
    $this->controller
        ->method('getPost')
        ->willReturn(['language_id' => 1]);

    $this->controller->model_esign_tpl_library = new class {
        public function createLibrary(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createLibrary();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['field'] ?? null)->toBe('name');
}
);

test('createLibrary returns 422 when language_id is missing', function () {
    $this->controller
        ->method('getPost')
        ->willReturn(['name' => 'Lib']);

    $this->controller->model_esign_tpl_library = new class {
        public function createLibrary(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createLibrary();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['field'] ?? null)->toBe('language_id');
}
);

