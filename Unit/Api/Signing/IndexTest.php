<?php

declare(strict_types=1);

// Load test config (defines DIR_SYSTEM, PUBLIC_API, etc.) and the controller under test.
require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'signing.php';


use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ControllerPublicAPIV1Signing::index()
 *
 * These are low-level controller tests that do not hit the HTTP layer.
 * They focus on routing and permission logic only.
 */

// Common setup for all tests in this file. We partially mock the controller so
// each test can focus on a small piece of behaviour (routing / permissions)
// without pulling in the full signing implementation or real dependencies.
// We pass a real Registry instance into the OpenCart Controller base class so
// its magic __get/__set do not explode.
beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1Signing&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse'])
        ->getMock();

    // Default no-op for checkPlugin and sendResponse, most tests will override expectations.
    $this->controller->method('checkPlugin')->willReturn(null);
    $this->controller->method('sendResponse')->willReturn(null);

    // Fake request/permission objects shaped like OpenCart controller expects.
    $this->controller->request = (object)[
        'get' => [],
    ];

    $this->controller->permission = (object)[
        'get'  => [],
        'post' => [],
        'put'  => [],
    ];

    $this->controller->json = [];
    $this->controller->statusCode    = null;
    $this->controller->allowedHeaders = null;
});

test('index returns 400 when sign_code is missing', function () {
    // Simulate a request without sign_code or route; controller should reject
    // early with a 400 validation error.
    $this->controller->request->get = []; // no sign_code, no route

    // sendResponse should be called once when early returning.
    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(400);
});

test('index extracts sign_code and action from route when present', function () {
    // Simulate the SEO-style route form:
    // publicapi/v1/signing/{sign_code}/{action}
    $this->controller->request->get = [
        'route' => 'publicapi/v1/signing/ABC123/sign',
    ];

    // Stub signDocument to avoid hitting the full signing flow here; this test
    // only verifies that index() parses the route and delegates correctly.
    $registry = new Registry();
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse', 'signDocument'])
        ->getMock();

    $this->controller->request = (object)[
        'get' => [
            'route' => 'publicapi/v1/signing/ABC123/sign',
        ],
    ];
    $this->controller->permission = (object)[
        'get'  => [],
        'post' => ['publicapi/v1/signing'],
        'put'  => [],
    ];

    $this->controller
        ->expects($this->once())
        ->method('signDocument')
        ->with('ABC123');

    // POST + action=sign
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();
});

test('index enforces get permission for get signer info', function () {
    $registry = new Registry();
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse', 'getSignerInfo'])
        ->getMock();

    $this->controller->request = (object)[
        'get' => [
            'sign_code' => 'CODE123',
        ],
    ];

    // No GET permission granted: index() must not call getSignerInfo() and
    // should respond with a 403 and an access denied payload.
    $this->controller->permission = (object)[
        'get'  => [],
        'post' => [],
        'put'  => [],
    ];

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->never())
        ->method('getSignerInfo');

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->json = [];

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['data'] ?? null)->toBeNull();
});

test('index calls getSignerInfo when get permission is granted', function () {
    $registry = new Registry();
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1Signing::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse', 'getSignerInfo'])
        ->getMock();

    $this->controller->request = (object)[
        'get' => [
            'sign_code' => 'SIGN123',
        ],
    ];

    $this->controller->permission = (object)[
        'get'  => ['publicapi/v1/signing'],
        'post' => [],
        'put'  => [],
    ];

    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller
        ->expects($this->once())
        ->method('getSignerInfo')
        ->with('SIGN123');

    $this->controller->index();
});

test('index responds with 405 for invalid method or action', function () {
    $this->controller->request->get = [
        'sign_code' => 'CODE123',
        'action'    => 'unknown',
    ];

    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->controller
        ->expects($this->once())
        ->method('sendResponse');

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(405);
    expect($this->controller->allowedHeaders)->toBe(['GET', 'POST', 'PUT']);
});

