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
 * - Authenticated referrer; POST body has no target fields.
 *
 * Steps:
 * 1. POST remind with empty body.
 * 2. Assert **HTTP 422** `referrals_remind_missing_target`.
 */
test('Referrals remind API — missing target returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(1), new ReferralModelStub());
    $c = new TestableControllerPublicapiv1ReferralsRemind($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['referrals_remind_missing_target']);
});

/**
 * Prerequisites:
 * - Cooldown active for referral row.
 *
 * Steps:
 * 1. POST remind by referral_uuid for pending referral.
 * 2. Assert **HTTP 429** `referrals_remind_cooldown`.
 */
test('Referrals remind API — cooldown returns 429 with retry_after_seconds', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $model = new ReferralModelStub();
    $model->referralRowForReferrer = [
        'referral_id' => 5,
        'referral_uuid' => '11111111-1111-1111-1111-111111111111',
        'status' => 'pending',
        'referred_email' => 'friend@example.com',
    ];
    $model->lastRemindForReferral = gmdate('Y-m-d H:i:s');
    [$registry] = ref_registry_with_models(
        new ReferralsCustomerStub(1),
        $model,
        null,
        new ReferralsAccountCustomerModelStub(0),
        new ReferralsConfigStub(3600),
    );
    $c = new TestableControllerPublicapiv1ReferralsRemind($registry);
    $c->setPostPayload(['referral_uuid' => '11111111-1111-1111-1111-111111111111']);
    $c->index();

    expect($c->statusCode)->toBe(429)
        ->and($c->json['error'])->toBe(['referrals_remind_cooldown'])
        ->and(isset($c->json['data']['retry_after_seconds']))->toBeTrue();
});
