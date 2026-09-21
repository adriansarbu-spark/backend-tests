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
 * - Company admin with overview permission; company has no partner program.
 *
 * Steps:
 * 1. GET sponsorships/overview.
 * 2. Assert HTTP 404 `program_not_found`.
 */
test('Sponsorships — overview returns 404 when program is missing', function () {
    $program = new SponsorshipsModelStub(['getProgramForCompany' => null]);
    [$registry] = sponsorships_registry(
        models: [
            'billing/entitlement_assignment' => new SponsorshipsModelStub(['isRoleAdminForCompany' => true]),
            'partner/program' => $program,
        ],
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsOverview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['program_not_found']);
});

/**
 * Prerequisites:
 * - Company admin; program stub with counters.
 *
 * Steps:
 * 1. GET sponsorships/overview.
 * 2. Assert `data` carries program identity fields and counter keys.
 * 3. Prove getOverviewCounters was called with the program id.
 */
test('Sponsorships — overview returns program identity and lifecycle counters', function () {
    [$registry, $load, $models] = sponsorships_console_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsOverview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data'])->toHaveKeys([
            'program_uuid',
            'program_name',
            'status',
            'billing_mode',
            'counters',
        ])
        ->and($controller->json['data']['program_uuid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and($controller->json['data']['program_name'])->toBe('Acme Partner Program')
        ->and($controller->json['data']['status'])->toBe('active')
        ->and($controller->json['data']['billing_mode'])->toBe('per_invitation')
        ->and($controller->json['data']['counters'])->toMatchArray([
            'invited' => 2,
            'onboarding' => 1,
            'active' => 3,
            'revoked' => 0,
            'billed' => 4,
        ])
        ->and(sponsorships_calls($models['program'], 'getOverviewCounters')[0]['args'])->toBe([1])
        ->and($load->loadedModels)->toBe(['billing/entitlement_assignment', 'partner/program']);
});

/**
 * Prerequisites:
 * - Wrong HTTP verb.
 *
 * Steps:
 * 1. POST overview.
 * 2. Assert HTTP 405 before auth or models.
 */
test('Sponsorships — overview rejects non-GET methods before auth', function () {
    sponsorships_set_method('POST');
    [$registry, $load] = sponsorships_registry(new SponsorshipsCustomerStub(0, 0, 0));
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsOverview::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe([]);
});
