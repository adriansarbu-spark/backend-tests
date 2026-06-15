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
 * 1. GET status without authentication.
 * 2. Assert **HTTP 401** `authentication_required`.
 */
test('Referrals status API — unauthenticated customer returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), new ReferralModelStub());
    $c = new TestableControllerPublicapiv1ReferralsStatus($registry);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['authentication_required']);
});

/**
 * Prerequisites:
 * - Authenticated referrer.
 *
 * Steps:
 * 1. POST to status route.
 * 2. Assert **HTTP 405** `method_not_allowed`.
 */
test('Referrals status API — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(1), new ReferralModelStub());
    $c = new TestableControllerPublicapiv1ReferralsStatus($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Authenticated referrer; stubs return funnel and reward totals.
 *
 * Steps:
 * 1. GET status.
 * 2. Assert **HTTP 200** with `as_referred`, `funnel.invites_sent_total`, and sorted `funnel.rewards_earned`.
 */
test('Referrals status API — success returns as_referred and funnel shape', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->asReferred = ['status' => 'pending', 'fulfilled_at' => null, 'created_at' => '2026-01-01 00:00:00'];
    $model->countsByStatus = ['pending' => 1, 'fulfilled' => 2, 'cancelled' => 0];
    $model->inviteCounts = ['total' => 5];

    $grant = new RoleEntitlementGrantStub();
    $grant->rewardTotals = ['zebra_ent' => 2, 'alpha_ent' => 1];

    [$registry, $load] = ref_registry_with_models(
        new ReferralsCustomerStub(1),
        $model,
        $grant,
    );
    $c = new TestableControllerPublicapiv1ReferralsStatus($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($load->loadedModels)->toContain('referral/referral')
        ->and($load->loadedModels)->toContain('billing/role_entitlement_grant')
        ->and($c->json['data']['as_referred']['has_referral'] ?? null)->toBeTrue()
        ->and($c->json['data']['funnel']['invites_sent_total'] ?? null)->toBe(5)
        ->and($c->json['data']['funnel']['referrals_completed'] ?? null)->toBe(2);

    $earned = $c->json['data']['funnel']['rewards_earned'] ?? [];
    expect($earned)->toHaveCount(2)
        ->and($earned[0]['entitlement_code'] ?? '')->toBe('alpha_ent')
        ->and($earned[1]['entitlement_code'] ?? '')->toBe('zebra_ent');
});
