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
 * - Unauthenticated customer.
 *
 * Steps:
 * 1. GET invite code.
 * 2. Assert **HTTP 401**.
 */
test('Referrals invite API — unauthenticated GET returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), new ReferralModelStub());
    $c = new TestableControllerPublicapiv1ReferralsInvite($registry);
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['authentication_required']);
});

/**
 * Prerequisites:
 * - Authenticated referrer; model returns active code.
 *
 * Steps:
 * 1. GET invite.
 * 2. Assert **HTTP 200** with `code` and `active`.
 */
test('Referrals invite API — GET returns active invite code', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->activeInvite = ['code' => 'ABC123', 'active' => true];
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(1), $model);
    $c = new TestableControllerPublicapiv1ReferralsInvite($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['code'] ?? null)->toBe('ABC123')
        ->and($c->json['data']['active'] ?? null)->toBeTrue();
});

/**
 * Prerequisites:
 * - Authenticated referrer; POST with rotate true.
 *
 * Steps:
 * 1. POST `{rotate: true}`.
 * 2. Assert model rotate path was used.
 */
test('Referrals invite API — POST rotate calls rotateInviteForReferrer', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new ReferralModelStub();
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(1), $model);
    $c = new TestableControllerPublicapiv1ReferralsInvite($registry);
    $c->setPostPayload(['rotate' => true]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($model->rotateCalled)->toBeTrue();
});
