<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/ReferralsTestDoubles.php';

beforeEach(function () {
    $this->refHadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->refSavedRequestMethod = $this->refHadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (!$this->refHadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->refSavedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Unit harness; customer id is zero.
 *
 * Steps:
 * 1. GET index() without authentication.
 * 2. Assert **HTTP 401** and `authentication_required` before referral model loads.
 */
test('Referrals list API — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    [$registry, $load] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['authentication_required'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated referrer; invalid status query param.
 *
 * Steps:
 * 1. GET with `status=invalid`.
 * 2. Assert **HTTP 422** `referrals_invalid_status`.
 */
test('Referrals list API — invalid status filter returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = ref_registry_with_models(new ReferralsCustomerStub(1), new ReferralModelStub());
    $registry->get('request')->get = ['status' => 'invalid'];

    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['referrals_invalid_status'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated referrer; invalid sort query param.
 *
 * Steps:
 * 1. GET with `sort=not_a_column`.
 * 2. Assert **HTTP 422** `referrals_invalid_sort`.
 */
test('Referrals list API — invalid sort returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = ref_registry_with_models(new ReferralsCustomerStub(1), new ReferralModelStub());
    $registry->get('request')->get = ['sort' => 'not_a_column'];
    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['referrals_invalid_sort'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated referrer; invalid order query param.
 *
 * Steps:
 * 1. GET with `order=sideways`.
 * 2. Assert **HTTP 422** `referrals_invalid_order`.
 */
test('Referrals list API — invalid order returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry, $load] = ref_registry_with_models(new ReferralsCustomerStub(1), new ReferralModelStub());
    $registry->get('request')->get = ['order' => 'sideways'];
    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['referrals_invalid_order'])
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated referrer; stub returns one invite-only row (no referral_id).
 *
 * Steps:
 * 1. GET list with cooldown disabled.
 * 2. Assert `can_remind` is true and pagination keys are present.
 */
test('Referrals list API — maps list rows and can_remind when cooldown is zero', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->listResult = [
        'total' => 1,
        'items' => [
            [
                'invite_uuid' => 'inv-1',
                'referral_id' => 0,
                'referral_uuid' => null,
                'referral_status' => null,
                'email' => 'friend@example.com',
                'invite_status' => 'sent',
                'invite_sent_at' => '2026-01-01 10:00:00',
                'referral_created_at' => null,
                'fulfilled_at' => null,
                'last_activity_at' => '2026-01-01 10:00:00',
            ],
        ],
    ];
    $grant = new RoleEntitlementGrantStub();
    [$registry, $load] = ref_registry_with_models(
        new ReferralsCustomerStub(1),
        $model,
        $grant,
        null,
        new ReferralsConfigStub(0),
    );
    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($load->loadedModels)->toContain('referral/referral')
        ->and($load->loadedModels)->toContain('billing/role_entitlement_grant')
        ->and($c->json['data'][0]['can_remind'] ?? null)->toBeTrue()
        ->and($c->json['pagination']['total'] ?? null)->toBe(1);
});

/**
 * Prerequisites:
 * - Authenticated referrer.
 *
 * Steps:
 * 1. POST to list route.
 * 2. Assert **HTTP 405** `method_not_allowed`.
 */
test('Referrals list API — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new ReferralModelStub();
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(1), $model);
    $c = new TestableControllerPublicapiv1Referrals($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});
