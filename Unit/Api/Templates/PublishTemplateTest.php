<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::publishTemplate()
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

// Helper to invoke the private publishTemplate() method.
function invokeTplPublishTemplate($controller, string $uuid): void {
    $ref = new ReflectionClass(ControllerPublicAPIV1EsignTplTemplates::class);
    $method = $ref->getMethod('publishTemplate');
    $method->setAccessible(true);
    $method->invoke($controller, $uuid);
}

test('publishTemplate returns 404 when template is not found', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) { return null; }
    };

    invokeTplPublishTemplate($this->controller, 'missing');

    expect($this->controller->statusCode)->toBe(404);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishTemplate returns 403 when caller does not own template', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 999,
                'status'                      => 'draft',
            ];
        }
    };

    invokeTplPublishTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishTemplate returns 422 when template is not draft', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'published',
            ];
        }
    };

    invokeTplPublishTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishTemplate returns 500 when publishVersion fails', function () {
    $this->controller->model_esign_tpl_template = new class {
        public function getTemplateByUuid(string $uuid) {
            return [
                'uuid'                        => $uuid,
                'created_by_customer_role_id' => 20,
                'status'                      => 'draft',
            ];
        }
        public function publishVersion(string $uuid) { return false; }
    };

    invokeTplPublishTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBe(500);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('publishTemplate succeeds for draft template', function () {
    $this->controller->model_esign_tpl_template = new class {
        private $store;
        public function __construct() {
            $this->store = [
                'uuid'                        => 'uuid-1',
                'created_by_customer_role_id' => 20,
                'status'                      => 'draft',
                'version'                     => 1,
            ];
        }
        public function getTemplateByUuid(string $uuid) {
            return $this->store;
        }
        public function publishVersion(string $uuid) {
            $this->store['status'] = 'published';
            $this->store['version'] = 2;
            return true;
        }
    };

    invokeTplPublishTemplate($this->controller, 'uuid-1');

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->toBe('uuid-1');
    expect($data['status'] ?? null)->toBe('published');
    expect($data['version'] ?? null)->toBeInt();
}
);

