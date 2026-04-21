<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::getLibraryVersions()
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

    $this->controller->request = (object)[
        'get' => [],
    ];

    $this->capturedHeaders = [];
    $capturedHeaders =& $this->capturedHeaders;
    $this->controller->response = new class ($capturedHeaders) {
        private $capturedHeaders;
        public function __construct(array &$capturedHeaders) { $this->capturedHeaders = &$capturedHeaders; }
        public function addHeader(string $header): void { $this->capturedHeaders[] = $header; }
    };

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

test('getLibraryVersions returns 404 when library is not found', function () {
    $this->controller->model_esign_tpl_library = new class {
        public function getLibraryByUuid(string $uuid) { return null; }
    };

    $this->controller->getLibraryVersions('missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
}
);

