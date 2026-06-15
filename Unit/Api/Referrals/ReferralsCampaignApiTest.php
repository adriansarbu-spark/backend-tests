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
 * - Public campaign endpoint.
 *
 * Steps:
 * 1. POST to campaign route.
 * 2. Assert **HTTP 405** `method_not_allowed`.
 */
test('Referrals campaign API — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), new ReferralModelStub());
    $c = new TestableControllerPublicapiv1ReferralsCampaign($registry);
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Model returns no default campaign.
 *
 * Steps:
 * 1. GET campaign.
 * 2. Assert **HTTP 404** `referral_campaign_not_configured`.
 */
test('Referrals campaign API — missing configuration returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->defaultCampaign = null;
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $c = new TestableControllerPublicapiv1ReferralsCampaign($registry);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['referral_campaign_not_configured']);
});

/**
 * Prerequisites:
 * - Model returns campaign payload.
 *
 * Steps:
 * 1. GET campaign without auth (anonymous allowlist).
 * 2. Assert **HTTP 200** and campaign data passthrough.
 */
test('Referrals campaign API — success passes through campaign data', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $model = new ReferralModelStub();
    $model->defaultCampaign = ['campaign_uuid' => 'camp-abc', 'rewards' => []];
    [$registry] = ref_registry_with_models(new ReferralsCustomerStub(0), $model);
    $c = new TestableControllerPublicapiv1ReferralsCampaign($registry);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['campaign_uuid'] ?? null)->toBe('camp-abc');
});
