<?php

declare(strict_types=1);

// Load test config and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::index()
 *
 * Focus on routing, permissions and @simplifi.ro write restrictions.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods([
            'checkPlugin',
            'sendResponse',
        ])
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

test('index returns 403 for GET single library when get permission is missing', function () {
    $this->controller->request->get = [
        'uuid' => 'LIB-UUID',
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
        'uuid'   => 'LIB-UUID',
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

test('index returns 403 for POST add-to-my-templates when post permission is missing', function () {
    $this->controller->request->get = [
        'uuid'   => 'LIB-UUID',
        'action' => 'add-to-my-templates',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'] ?? [])->not->toBe([]);
});

test('index blocks write POST createLibrary for non-simplifi.ro email', function () {
    $this->controller->request->get = [];
    $this->controller->permission->post = ['publicapi/v1/esign/tpl/library'];

    $_SERVER['REQUEST_METHOD'] = 'POST';

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

