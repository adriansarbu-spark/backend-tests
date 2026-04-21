<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::cloneTemplate()
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

    $this->controller->customer = new class {
        public function getRoleId() { return 20; }
    };

    $this->controller->load = new class {
        public function model(string $name): void {}
    };
});

// Helper to invoke the private cloneTemplate() method.
function invokeTplCloneTemplate($controller, string $uuid): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('cloneTemplate');
    $method->setAccessible(true);
    $method->invoke($controller, $uuid);
}

test('cloneTemplate returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    invokeTplCloneTemplate($this->controller, 'missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('cloneTemplate returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 999,
            ];
        }
    };

    invokeTplCloneTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('cloneTemplate returns 500 when cloneTemplateToNewGroup fails', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
            ];
        }
        public function cloneTemplateToNewGroup(string $uuid, string $newUuid) { return 0; }
    };

    invokeTplCloneTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(500);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('cloneTemplate succeeds and returns cloned data', function () {
    $this->controller->model_esign_tpl_template = new class {
        private $cloned;
        public function getTemplateByUuid(string $uuid) {
            if ($uuid === 'source') {
                return [
                    'uuid'                        => 'source',
                    'name'                        => 'Src',
                    'created_by_customer_role_id' => 20,
                    'status'                      => 'draft',
                    'version'                     => 1,
                ];
            }
            // Return cloned row.
            return $this->cloned ?? [
                'uuid'   => 'cloned-uuid',
                'name'   => 'Src',
                'status' => 'draft',
                'version'=> 1,
            ];
        }
        public function cloneTemplateToNewGroup(string $uuid, string $newUuid) {
            $this->cloned = [
                'uuid'   => $newUuid,
                'name'   => 'Src',
                'status' => 'draft',
                'version'=> 1,
            ];
            return 123;
        }
        public function getVersionParties(string $uuid): array {
            return [[
                'code'           => 'P1',
                'label'          => 'Party 1',
                'signing_order'  => 1,
                'parallel_group' => 0,
            ]];
        }
        public function replaceVersionParties(string $uuid, array $rows) { return true; }
        public function getVersionSmartfields(string $uuid): array {
            return [[
                'field_key' => 'field1',
                'label'     => 'Field 1',
                'type'      => 'text',
                'required'  => 1,
            ]];
        }
        public function replaceVersionSmartfields(string $uuid, array $rows) { return true; }
    };

    invokeTplCloneTemplate($this->controller, 'source');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->not->toBeNull();
    expect($data['name'] ?? null)->toBe('Src');
    expect($data['status'] ?? null)->toBe('draft');
    expect($data['version'] ?? null)->toBeInt();
}
);

