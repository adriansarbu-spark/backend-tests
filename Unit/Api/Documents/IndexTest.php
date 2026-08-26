<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

beforeEach(function () {
    // Routing/permission tests still invoke the real handlers. Wire the same
    // customer/model stubs as the other document unit tests so those handlers
    // return 404/422 instead of NPEing and dumping stack traces to stderr
    // (which the admin Test Runner surfaces as a run error).
    $this->controller = buildDocumentsController();
    $this->controller->permission = (object)[
        'get'    => [],
        'post'   => [],
        'put'    => [],
        'delete' => [],
    ];
    $this->controller->request = (object)['get' => []];
    $this->controller->json = ['success' => 1, 'error' => [], 'data' => []];
    $this->controller->statusCode = 200;
    $this->controller->allowedHeaders = null;
});

// --- POST without uuid/action ---

test('POST without uuid returns 403 without post permission', function () {
    $this->controller->request->get = [];
    $this->controller->permission->post = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'])->toContain('access_denied');
});

test('POST without uuid does not 403 when post permission granted', function () {
    $this->controller->request->get = [];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // createDraft is private and will fail internally, but the important thing
    // is that it does NOT return 403 — routing reached the handler.
    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
});

// --- GET without uuid ---

test('GET without uuid returns 403 without get permission', function () {
    $this->controller->request->get = [];
    $this->controller->permission->get = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
    expect($this->controller->json['error'])->toContain('access_denied');
});

test('GET without uuid does not 403 when get permission granted', function () {
    $this->controller->request->get = [];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
});

// --- GET with uuid (no action) → getDocument ---

test('GET with uuid does not 403 when get permission granted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-UUID-1'];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- GET with uuid and action=file → getDocumentFile ---

test('GET with uuid and action=file does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-1', 'action' => 'file'];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- GET with uuid and action=audit-certificate → getDocumentAuditCertificate ---

test('GET with uuid and action=audit-certificate does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-2', 'action' => 'audit-certificate'];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- GET with uuid=statistics → getStatistics ---

test('GET with uuid=statistics does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'statistics'];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- GET with uuid=recipients → listRecipients ---

test('GET with uuid=recipients does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'recipients'];
    $this->controller->permission->get = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- PUT with uuid → putDocumentDraft ---

test('PUT with uuid does not 403 when put permission granted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-PUT'];
    $this->controller->permission->put = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

test('PUT with uuid also accepts post permission as fallback', function () {
    $this->controller->request->get = ['uuid' => 'DOC-PUT'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $this->controller->permission->put = [];
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- POST with uuid and action=signers → addSigners ---

test('POST with uuid and action=signers does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-S', 'action' => 'signers'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- POST with uuid and action=annotations → setAnnotations ---

test('POST with uuid and action=annotations does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-A', 'action' => 'annotations'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- POST with uuid and action=send → sendDocument ---

test('POST with uuid and action=send does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-SEND', 'action' => 'send'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- POST with uuid and action=cancel → cancelDocument ---

test('POST with uuid and action=cancel does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-C', 'action' => 'cancel'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- POST with uuid and action=remind → remindSigner ---

test('POST with uuid and action=remind does not 403 when permitted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-R', 'action' => 'remind'];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

// --- DELETE with uuid → deleteDocument ---

test('DELETE with uuid does not 403 when delete permission granted', function () {
    $this->controller->request->get = ['uuid' => 'DOC-DEL'];
    $this->controller->permission->delete = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

test('DELETE with uuid returns 403 without delete permission', function () {
    $this->controller->request->get = ['uuid' => 'DOC-DEL'];
    $this->controller->permission->delete = [];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(403);
});

// --- Invalid method → 405 ---

test('invalid method returns 405', function () {
    $this->controller->request->get = ['uuid' => 'DOC-1', 'action' => 'unknown'];
    $_SERVER['REQUEST_METHOD'] = 'PATCH';

    $this->controller->index();

    expect($this->controller->statusCode)->toBe(405);
    expect($this->controller->allowedHeaders)->toBe(['GET', 'POST', 'PUT', 'DELETE']);
});

// --- Route parsing ---

test('index extracts uuid and action from route param', function () {
    $this->controller->request->get = [
        'route' => 'publicapi/v1/documents/MY-UUID/send',
    ];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});

test('index extracts uuid and action from _route_ param', function () {
    $this->controller->request->get = [
        '_route_' => 'publicapi/v1/documents/ROUTE-UUID/cancel',
    ];
    $this->controller->permission->post = ['publicapi/v1/documents'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $this->controller->index();

    expect($this->controller->statusCode)->not->toBe(403);
    expect($this->controller->statusCode)->not->toBe(405);
});
