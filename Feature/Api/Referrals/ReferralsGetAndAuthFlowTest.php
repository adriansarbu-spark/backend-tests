<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/ReferralsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php`.
     *
     * Steps:
     * 1. Mark this file skipped so no referrals HTTP calls run.
     */
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
 * - Integration tests enabled; referrals list endpoint is reachable.
 *
 * Steps:
 * 1. **GET** `/referrals` without an Authorization header.
 * 2. Access is refused (**HTTP 404**).
 */
test('Referrals - listing without a token is refused', function () {
    [$status, $json] = ReferralsApiHelper::getJson(ReferralsApiHelper::referralsUrl());

    expect($status)->toBe(404);
    expect(ReferralsApiHelper::joinedErrors($json))->not->toBe('');
});

/**
 * Prerequisites:
 * - Signed-in test user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/referrals` with a valid bearer.
 * 2. Expect **HTTP 200**, array `data`, and `pagination` with page metadata.
 */
test('Referrals - signed-in user can list referral activity with pagination', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();
    [$status, $json, $raw] = ReferralsApiHelper::getJson(
        ReferralsApiHelper::referralsUrl() . '?page=1&per_page=20',
        $bearer,
    );

    $debug = "Status={$status}\n" . substr((string)$raw, 0, 800);
    expect($status)->toBe(200, "List referrals failed.\n{$debug}");
    expect(is_array($json['data'] ?? null))->toBeTrue("Expected data array.\n{$debug}");
    expect(is_array($json['pagination'] ?? null))->toBeTrue("Expected pagination.\n{$debug}");
    expect(isset($json['pagination']['page']))->toBeTrue();
});

/**
 * Prerequisites:
 * - Signed-in test user.
 *
 * Steps:
 * 1. **GET** `/referrals?status=not_a_status`.
 * 2. Expect **HTTP 422** and a non-empty `error`.
 */
test('Referrals - invalid status filter is rejected', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();
    [$status, $json] = ReferralsApiHelper::getJson(
        ReferralsApiHelper::referralsUrl() . '?status=not_a_status',
        $bearer,
    );

    expect($status)->toBe(422);
    expect(ReferralsApiHelper::joinedErrors($json))->toContain('referrals_invalid_status');
});
