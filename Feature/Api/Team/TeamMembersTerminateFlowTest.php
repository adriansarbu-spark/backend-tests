<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/TeamApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no team members terminate API calls run.
     */
    test('Team members - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak, `TEST_USER_1_*`, and TOTP secret for user 1 are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    TeamApiHelper::assertTotpConfigOrSkip();
});

/**
 * Prerequisites:
 * - Company admin (`TEST_USER_1_*`) with TOTP enrolled.
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/team/members/{role_uuid}/terminate` with a syntactically valid **`role_uuid`** that does not match any member in the company.
 * 2. Supply a fresh **`totp_code`** from the configured secret.
 * 3. Expect **HTTP 404** and **`member_not_found`** (no membership is terminated).
 */
test('Team members - terminate with unknown role UUID returns 404', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $unknownUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $totp = ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_1_TOTP_SECRET);

    [$status, $json, $raw] = TeamApiHelper::postJson(
        TeamApiHelper::membersTerminateUrl($unknownUuid),
        $bearer,
        [
            'totp_code' => $totp,
            'reason' => 'integration test — unknown member',
        ]
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 800);

    expect($status)->toBe(404, $debug);
    expect(TeamApiHelper::joinedErrors($json))->toContain('member_not_found');
});

/**
 * Prerequisites:
 * - Company admin with TOTP enrolled.
 *
 * Steps:
 * 1. **POST** terminate **without** **`totp_code`** in the JSON body.
 * 2. Expect **HTTP 400** and **`totp_code_required`** before any membership change.
 */
test('Team members - terminate without TOTP when enrolled is rejected', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $unknownUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    [$status, $json, $raw] = TeamApiHelper::postJson(
        TeamApiHelper::membersTerminateUrl($unknownUuid),
        $bearer,
        ['reason' => 'missing totp']
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 800);

    expect($status)->toBe(400, $debug);
    expect(TeamApiHelper::joinedErrors($json))->toContain('totp_code_required');
});

/**
 * Prerequisites:
 * - Company admin with TOTP; members list returns at least the caller’s own row.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/members` and find the row whose **`email`** matches **`TEST_USER_1_EMAIL`** (case-insensitive).
 * 2. **POST** terminate for that **`role_uuid`** with valid **`totp_code`**.
 * 3. Expect **HTTP 409** and **`cannot_terminate_self`** (guardrail: you cannot fire yourself via this endpoint).
 */
test('Team members - admin cannot terminate their own membership', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    [$listStatus, $listJson, $listRaw] = TeamApiHelper::get(
        TeamApiHelper::membersUrl() . '?member_status=any&per_page=100',
        $bearer
    );
    expect($listStatus)->toBe(200, 'GET members precondition failed: ' . substr($listRaw, 0, 800));

    $needle = mb_strtolower(TEST_USER_1_EMAIL);
    $selfUuid = '';
    foreach ((array)($listJson['data']['members'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (mb_strtolower((string)($row['email'] ?? '')) === $needle) {
            $selfUuid = (string)($row['role_uuid'] ?? '');
            break;
        }
    }

    if ($selfUuid === '') {
        test()->markTestSkipped('Could not resolve caller role_uuid from members list for self-terminate check.');
    }

    $totp = ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_1_TOTP_SECRET);
    [$status, $json, $raw] = TeamApiHelper::postJson(
        TeamApiHelper::membersTerminateUrl($selfUuid),
        $bearer,
        [
            'totp_code' => $totp,
            'reason' => 'integration test — self terminate',
        ]
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 800);

    expect($status)->toBe(409, $debug);
    expect(TeamApiHelper::joinedErrors($json))->toContain('cannot_terminate_self');
});
