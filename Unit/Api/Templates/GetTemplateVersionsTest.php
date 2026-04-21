<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::getTemplateVersions()
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

// Helper to invoke private getTemplateVersions().
function invokeTplGetTemplateVersions($controller, string $uuid): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('getTemplateVersions');
    $method->setAccessible(true);
    $method->invoke($controller, $uuid);
}

test('getTemplateVersions returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    invokeTplGetTemplateVersions($this->controller, 'missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('getTemplateVersions returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 999,
            ];
        }
    };

    invokeTplGetTemplateVersions($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('getTemplateVersions builds params and shapes response', function () {
    $this->controller->request->get = [
        'page'   => '2',
        'per_page' => '5',
        'sort'   => 'version',
        'order'  => 'asc',
        'status' => 'draft',
    ];

    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'template_group_uuid'         => null,
                'created_by_customer_role_id' => 20,
            ];
        }
        public function getTemplateGroupVersions(string $groupUuid, int $companyId, array $params) {
            return [
                'versions' => [[
                    'uuid'              => 'v1',
                    'name'              => 'Name',
                    'description'       => null,
                    'category_code'     => 'general',
                    'visibility'        => 'private',
                    'status'            => 'draft',
                    'version'           => 1,
                    'parties_count'     => 0,
                    'smartfields_count' => 0,
                    'date_added'        => '2024-01-01 10:00:00',
                    'date_modified'     => '2024-01-01 11:00:00',
                ]],
                'total' => 1,
            ];
        }
    };

    invokeTplGetTemplateVersions($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect(count($data))->toBe(1);
    expect($data[0]['uuid'] ?? null)->toBe('v1');
}
);

