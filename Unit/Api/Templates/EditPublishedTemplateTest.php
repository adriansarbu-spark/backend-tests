<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::editPublishedTemplate()
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

// Helper to invoke the private editPublishedTemplate() method.
function invokeTplEditPublishedTemplate($controller, string $uuid): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('editPublishedTemplate');
    $method->setAccessible(true);
    $method->invoke($controller, $uuid);
}

test('editPublishedTemplate returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    invokeTplEditPublishedTemplate($this->controller, 'missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedTemplate returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 999,
                'status'                      => 'published',
            ];
        }
    };

    invokeTplEditPublishedTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedTemplate returns 422 when template is not published', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'draft',
            ];
        }
    };

    invokeTplEditPublishedTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('editPublishedTemplate returns 500 when new version cannot be created', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'published',
            ];
        }
        public function editPublishedTemplate(string $uuid, string $newUuid) { return 0; }
    };

    invokeTplEditPublishedTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(500);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
}
);

