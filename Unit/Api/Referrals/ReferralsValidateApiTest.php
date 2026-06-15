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
 * - No referral code query parameter.
 *
 * Steps:
 * 1. GET validate without `code`.
 * 2. Assert **HTTP 400** `referral_code_required`.
 */
test('Referrals validate API — missing code returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $c = new TestableControllerPublicapiv1ReferralsValidate($registry);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['referral_code_required']);
});

/**
 * Prerequisites:
 * - Stub finds no invite for code.
 *
 * Steps:
 * 1. GET with unknown code.
 * 2. Assert `data.valid` is false.
 */
test('Referrals validate API — unknown code returns valid false', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->inviteByCode = null;
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $registry->get('request')->get = ['code' => 'UNKNOWN'];
    $c = new TestableControllerPublicapiv1ReferralsValidate($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['valid'] ?? null)->toBeFalse();
});

/**
 * Prerequisites:
 * - Stub finds active invite.
 *
 * Steps:
 * 1. GET with known code.
 * 2. Assert `data.valid` is true.
 */
test('Referrals validate API — known code returns valid true', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->inviteByCode = ['invite_id' => 1];
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $registry->get('request')->get = ['code' => 'GOODCODE'];
    $c = new TestableControllerPublicapiv1ReferralsValidate($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['valid'] ?? null)->toBeTrue();
});
