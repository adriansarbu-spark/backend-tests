<?php

declare(strict_types=1);

// Load test config and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';
require_once __DIR__ . '/_support/TemplatesTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::createTemplate()
 *
 * These tests cover validation branches and a minimal happy path, without
 * asserting on exact error text (only codes/fields/status).
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['sendResponse', 'getPost'])
        ->getMock();

    $this->controller->json = [];
    $this->controller->statusCode = null;

    $this->controller->load = new class {
        public function model(string $name): void {}
    };

    $this->controller->customer = new class {
        public function getCompanyId() { return 100; }
        public function getRoleId() { return 200; }
    };

    $this->controller->apiRequest = null;
});

test('createTemplate returns 422 when name is missing', function () {
    $this->controller
        ->method('getPost')
        ->willReturn(['content' => 'body']);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('name');
});

test('createTemplate returns 422 when content is missing', function () {
    $this->controller
        ->method('getPost')
        ->willReturn(['name' => 'My template']);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('content');
});

test('createTemplate returns 422 for invalid category_code', function () {
    $this->controller
        ->method('getPost')
        ->willReturn([
            'name'          => 'My template',
            'content'       => 'body',
            'category_code' => 'invalid',
        ]);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(422);
    $error = $this->controller->json['error'] ?? [];
    expect($error['code'] ?? null)->toBe('VALIDATION_ERROR');
    expect($error['field'] ?? null)->toBe('category_code');
});

test('createTemplate returns 403 when company or role context is missing', function () {
    $this->controller->customer = new class {
        public function getCompanyId() { return null; }
        public function getRoleId() { return null; }
    };

    $this->controller
        ->method('getPost')
        ->willReturn([
            'name'    => 'My template',
            'content' => 'body',
        ]);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) { throw new RuntimeException('should not be called'); }
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBe(403);
    $errors = $this->controller->json['error'] ?? [];
    expect($errors)->not->toBe([]);
});

test('createTemplate succeeds with minimal valid payload', function () {
    $this->controller
        ->method('getPost')
        ->willReturn([
            'name'    => 'My template',
            'content' => 'body',
        ]);

    $this->controller->model_esign_tpl_template = new class {
        public function createTemplate(array $data) { return 123; }
        public function replaceVersionParties($uuid, array $parties) {}
        public function replaceVersionSmartfields($uuid, array $smartfields) {}
    };

    $this->controller->createTemplate();

    expect($this->controller->statusCode)->toBeNull();
    $data = $this->controller->json['data'] ?? [];
    expect($data['uuid'] ?? null)->not->toBeNull();
});

