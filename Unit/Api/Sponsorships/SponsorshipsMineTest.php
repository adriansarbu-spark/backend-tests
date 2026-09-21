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
 * @param array<string, mixed>  $query
 * @param array<string, object> $overrides
 *
 * @return array{
 *   0: Registry,
 *   1: SponsorshipsLoadStub,
 *   2: array{link: SponsorshipsModelStub, sponsorship: SponsorshipsModelStub},
 *   3: SponsorshipsCustomerStub
 * }
 */
function sponsorships_mine_fixture(
    array $query = [],
    array $overrides = [],
    ?SponsorshipsCustomerStub $customer = null,
): array {
    $customer ??= new SponsorshipsCustomerStub(customerId: 10, companyId: 0, roleId: 30, roleCode: 'personal');
    $linkModel = $overrides['partner/customer_link'] ?? new SponsorshipsModelStub([]);
    $sponsorshipModel = $overrides['partner/sponsorship']
        ?? new SponsorshipsModelStub(['getSponsorshipsForCustomer' => []]);
    $programModel = $overrides['partner/program'] ?? sponsorships_program_model();

    [$registry, $load] = sponsorships_registry(
        customer: $customer,
        models: [
            'partner/customer_link' => $linkModel,
            'partner/sponsorship' => $sponsorshipModel,
            'partner/program' => $programModel,
        ],
        query: $query,
    );

    return [
        $registry,
        $load,
        [
            'link' => $linkModel,
            'sponsorship' => $sponsorshipModel,
            'program' => $programModel,
        ],
        $customer,
    ];
}

/**
 * Prerequisites:
 * - No signed-in customer (id 0).
 *
 * Steps:
 * 1. GET sponsorships/mine.
 * 2. Assert HTTP 401 `unauthenticated`.
 * 3. Prove no models were loaded.
 */
test('Sponsorships — mine returns 401 when the caller is not signed in', function () {
    [$registry, $load] = sponsorships_mine_fixture(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMine::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([])
        ->and($controller->sendResponseCalls)->toBe(1);
});

/**
 * Prerequisites:
 * - Signed-in customer without the mine route permission.
 *
 * Steps:
 * 1. GET sponsorships/mine with empty permissions.
 * 2. Assert HTTP 403 `access_denied`.
 * 3. Prove sponsorship model was not loaded.
 */
test('Sponsorships — mine refuses callers missing route permission', function () {
    [$registry, $load] = sponsorships_mine_fixture();
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMine::class,
        $registry,
        [],
    );
    $controller->index();

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toBe(['access_denied'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Wrong HTTP verb.
 *
 * Steps:
 * 1. POST sponsorships/mine.
 * 2. Assert HTTP 405 before auth or models.
 */
test('Sponsorships — mine rejects non-GET methods before auth', function () {
    sponsorships_set_method('POST');
    [$registry, $load] = sponsorships_mine_fixture(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMine::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Signed-in beneficiary; sponsorship model returns two roster rows.
 *
 * Steps:
 * 1. GET sponsorships/mine.
 * 2. Assert HTTP 200 and `data.sponsorships` matches the stub list.
 * 3. Prove getSponsorshipsForCustomer was called with the customer id.
 */
test('Sponsorships — mine lists the signed-in customer sponsorships', function () {
    $rows = [
        [
            'link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'partner_name' => 'Acme Partner Co',
            'status' => 'active',
        ],
        [
            'link_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'partner_name' => 'Other Partner',
            'status' => 'revoked_by_customer',
        ],
    ];
    [$registry, $load, $models] = sponsorships_mine_fixture(overrides: [
        'partner/sponsorship' => new SponsorshipsModelStub([
            'getSponsorshipsForCustomer' => $rows,
        ]),
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMine::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['sponsorships'])->toBe($rows)
        ->and(sponsorships_calls($models['sponsorship'], 'getSponsorshipsForCustomer')[0]['args'])->toBe([10])
        ->and($load->loadedModels)->toBe(['partner/sponsorship']);
});

/**
 * Prerequisites:
 * - No signed-in customer.
 *
 * Steps:
 * 1. POST sponsorships/mine/revoke.
 * 2. Assert HTTP 401 `unauthenticated`.
 * 3. Prove no models were loaded.
 */
test('Sponsorships — mine/revoke returns 401 when the caller is not signed in', function () {
    sponsorships_set_method('POST');
    [$registry, $load] = sponsorships_mine_fixture(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
    $controller->index();

    expect($controller->statusCode)->toBe(401)
        ->and($controller->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Signed-in customer; body omits link_uuid.
 *
 * Steps:
 * 1. POST mine/revoke with empty payload.
 * 2. Assert HTTP 400 `link_uuid_required`.
 * 3. Prove revokeByCustomer was never called.
 */
test('Sponsorships — mine/revoke requires link_uuid', function () {
    sponsorships_set_method('POST');
    $link = new SponsorshipsModelStub([]);
    [$registry, $load] = sponsorships_mine_fixture(overrides: [
        'partner/customer_link' => $link,
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload([]);
    $controller->index();

    expect($controller->statusCode)->toBe(400)
        ->and($controller->json['error'])->toBe(['link_uuid_required'])
        ->and(sponsorships_calls($link, 'revokeByCustomer'))->toBe([])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Signed-in customer; revokeByCustomer returns link_not_found (foreign or unknown uuid).
 *
 * Steps:
 * 1. POST mine/revoke with a foreign link uuid.
 * 2. Assert HTTP 404 `link_not_found`.
 * 3. Prove afterRevocation side effects did not run (no program load for billing).
 */
test('Sponsorships — mine/revoke hides foreign links as not found', function () {
    sponsorships_set_method('POST');
    $link = new SponsorshipsModelStub([
        'revokeByCustomer' => ['ok' => false, 'error' => 'link_not_found'],
    ]);
    [$registry, $load, $models] = sponsorships_mine_fixture(overrides: [
        'partner/customer_link' => $link,
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['link_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']);
    $controller->index();

    expect($controller->statusCode)->toBe(404)
        ->and($controller->json['error'])->toBe(['link_not_found'])
        ->and(sponsorships_calls($link, 'revokeByCustomer')[0]['args'])->toBe([
            10,
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ])
        ->and($load->loadedModels)->toBe(['partner/customer_link'])
        ->and(sponsorships_calls($models['program'], 'getProgramById'))->toBe([]);
});

/**
 * Prerequisites:
 * - Signed-in customer; link already revoked (conflict).
 *
 * Steps:
 * 1. POST mine/revoke.
 * 2. Assert HTTP 409 with the model error code.
 */
test('Sponsorships — mine/revoke maps non-not-found failures to 409', function () {
    sponsorships_set_method('POST');
    $link = new SponsorshipsModelStub([
        'revokeByCustomer' => ['ok' => false, 'error' => 'already_revoked'],
    ]);
    [$registry] = sponsorships_mine_fixture(overrides: [
        'partner/customer_link' => $link,
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
    $controller->index();

    expect($controller->statusCode)->toBe(409)
        ->and($controller->json['error'])->toBe(['already_revoked']);
});

/**
 * Prerequisites:
 * - Signed-in customer; revoke succeeds with an unbilled raw link (afterRevocation no-op).
 *
 * Steps:
 * 1. POST mine/revoke with a valid link_uuid.
 * 2. Assert HTTP 200, `data.revoked` true, and refreshed sponsorships list.
 * 3. Prove revokeByCustomer was called; markLinkUnbilled was not (no billed_at).
 */
test('Sponsorships — mine/revoke succeeds and refreshes the mine list', function () {
    sponsorships_set_method('POST');
    $refreshed = [
        ['link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'status' => 'revoked_by_customer'],
    ];
    $raw = [
        'link_id' => 77,
        'program_id' => 1,
        'program_plan_id' => 5,
        'billed_at' => null,
    ];
    $link = new SponsorshipsModelStub([
        'revokeByCustomer' => ['ok' => true, 'raw' => $raw],
    ]);
    $sponsorship = new SponsorshipsModelStub([
        'getSponsorshipsForCustomer' => $refreshed,
    ]);
    [$registry, $load, $models] = sponsorships_mine_fixture(overrides: [
        'partner/customer_link' => $link,
        'partner/sponsorship' => $sponsorship,
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['revoked'])->toBeTrue()
        ->and($controller->json['data']['sponsorships'])->toBe($refreshed)
        ->and(sponsorships_calls($link, 'revokeByCustomer')[0]['args'])->toBe([
            10,
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        ])
        ->and(sponsorships_calls($link, 'markLinkUnbilled'))->toBe([])
        ->and(sponsorships_calls($models['program'], 'getProgramById'))->toBe([])
        ->and($load->loadedModels)->toBe(['partner/customer_link', 'partner/sponsorship']);
});

/**
 * Prerequisites:
 * - Signed-in customer; revoke succeeds with a billed raw link so afterRevocation runs.
 * - Plan lookup returns null so billing exits before any Stripe / DbMutex work.
 *
 * Steps:
 * 1. POST mine/revoke with billed raw link.
 * 2. Assert HTTP 200 and revoke still succeeds for the beneficiary.
 * 3. Prove afterRevocation looked up program + plan; markLinkUnbilled was not called.
 */
test('Sponsorships — mine/revoke triggers afterRevocation for billed links', function () {
    sponsorships_set_method('POST');
    $raw = [
        'link_id' => 88,
        'program_id' => 1,
        'program_plan_id' => 5,
        'billed_at' => '2026-08-01 12:00:00',
    ];
    $link = new SponsorshipsModelStub([
        'revokeByCustomer' => ['ok' => true, 'raw' => $raw],
    ]);
    $program = sponsorships_program_model();
    $sponsorship = new SponsorshipsModelStub([
        'getSponsorshipsForCustomer' => [],
        'getPlanById' => null,
    ]);
    [$registry, $load, $models] = sponsorships_mine_fixture(overrides: [
        'partner/customer_link' => $link,
        'partner/program' => $program,
        'partner/sponsorship' => $sponsorship,
    ]);
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->setPostPayload(['link_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['revoked'])->toBeTrue()
        ->and(sponsorships_calls($models['program'], 'getProgramById')[0]['args'])->toBe([1])
        ->and(sponsorships_calls($sponsorship, 'getPlanById')[0]['args'])->toBe([5])
        ->and(sponsorships_calls($link, 'markLinkUnbilled'))->toBe([])
        ->and(in_array('partner/customer_link', $load->loadedModels, true))->toBeTrue()
        ->and(in_array('partner/program', $load->loadedModels, true))->toBeTrue()
        ->and(in_array('partner/sponsorship', $load->loadedModels, true))->toBeTrue();
});

/**
 * Prerequisites:
 * - Wrong HTTP verb for mine/revoke.
 *
 * Steps:
 * 1. GET mine/revoke.
 * 2. Assert HTTP 405 before auth or models.
 */
test('Sponsorships — mine/revoke rejects non-POST methods before auth', function () {
    sponsorships_set_method('GET');
    [$registry, $load] = sponsorships_mine_fixture(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
    );
    $controller = sponsorships_controller(
        TestableControllerPublicAPIV1SponsorshipsMineRevoke::class,
        $registry,
        sponsorships_all_permissions(),
    );
    $controller->index();

    expect($controller->statusCode)->toBe(405)
        ->and($controller->json['error'])->toBe(['method_not_allowed'])
        ->and($load->loadedModels)->toBe([]);
});
