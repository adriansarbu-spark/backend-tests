<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/ActiveRoleTestDoubles.php';

/**
 * Unit tests for POST /account/active-role ownership and persistence.
 */

beforeEach(function () {
    $this->activeRoleHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->activeRoleSavedMethod = $this->activeRoleHadMethod
        ? $_SERVER['REQUEST_METHOD']
        : null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
});

afterEach(function () {
    if ($this->activeRoleHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->activeRoleSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - Isolated active-role harness; no authenticated customer.
 *
 * Steps:
 * 1. POST a role UUID.
 * 2. Assert 401 before role models load or any persist call.
 */
test('Account active-role API — unauthenticated caller is rejected before role lookup', function () {
    [$controller, $load, $roleModel, $companyModel] = active_role_controller(customerId: 0);
    $controller->setPostPayload(['role_uuid' => '11111111-2222-4333-8444-555555555555']);

    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($roleModel->lookupCalls)->toBe([])
        ->and($companyModel->setCalls)->toBe([])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Authenticated customer 51.
 *
 * Steps:
 * 1. POST without role_uuid.
 * 2. Assert 400 role_uuid_required and that company role is not persisted.
 */
test('Account active-role API — missing role_uuid is rejected before persistence', function () {
    [$controller, $load, $roleModel, $companyModel] = active_role_controller();
    $controller->setPostPayload([]);

    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['role_uuid_required'])
        ->and($load->loadedModels)->toBe([])
        ->and($roleModel->ensureMissingUuidsCalls)->toBe([])
        ->and($companyModel->setCalls)->toBe([]);
});

/**
 * Prerequisites:
 * - Customer 51 is authenticated. The submitted role UUID is well-formed but
 *   not owned by this customer (the role model returns 0).
 *
 * Steps:
 * 1. POST the foreign role_uuid.
 * 2. Assert HTTP 403 role_not_allowed, ensureMissingUuids ran for this
 *    customer, and setUserCompanyAndRole was not called.
 */
test('Account active-role API — foreign role_uuid is forbidden and not persisted', function () {
    $foreignUuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    [$controller, $load, $roleModel, $companyModel] = active_role_controller();
    $roleModel->ownedRoles = [
        '11111111-2222-4333-8444-555555555555' => 42,
    ];
    $controller->setPostPayload(['role_uuid' => $foreignUuid]);

    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['role_not_allowed'])
        ->and($load->loadedModels)->toBe(['account/customer_role'])
        ->and($roleModel->ensureMissingUuidsCalls)->toBe([51])
        ->and($roleModel->lookupCalls)->toBe([[$foreignUuid, 51]])
        ->and($companyModel->setCalls)->toBe([])
        ->and($roleModel->uuidLookupCalls)->toBe([])
        ->and($controller->json['data'] ?? [])->not->toHaveKey('role_uuid');
});

/**
 * Prerequisites:
 * - Customer 51 owns role UUID 11111111-2222-4333-8444-555555555555 (role id 42)
 *   bound to a company UUID.
 *
 * Steps:
 * 1. POST that role_uuid.
 * 2. Assert setUserCompanyAndRole is called with 42 and the response returns
 *    the owned role and company UUIDs.
 */
test('Account active-role API — owned role_uuid is persisted as the active role', function () {
    $ownedUuid = '11111111-2222-4333-8444-555555555555';
    $companyUuid = '99999999-aaaa-4bbb-8ccc-dddddddddddd';
    [$controller, $load, $roleModel, $companyModel] = active_role_controller();
    $roleModel->ownedRoles = [$ownedUuid => 42];
    $roleModel->uuidsByRoleId = [
        42 => ['role_uuid' => $ownedUuid, 'company_uuid' => $companyUuid],
    ];
    $controller->setPostPayload(['role_uuid' => $ownedUuid]);

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($load->loadedModels)->toBe(['account/customer_role', 'account/company'])
        ->and($roleModel->ensureMissingUuidsCalls)->toBe([51])
        ->and($roleModel->lookupCalls)->toBe([[$ownedUuid, 51]])
        ->and($companyModel->setCalls)->toBe([42])
        ->and($roleModel->uuidLookupCalls)->toBe([42])
        ->and($controller->json['data']['role_uuid'])->toBe($ownedUuid)
        ->and($controller->json['data']['company_uuid'])->toBe($companyUuid)
        ->and($controller->sendResponseCalls)->toBe(1);
});
