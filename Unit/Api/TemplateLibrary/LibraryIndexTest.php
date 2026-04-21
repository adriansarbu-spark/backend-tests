<?php

declare(strict_types=1);

// Load test config and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/tpl/library.php';
require_once __DIR__ . '/_support/TemplateLibraryTestDoubles.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1EsignTplLibrary::index()
 *
 * Focus on happy-path routing for read-only GET endpoints.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var TestableControllerPublicAPIV1EsignTplLibrary&MockObject $controller */
    $this->controller = $this->getMockBuilder(TestableControllerPublicAPIV1EsignTplLibrary::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse', 'listLibraries', 'getLibrary', 'getLibraryVersions'])
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
});

test('index routes GET without uuid to listLibraries when permitted', function () {
    $this->controller->request->get = [];
    $this->controller->permission->get = ['publicapi/v1/esign/tpl/library'];

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('listLibraries');

    $this->controller->index();
});

test('index routes GET with uuid to getLibrary when permitted', function () {
    $this->controller->request->get = [
        'uuid' => 'LIB-UUID',
    ];
    $this->controller->permission->get = ['publicapi/v1/esign/tpl/library'];

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('getLibrary')
        ->with('LIB-UUID');

    $this->controller->index();
});

test('index routes GET with uuid and versions action to getLibraryVersions when permitted', function () {
    $this->controller->request->get = [
        'uuid'   => 'LIB-UUID',
        'action' => 'versions',
    ];
    $this->controller->permission->get = ['publicapi/v1/esign/tpl/library'];

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('getLibraryVersions')
        ->with('LIB-UUID');

    $this->controller->index();
});

