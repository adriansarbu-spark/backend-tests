<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::updateTemplate()
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
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

// Helper to invoke the private updateTemplate() method.
function invokeTplUpdateTemplate($controller, string $uuid): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('updateTemplate');
    $method->setAccessible(true);
    $method->invoke($controller, $uuid);
}

test('updateTemplate returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    invokeTplUpdateTemplate($this->controller, 'missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('updateTemplate returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 999,
                'status'                      => 'draft',
            ];
        }
    };

    invokeTplUpdateTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('updateTemplate returns 422 when template is not draft', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'published',
            ];
        }
    };

    invokeTplUpdateTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('updateTemplate returns 422 for invalid category_code', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'draft',
            ];
        }
        public function updateTemplate(string $uuid, array $updates) { return true; }
    };

    $this->controller
        ->method('getPost')
        ->willReturn(['category_code' => 'invalid']);

    invokeTplUpdateTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('category_code');
});

test('updateTemplate succeeds when updating name and content', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'draft',
            ];
        }
        public function updateTemplate(string $uuid, array $updates) { return true; }
        public function replaceVersionParties($uuid, array $parties) {}
        public function replaceVersionSmartfields($uuid, array $smartfields) {}
    };

    $this->controller
        ->method('getPost')
        ->willReturn([
            'name'    => 'Updated',
            'content' => 'body',
        ]);

    invokeTplUpdateTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBeNull();
    expect($this->controller->json['data'] ?? null)->toBeArray();
});

