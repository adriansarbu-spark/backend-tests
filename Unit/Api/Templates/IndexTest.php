<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/templates.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplTemplates::index()
 *
 * These are low-level controller tests that focus on routing and permission
 * logic, following the project-wide documents testing guidelines.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1EsignTplTemplates&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1EsignTplTemplates::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse'])
        ->getMock();

    $this->controller->method('checkPlugin')->willReturn(null);
    $this->controller->method('sendResponse')->willReturn(null);

    $this->controller->request = (object)[
        'get' => [],
    ];

    $this->controller->permission = (object)[
        'get'    => [],
        'post'   => [],
        'put'    => [],
        'delete' => [],
    ];

    $this->controller->json = [];
    $this->controller->statusCode = null;
    $this->controller->allowedHeaders = null;
});

test('index returns 403 for POST without createTemplate permission', function () {
    $this->controller->request->get = [];
    $this->controller->permission->post = [];

    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index returns 403 for GET single template when get permission is missing', function () {
    $this->controller->request->get = [
        'uuid' => 'TEMPLATE-UUID',
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index returns 403 for GET versions when get permission is missing', function () {
    $this->controller->request->get = [
        'uuid'   => 'TEMPLATE-UUID',
        'action' => 'versions',
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index returns 403 for PUT update when put permission is missing', function () {
    $this->controller->request->get = [
        'uuid' => 'TEMPLATE-UUID',
    ];
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index returns 403 for DELETE when delete permission is missing', function () {
    $this->controller->request->get = [
        'uuid' => 'TEMPLATE-UUID',
    ];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index responds with 405 for unsupported method', function () {
    $this->controller->request->get = [];

    $_SERVER['REQUEST_METHOD'] = 'PATCH';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(405);
    expect($this->controller->allowedHeaders)->toBe(['GET', 'POST', 'PUT', 'DELETE']);
});

