<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'esign/author/documents.php';

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for {@see ControllerPublicAPIV1EsignAuthorDocuments} routing and permissions.
 */

beforeEach(function () {
    $registry = new Registry();

    /** @var ControllerPublicAPIV1EsignAuthorDocuments&MockObject $controller */
    $this->controller = $this->getMockBuilder(ControllerPublicAPIV1EsignAuthorDocuments::class)
        ->setConstructorArgs([$registry])
        ->onlyMethods(['checkPlugin', 'sendResponse'])
        ->getMock();

    $this->controller->method('checkPlugin')->willReturn(null);
    $this->controller->method('sendResponse')->willReturn(null);

    $this->controller->request = (object) ['get' => []];
    $this->controller->permission = (object) [
        'get'    => [],
        'post'   => [],
        'put'    => [],
        'delete' => [],
    ];
    $this->controller->backend_variables = [
        'company_id' => 10,
        'customer_role_id' => 5,
        'customer_id' => 1,
    ];
    $this->controller->json = [];
    $this->controller->statusCode = null;
});

/**
 * Prerequisites:
 * - POST create without permission on `publicapi/v1/esign/author-documents`.
 *
 * Steps:
 * 1. POST index() with no uuid.
 * 2. Assert **HTTP 403** Access denied.
 */
test('Author documents API — POST create without permission returns 403', function () {
    $this->controller->permission->post = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403)
        ->and($this->controller->json['error'] ?? [])->toContain('access_denied');
});

/**
 * Prerequisites:
 * - GET list without get permission.
 *
 * Steps:
 * 1. GET index() with no uuid.
 * 2. Assert **HTTP 403**.
 */
test('Author documents API — GET list without permission returns 403', function () {
    $this->controller->permission->get = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
});

/**
 * Prerequisites:
 * - Unsupported verb on collection route.
 *
 * Steps:
 * 1. PATCH index().
 * 2. Assert **HTTP 405** Method not allowed.
 */
/**
 * Prerequisites:
 * - POST create permitted on collection route; body has no document name.
 *
 * Steps:
 * 1. POST `index()` without uuid.
 * 2. Assert **HTTP 422** validation on `name` (routes to `createDocument`).
 */
test('Author documents API — POST create without name returns 422 when permitted', function () {
    $path = 'publicapi/v1/esign/author-documents';
    $this->controller->permission->post = [$path];
    $this->controller->backend_variables = [
        'company_id' => 10,
        'customer_role_id' => 5,
        'customer_id' => 1,
    ];
    $this->controller->apiRequest = new class {
        public object $request;

        public function __construct()
        {
            $this->request = new class {
                public function all(): array
                {
                    return ['source_type' => 'scratch'];
                }
            };
        }

        public function getContent(): string
        {
            return '';
        }
    };
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(422);
    expect($this->controller->json['error'] ?? [])->toContain('name_required');
});

test('Author documents API — unsupported method returns 405', function () {
    $path = 'publicapi/v1/esign/author-documents';
    $this->controller->permission->get = [$path];
    $this->controller->permission->post = [$path];
    $this->controller->permission->put = [$path];
    $this->controller->permission->delete = [$path];
    $_SERVER['REQUEST_METHOD'] = 'PATCH';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(405)
        ->and($this->controller->json['error'] ?? [])->toContain('method_not_allowed');
});
