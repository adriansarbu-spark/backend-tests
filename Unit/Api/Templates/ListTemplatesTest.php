<?php

declare(strict_types=1);

// Load test config and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::listTemplates()
 *
 * Focus on query validation and response shaping.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->request = (object)[
        'get' => [],
    ];

    $this->controller->customer = new class {
        public function getCompanyId() { return 10; }
        public function getRoleId() { return 20; }
    };

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

// Helper to invoke the private listTemplates() method.
function invokeTplListTemplates($controller): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('listTemplates');
    $method->setAccessible(true);
    $method->invoke($controller);
}

test('listTemplates returns 422 for invalid sort field', function () {
    $this->controller->request->get = ['sort' => 'invalid-field'];

    $this->controller->model_esign_tpl_template = new class {
        public function listTemplates(array $params) { throw new RuntimeException('should not be called'); }
    };

    invokeTplListTemplates($this->controller);

    expect($this->controller->statusCode)->toBe(422);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors)->not->toBe([]);
});

test('listTemplates returns 422 for invalid order', function () {
    $this->controller->request->get = ['order' => 'WRONG'];

    $this->controller->model_esign_tpl_template = new class {
        public function listTemplates(array $params) { throw new RuntimeException('should not be called'); }
    };

    invokeTplListTemplates($this->controller);

    expect($this->controller->statusCode)->toBe(422);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors)->not->toBe([]);
});

test('listTemplates builds params and shapes response correctly', function () {
    $this->controller->request->get = [
        'page'          => '2',
        'per_page'      => '5',
        'sort'          => 'name',
        'order'         => 'asc',
        'status'        => 'draft',
        'visibility'    => 'private',
        'category_code' => 'contracts',
    ];

    $capturedParams = null;
    $this->controller->model_esign_tpl_template = new class ($capturedParams) {
        private $capturedParams;
        public function __construct(&$capturedParams) { $this->capturedParams = &$capturedParams; }
        public function listTemplates(array $params) {
            $this->capturedParams = $params;
            return [
                'templates' => [[
                    'uuid'              => 'uuid-1',
                    'name'              => 'First',
                    'description'       => null,
                    'category_code'     => 'contracts',
                    'visibility'        => 'private',
                    'status'            => 'draft',
                    'version'           => 3,
                    'parties_count'     => 2,
                    'smartfields_count' => 1,
                    'date_added'        => '2024-01-01 10:00:00',
                    'date_modified'     => '2024-01-02 12:00:00',
                ]],
                'total' => 1,
            ];
        }
    };

    invokeTplListTemplates($this->controller);

    expect($this->controller->statusCode)->toBeNull();

    // Assert response data is shaped correctly (we avoid deep param assertions to keep the test stable).
    $data = $this->controller->json['data'] ?? [];
    expect(count($data))->toBe(1);
    $item = $data[0];
    expect($item['uuid'] ?? null)->toBe('uuid-1');
    expect($item['name'] ?? null)->toBe('First');
    expect($item['category_code'] ?? null)->toBe('contracts');
    expect($item['visibility'] ?? null)->toBe('private');
    expect($item['status'] ?? null)->toBe('draft');

    expect($this->capturedHeaders)->toContain('X-Total-Count: 1');
});

