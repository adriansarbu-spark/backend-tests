<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
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
 * - Signed-in test user (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/referrals?sort=not_a_column`.
 * 2. Expect **HTTP 422** and `referrals_invalid_sort`.
 */
test('Referrals - invalid sort column is rejected', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();
    [$status, $json] = ReferralsApiHelper::getJson(
        ReferralsApiHelper::referralsUrl() . '?sort=not_a_column',
        $bearer,
    );

    expect($status)->toBe(422);
    expect(ReferralsApiHelper::joinedErrors($json))->toContain('referrals_invalid_sort');
});

/**
 * Prerequisites:
 * - Signed-in test user.
 *
 * Steps:
 * 1. **GET** `/referrals?order=sideways`.
 * 2. Expect **HTTP 422** and `referrals_invalid_order`.
 */
test('Referrals - invalid sort order is rejected', function () {
    $bearer = ReferralsApiHelper::bearerForTestUser1();
    [$status, $json] = ReferralsApiHelper::getJson(
        ReferralsApiHelper::referralsUrl() . '?order=sideways',
        $bearer,
    );

    expect($status)->toBe(422);
    expect(ReferralsApiHelper::joinedErrors($json))->toContain('referrals_invalid_order');
});
