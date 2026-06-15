<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'team/members.php';
require_once __DIR__ . '/_support/TeamMembersTestDoubles.php';
require_once __DIR__ . '/_support/TeamMembersUnitHelpers.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1TeamMembers} (auth, list, terminate routing).
 * Low-level controller tests without HTTP or database.
 */

beforeEach(function () {
    $this->tmHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->tmSavedRequestMethod = $this->tmHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $this->tmHadRequestUri = array_key_exists('REQUEST_URI', $_SERVER);
    $this->tmSavedRequestUri = $this->tmHadRequestUri ? $_SERVER['REQUEST_URI'] : null;
});

afterEach(function () {
    if (! $this->tmHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->tmSavedRequestMethod;
    }
    if (! $this->tmHadRequestUri) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $this->tmSavedRequestUri;
    }
});

/**
 * Prerequisites:
 * - Unit harness (registry stubs); no database.
 *
 * Steps:
 * 1. Call index() with customer id 0 and GET.
 * 2. Assert 401 unauthenticated, checkPlugin once, sendResponse once, team model not loaded.
 */
test('Team members API — unauthenticated customer returns 401 before list logic', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(0, 10), $inv);
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with missing company context (company id 0).
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 400 company_context_required and no model loads.
 */
test('Team members API — authenticated user without company returns 400 company_context_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 0), $inv);
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['company_context_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated customer with valid company; stub list returns rows and totals.
 *
 * Steps:
 * 1. GET with query filters (role_code, member_status, representative_status, search, pagination).
 * 2. Assert model receives normalized args, response includes company_uuid, members, pagination.total_pages.
 */
test('Team members API — GET list forwards filters and returns data plus pagination meta', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $inv = new TeamMembersTeamInvitationModelStub();
    $inv->companyUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $inv->listResult = [
        'items'    => [['role_uuid' => 'r1', 'email' => 'a@b.co']],
        'page'     => 2,
        'per_page' => 10,
        'total'    => 25,
    ];
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(5, 99), $inv);
    $registry->get('request')->get = [
        'page'                  => '2',
        'per_page'              => '10',
        'search'                => ' ann ',
        'sort'                  => 'email',
        'order'                 => 'desc',
        'role_code'             => 'admin',
        'member_status'         => 'any',
        'representative_status' => 'pending',
    ];
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe(['account/team_invitation'])
        ->and($inv->lastMembersPagedArgs)->toMatchArray([
            'company_id'            => 99,
            'page'                  => 2,
            'per_page'              => 10,
            'search'                => 'ann',
            'sort'                  => 'email',
            'order'                 => 'desc',
            'role_code'             => 'admin',
            'member_status'         => 'any',
            'representative_status' => 'pending',
        ])
        ->and($c->json['data']['company_uuid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
        ->and($c->json['data']['members'])->toHaveCount(1)
        ->and($c->json['pagination']['total'])->toBe(25)
        ->and($c->json['pagination']['total_pages'])->toBe(3);
});

/**
 * Prerequisites:
 * - Authenticated customer with company; HTTP method not GET or terminate-POST.
 *
 * Steps:
 * 1. PUT index() without terminate action.
 * 2. Assert 405 method_not_allowed and allowedHeaders lists GET, POST, OPTIONS.
 */
test('Team members API — unsupported method on list route returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10), $inv);
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS']);
});

/**
 * Prerequisites:
 * - Terminate action detected from members_action query flag; method is GET.
 *
 * Steps:
 * 1. Set members_action=terminate and GET.
 * 2. Assert 405 before admin or model checks (no representative model load).
 */
test('Team members API — terminate action with GET returns 405 before authorization', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10), $inv);
    $registry->get('request')->get = [
        'members_action' => 'terminate',
        'role_uuid'      => 'deadbeef-dead-beef-dead-beefdeadbeef',
    ];
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->allowedHeaders)->toBe(['POST', 'OPTIONS'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - SEO-style path exposes terminate segment; method GET.
 *
 * Steps:
 * 1. Set REQUEST_URI to .../team/members/{uuid}/terminate and GET.
 * 2. Assert 405 (terminate requires POST).
 */
test('Team members API — terminate path from REQUEST_URI with GET returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/publicapi/v1/team/members/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/terminate';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10), $inv);
    $c = tm_make_controller($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - POST terminate; caller is not company admin per representative stub.
 *
 * Steps:
 * 1. members_action=terminate, role_uuid present, POST.
 * 2. Assert 403 admin_role_required after loading company/representative only (not team_invitation).
 */
test('Team members API — terminate requires company admin role', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    $rep = new TeamMembersRepresentativeStub(false);
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10, 77), $inv, $rep);
    $registry->get('request')->get = [
        'members_action' => 'terminate',
        'role_uuid'      => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['admin_role_required'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Admin POST terminate without role_uuid in query (empty).
 *
 * Steps:
 * 1. members_action=terminate, POST, empty post.
 * 2. Assert 400 role_uuid_required after representative check.
 */
test('Team members API — terminate without role_uuid returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10, 77), $inv);
    $registry->get('request')->get = [
        'members_action' => 'terminate',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['role_uuid_required'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Customer has TOTP enrolled (non-empty secret); POST terminate with admin and role_uuid but no totp_code.
 *
 * Steps:
 * 1. POST terminate with empty body keys for totp.
 * 2. Assert 400 totp_code_required; team_invitation model not loaded.
 */
test('Team members API — terminate with TOTP enrolled requires totp_code in body', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    $customer = new TeamMembersCustomerStub(1, 10, 77, 'JBSWY3DPEHPK3PXP');
    [$registry, $load] = tm_registry_with_stubs($customer, $inv);
    $registry->get('request')->get = [
        'role_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'action'    => 'terminate',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload(['reason' => 'left company']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['totp_code_required'])
        ->and($load->loadedModels)->toBe(['company/representative']);
});

/**
 * Prerequisites:
 * - Admin without TOTP; stub terminate returns domain error member_not_found.
 *
 * Steps:
 * 1. POST terminate with role_uuid from route-style query.
 * 2. Assert HTTP 404 and error payload; both models loaded in order.
 */
test('Team members API — terminate maps member_not_found to 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    $inv->terminateResult = ['error' => 'member_not_found'];
    [$registry, $load] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10, 77), $inv);
    $registry->get('request')->get = [
        'route' => 'publicapi/v1/team/members/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb/terminate',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['member_not_found'])
        ->and($load->loadedModels)->toBe(['company/representative', 'account/team_invitation']);
});

/**
 * Prerequisites:
 * - Stub returns seal_revocation_failed with seal uuid (downstream dependency failure).
 *
 * Steps:
 * 1. POST terminate.
 * 2. Assert 502, error code, and data.error_seal_uuid present for operators.
 */
test('Team members API — terminate maps seal_revocation_failed to 502 with seal diagnostics', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    $inv->terminateResult = [
        'error'           => 'seal_revocation_failed',
        'error_seal_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'seals_revoked'   => 1,
    ];
    [$registry] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10, 77), $inv);
    $registry->get('request')->get = [
        'role_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'action'    => 'terminate',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(502)
        ->and($c->json['error'])->toBe(['seal_revocation_failed'])
        ->and($c->json['data']['error_seal_uuid'])->toBe('cccccccc-cccc-cccc-cccc-cccccccccccc');
});

/**
 * Prerequisites:
 * - Successful terminate payload from model (no error key).
 *
 * Steps:
 * 1. POST terminate.
 * 2. Assert 200 shape: data.member, representative_revoked, seals_revoked, requests_cancelled integers/bools.
 */
test('Team members API — successful terminate returns member summary and revocation flags', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $inv = new TeamMembersTeamInvitationModelStub();
    $inv->terminateResult = [
        'member'                 => ['role_uuid' => 'u1', 'member_status' => 'terminated'],
        'representative_revoked' => true,
        'seals_revoked'          => 2,
        'requests_cancelled'     => 1,
    ];
    [$registry] = tm_registry_with_stubs(new TeamMembersCustomerStub(1, 10, 77), $inv);
    $registry->get('request')->get = [
        'role_uuid' => 'u1',
        'action'    => 'terminate',
    ];
    $c = tm_make_controller($registry);
    $c->setPostPayload(['reason' => 'role change']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['member']['role_uuid'])->toBe('u1')
        ->and($c->json['data']['representative_revoked'])->toBeTrue()
        ->and($c->json['data']['seals_revoked'])->toBe(2)
        ->and($c->json['data']['requests_cancelled'])->toBe(1);
});
