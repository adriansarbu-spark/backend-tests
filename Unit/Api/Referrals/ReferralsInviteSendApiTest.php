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
 * - Model rejects send (rate limit).
 *
 * Steps:
 * 1. POST invite send with valid-looking email.
 * 2. Assert **HTTP 429** and limit reason from model.
 */
test('Referrals invite send API — rate limit from model returns 429', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new ReferralModelStub();
    $model->canSendResult = ['ok' => false, 'reason' => 'limit_referrer_daily'];
    [$registry] = ref_registry_with_models(
        new ReferralsCustomerStub(1),
        $model,
        null,
        new ReferralsAccountCustomerModelStub(0),
    );
    $c = new TestableControllerPublicapiv1ReferralsInviteSend($registry);
    $c->setPostPayload(['email' => 'friend@example.com']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['limit_referrer_daily']);
});

/**
 * Prerequisites:
 * - Model rejects invalid email.
 *
 * Steps:
 * 1. POST invite send.
 * 2. Assert **HTTP 400** `invalid_email`.
 */
test('Referrals invite send API — invalid email returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new ReferralModelStub();
    $model->canSendResult = ['ok' => false, 'reason' => 'invalid_email'];
    [$registry] = ref_registry_with_models(
        new ReferralsCustomerStub(1),
        $model,
        null,
        new ReferralsAccountCustomerModelStub(0),
    );
    $c = new TestableControllerPublicapiv1ReferralsInviteSend($registry);
    $c->setPostPayload(['email' => 'not-an-email']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_email']);
});
