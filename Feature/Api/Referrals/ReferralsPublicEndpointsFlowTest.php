<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ReferralsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Referrals - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    ReferralsApiHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Validate endpoint is allowlisted (no bearer required).
 *
 * Steps:
 * 1. **GET** `/referrals/validate` with a nonsense code.
 * 2. Expect **HTTP 200** and `data.valid` false.
 */
test('Referrals - validate returns valid false for unknown code without auth', function () {
    [$status, $json, $raw] = ReferralsApiHelper::getJson(
        ReferralsApiHelper::validateUrl('ZZZ-NOT-A-REAL-CODE-' . gmdate('YmdHis')),
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 600);
    expect($status)->toBe(200, "Validate failed.\n{$debug}");
    expect($json['data']['valid'] ?? null)->toBeFalse();
});

/**
 * Prerequisites:
 * - Campaign endpoint is allowlisted (no bearer required).
 *
 * Steps:
 * 1. **GET** `/referrals/campaign` without auth.
 * 2. Expect **HTTP 200** with campaign payload, or **HTTP 404** when not configured in this environment.
 */
test('Referrals - public campaign endpoint responds without auth', function () {
    [$status, $json, $raw] = ReferralsApiHelper::getJson(ReferralsApiHelper::campaignUrl());

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 600);
    expect(in_array($status, [200, 404], true))->toBeTrue("Unexpected campaign status.\n{$debug}");

    if ($status === 404) {
        expect(ReferralsApiHelper::joinedErrors($json))->toContain('referral_campaign_not_configured');
    } else {
        expect(is_array($json['data'] ?? null))->toBeTrue("Expected campaign data.\n{$debug}");
    }
});
