<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::listLibraries()
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

test('listLibraries filters status to published for non-simplifi.ro emails', function () {
    $this->controller->request->get = [
        'status' => 'draft',
    ];

    // Make isSimplifiRoEmail() return false via a simple subclass override in the test double.
    $this->controller->method('sendResponse')->willReturn(null);

    $model = new class {
        public array $seenParams = [];
        public function listLibraries(array $params) {
            $this->seenParams = $params;
            return [];
        }
    };
    $this->controller->model_esign_tpl_library = $model;

    $this->controller->listLibraries();

    expect($this->controller->statusCode)->toBeNull();
    expect($model->seenParams['status'] ?? null)->toBe('published');
}
);

test('listLibraries returns libraries and pagination metadata and can_archive only for simplifi.ro published', function () {
    $this->controller->customer = new class {
        public function getEmail(): string { return 'admin@simplifi.ro'; }
    };

    $this->controller->request->get = [
        'page' => 1,
        'per_page' => 10,
        'sort' => 'date_added',
        'order' => 'ASC',
        'status' => 'published',
    ];

    $this->controller->method('sendResponse')->willReturn(null);

    $this->controller->model_esign_tpl_library = new class {
        public function listLibraries(array $params) {
            return [
                [
                    'uuid' => 'lib-1',
                    'name' => 'Lib 1',
                    'description' => 'D1',
                    'category_code' => 'general',
                    'language_id' => 1,
                    'status' => 'published',
                    'version' => 1,
                    'parties_count' => 2,
                    'smartfields_count' => 3,
                    'date_added' => '2024-01-01 10:00:00',
                    'date_modified' => '2024-01-02 10:00:00',
                ],
                [
                    'uuid' => 'lib-2',
                    'name' => 'Lib 2',
                    'status' => 'draft',
                ],
            ];
        }
    };

    $this->controller->listLibraries();

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data)->toHaveCount(2);
    expect($data[0]['uuid'] ?? null)->toBe('lib-1');
    expect($data[0]['can_archive'] ?? false)->toBeTrue();
    expect($data[1]['can_archive'] ?? true)->toBeFalse();

    // Pagination headers should be set
    expect($this->capturedHeaders)->toContain('X-Total-Count: 2');
    expect($this->capturedHeaders)->toContain('X-Pagination-Page: 1');
    expect($this->capturedHeaders)->toContain('X-Pagination-Per-Page: 10');
    expect($this->capturedHeaders)->toContain('X-Pagination-Total-Pages: 1');
});

