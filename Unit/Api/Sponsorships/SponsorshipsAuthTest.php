<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/SponsorshipsTestDoubles.php';

beforeEach(function () {
    $this->spHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->spSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    sponsorships_set_method('GET');
});

afterEach(function () {
    if ($this->spHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->spSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - Unit harness only; customer id is 0 (not signed in).
 *
 * Steps:
 * 1. Call each console GET endpoint through its testable controller.
 * 2. Assert HTTP 401 with `unauthenticated`.
 * 3. Prove no models were loaded.
 */
test('Sponsorships — unauthenticated console routes return 401 before model work', function (
    string $class,
    string $route,
) {
    [$registry, $load] = sponsorships_registry(new SponsorshipsCustomerStub(0, 20, 30));
    $controller = sponsorships_controller($class, $registry, sponsorships_all_permissions());
    $controller->index();

    expect($controller->checkPluginCalls)->toBe(1)
        ->and($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($controller->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
})->with([
    [TestableControllerPublicAPIV1SponsorshipsProgram::class, 'publicapi/v1/sponsorships/program'],
    [TestableControllerPublicAPIV1SponsorshipsOverview::class, 'publicapi/v1/sponsorships/overview'],
    [TestableControllerPublicAPIV1SponsorshipsEmailPreview::class, 'publicapi/v1/sponsorships/email_preview'],
    [TestableControllerPublicAPIV1SponsorshipsMine::class, 'publicapi/v1/sponsorships/mine'],
]);

/**
 * Prerequisites:
 * - Signed-in customer whose active role has no company (company id 0).
 *
 * Steps:
 * 1. Call each company-scoped console GET.
 * 2. Assert HTTP 400 `company_context_required`.
 * 3. Prove authorization stopped before model load.
 */
test('Sponsorships — company-scoped console routes require a company context', function (
    string $class,
) {
    [$registry, $load] = sponsorships_registry(new SponsorshipsCustomerStub(10, 0, 30));
    $controller = sponsorships_controller($class, $registry, sponsorships_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
})->with([
    TestableControllerPublicAPIV1SponsorshipsProgram::class,
    TestableControllerPublicAPIV1SponsorshipsOverview::class,
    TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
]);

/**
 * Prerequisites:
 * - Authenticated company member who is not a company admin.
 *
 * Steps:
 * 1. Call each admin-gated console GET with full route permissions.
 * 2. Assert HTTP 403 `admin_role_required`.
 * 3. Prove only the entitlement assignment model was loaded for the role check.
 */
test('Sponsorships — non-admin company role is refused before partner program work', function (
    string $class,
    string $method,
    string $route,
    array $modelsBeforeAdmin,
) {
    sponsorships_set_method($method);
    $assignment = new SponsorshipsModelStub(['isRoleAdminForCompany' => false]);
    [$registry, $load] = sponsorships_registry(
        customer: new SponsorshipsCustomerStub(10, 20, 30, 'employee'),
        models: ['billing/entitlement_assignment' => $assignment],
        query: ['route' => $route],
    );
    $controller = sponsorships_controller($class, $registry, sponsorships_all_permissions());
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe($modelsBeforeAdmin)
        ->and(sponsorships_calls($assignment, 'isRoleAdminForCompany')[0]['args'])->toBe([30, 20]);
})->with([
    // program checks admin before route permission
    [
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        'GET',
        'publicapi/v1/sponsorships/program',
        ['billing/entitlement_assignment'],
    ],
    // overview / email_preview check route permission first, then admin
    [
        TestableControllerPublicAPIV1SponsorshipsOverview::class,
        'GET',
        'publicapi/v1/sponsorships/overview',
        ['billing/entitlement_assignment'],
    ],
    [
        TestableControllerPublicAPIV1SponsorshipsEmailPreview::class,
        'GET',
        'publicapi/v1/sponsorships/email_preview',
        ['billing/entitlement_assignment'],
    ],
]);

/**
 * Prerequisites:
 * - Authenticated company admin without the public API route permission.
 *
 * Steps:
 * 1. Call overview and email_preview (permission checked before any model).
 * 2. Assert HTTP 403 `access_denied`.
 * 3. Prove no models were loaded.
 */
test('Sponsorships — missing route permission denies overview and email preview before models', function (
    string $class,
    string $route,
) {
    [$registry, $load] = sponsorships_registry(
        query: ['route' => $route],
    );
    $controller = sponsorships_controller($class, $registry, ['get' => [], 'post' => [], 'patch' => []]);
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
})->with([
    [TestableControllerPublicAPIV1SponsorshipsOverview::class, 'publicapi/v1/sponsorships/overview'],
    [TestableControllerPublicAPIV1SponsorshipsEmailPreview::class, 'publicapi/v1/sponsorships/email_preview'],
    [TestableControllerPublicAPIV1SponsorshipsMine::class, 'publicapi/v1/sponsorships/mine'],
]);

/**
 * Prerequisites:
 * - Authenticated company admin without PATCH/GET permission on program.
 * - Program controller loads entitlement + program before the permission gate.
 *
 * Steps:
 * 1. GET program without route permission.
 * 2. Assert HTTP 403 `access_denied`.
 * 3. Prove partner program was already loaded (permission is after the admin/program fetch).
 */
test('Sponsorships — missing program route permission still returns access_denied after admin gate', function () {
    $assignment = new SponsorshipsModelStub(['isRoleAdminForCompany' => true]);
    $program = sponsorships_program_model();
    [$registry, $load] = sponsorships_registry(
        models: [
            'billing/entitlement_assignment' => $assignment,
            'partner/program' => $program,
        ],
        query: ['route' => 'publicapi/v1/sponsorships/program'],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsProgram::class,
        $registry,
        ['get' => [], 'patch' => []],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'partner/program']);
});
