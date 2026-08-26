<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscDevPack::index() JSON/guard paths.
 * File download/zip success paths call private sendFile()+exit and are covered by integration tests.
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
});

function csc_dev_pack_controller(CscApiCustomerStub $customer, ?CscApiClientModelStub $client = null): TestableControllerPublicAPIV1CscDevPack
{
    [$registry] = csc_access_registry($customer);
    $load = $registry->get('load');
    // Reuse access load stub path for entitlement; attach api_client via a thin wrapper.
    $apiClient = $client ?? new CscApiClientModelStub();
    $origLoad = $load;
    $registry->set('load', new class($registry, $origLoad, $apiClient) {
        public array $loadedModels = [];

        public function __construct(
            private Registry $registry,
            private $inner,
            private CscApiClientModelStub $apiClient,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'csc/api_client') {
                $this->registry->set('model_csc_api_client', $this->apiClient);
            } else {
                $this->inner->model($route);
            }
        }
    });

    $c = new TestableControllerPublicAPIV1CscDevPack($registry);
    $c->permission = (object) [
        'get'    => [ControllerPublicAPIV1CscDevPack::PERMISSION_PATH],
        'post'   => [],
        'patch'  => [],
        'delete' => [],
    ];

    return $c;
}

/**
 * Prerequisites:
 * - Unauthenticated customer.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 401.
 */
test('CSC dev pack — unauthenticated returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $c = csc_dev_pack_controller(new CscApiCustomerStub(0, 10, 1));
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated']);
});

/**
 * Prerequisites:
 * - Non-admin role.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 403 admin_role_required.
 */
test('CSC dev pack — non-admin returns 403', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $ent = new CscEntitlementAssignmentStub();
    $ent->isAdmin = false;
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), null, $ent);
    $c = new TestableControllerPublicAPIV1CscDevPack($registry);
    $c->permission = (object) [
        'get' => [ControllerPublicAPIV1CscDevPack::PERMISSION_PATH],
        'post' => [], 'patch' => [], 'delete' => [],
    ];
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required']);
});

/**
 * Prerequisites:
 * - Admin; POST method.
 *
 * Steps:
 * 1. Call index() with POST.
 * 2. Assert 405.
 */
test('CSC dev pack — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $c = csc_dev_pack_controller(new CscApiCustomerStub(1, 10, 5));
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Admin; no API client row.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 409 integration_not_provisioned.
 */
test('CSC dev pack — missing integration returns 409', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $client = new CscApiClientModelStub();
    $client->byCompany = null;
    $c = csc_dev_pack_controller(new CscApiCustomerStub(1, 10, 5), $client);
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['integration_not_provisioned']);
});

/**
 * Prerequisites:
 * - Provisioned admin; no item query.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert every static item plus environment and zip appear once.
 */
test('CSC dev pack — index lists every supported resource', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $client = new CscApiClientModelStub();
    $client->byCompany = [
        'api_client_uuid'    => 'u1',
        'keycloak_client_id' => 'sim_api_u1',
        'name'               => 'N',
        'status'             => 'active',
    ];
    $c = csc_dev_pack_controller(new CscApiCustomerStub(1, 10, 5), $client);
    $c->index();

    $items = array_column($c->json['data']['items'], 'item');
    expect($c->statusCode)->toBe(200)
        ->and($items)->toContain('openapi-yaml')
        ->and($items)->toContain('openapi-json')
        ->and($items)->toContain('reference')
        ->and($items)->toContain('guide')
        ->and($items)->toContain('postman-collection')
        ->and($items)->toContain('snippet-php')
        ->and($items)->toContain('postman-environment')
        ->and($items)->toContain('zip')
        ->and(array_count_values($items)['zip'])->toBe(1)
        ->and(array_count_values($items)['postman-environment'])->toBe(1);
});

/**
 * Prerequisites:
 * - Unknown item key.
 *
 * Steps:
 * 1. GET with dev_pack_item=nope.
 * 2. Assert 404 (JSON path before sendFile).
 */
test('CSC dev pack — unknown item returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $client = new CscApiClientModelStub();
    $client->byCompany = ['api_client_uuid' => 'u1', 'keycloak_client_id' => 'sim_api_u1'];
    $c = csc_dev_pack_controller(new CscApiCustomerStub(1, 10, 5), $client);
    $c->request->get = ['dev_pack_item' => 'nope'];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['dev_pack_item_not_found']);
});

/**
 * Prerequisites:
 * - The checked-in CSC developer manifest points at production documentation assets.
 *
 * Steps:
 * 1. Inspect every manifest filename and read every non-generated resource through the containment helper.
 * 2. Attempt traversal and missing-file reads through the same helper.
 * 3. Assert only manifest-safe names and in-root files are accepted.
 */
test('CSC dev pack — manifest resources stay inside docs and use safe zip names', function () {
    $client = new CscApiClientModelStub();
    $client->byCompany = ['api_client_uuid' => 'u1', 'keycloak_client_id' => 'sim_api_u1'];
    $controller = csc_dev_pack_controller(new CscApiCustomerStub(1, 10, 5), $client);
    $itemsProperty = new ReflectionProperty(ControllerPublicAPIV1CscDevPack::class, 'static_items');
    $itemsProperty->setAccessible(true);
    $items = $itemsProperty->getValue();
    $loadFile = new ReflectionMethod(ControllerPublicAPIV1CscDevPack::class, 'loadDocFile');
    $loadFile->setAccessible(true);

    foreach ($items as $key => $meta) {
        expect($meta['name'])->not->toContain('/')
            ->and($meta['name'])->not->toContain('..');
        if ($key !== 'reference') {
            expect($loadFile->invoke($controller, $meta['file']))->toBeString()->not->toBe('');
        }
    }

    expect($loadFile->invoke($controller, '../config.php'))->toBeNull()
        ->and($loadFile->invoke($controller, 'devpack/does-not-exist.txt'))->toBeNull();
});

/**
 * Prerequisites:
 * - A provisioned CSC client has one allowlisted redirect and includes secret/foreign-looking fixture fields.
 *
 * Steps:
 * 1. Personalize the checked-in Postman environment for the active client.
 * 2. Assert only the public OAuth client ID and owned redirect URI are substituted.
 * 3. Assert client/webhook secrets, file paths, and foreign identifiers are absent.
 */
test('CSC dev pack — personalized environment exposes only owned public client configuration', function () {
    $redirect = new CscRedirectUriModelStub();
    $redirect->uris = [['uri' => 'https://client.example.test/callback']];
    [$registry] = csc_integrator_registry(redirect: $redirect);
    $controller = new TestableControllerPublicAPIV1CscDevPack($registry);
    $method = new ReflectionMethod(ControllerPublicAPIV1CscDevPack::class, 'personalizedEnvironmentJson');
    $method->setAccessible(true);
    $raw = $method->invoke($controller, [
        'csc_api_client_id' => 51,
        'keycloak_client_id' => 'oauth-public-client',
        'name' => 'Owned client',
        'client_secret' => 'must-not-leak',
        'webhook_secret' => 'also-must-not-leak',
        'foreign_client_id' => 'foreign-must-not-leak',
    ]);
    $environment = json_decode((string) $raw, true);
    $values = array_column($environment['values'] ?? [], 'value', 'key');

    expect($environment['name'])->toBe('Simplifi CSC — Owned client')
        ->and($values['oauth_client_id'] ?? null)->toBe('oauth-public-client')
        ->and($values['redirect_uri'] ?? null)->toBe('https://client.example.test/callback')
        ->and($raw)->not->toContain('must-not-leak')
        ->and($raw)->not->toContain('foreign-must-not-leak')
        ->and($raw)->not->toContain(DIR_SYSTEM);
});
