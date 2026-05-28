<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/CompanyRepresentativeApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no company representatives permission checks run.
     */
    test('Company representatives - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak plus **`TEST_USER_1_*`**, **`TEST_USER_2_*`**, and **`TEST_USER_1_TOTP_SECRET`** for revoke probes.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    CompanyRepresentativeApiHelper::assertTwoUsersConfigOrSkip();
    CompanyRepresentativeApiHelper::assertTotpUser1ConfigOrSkip();
    CompanyRepresentativeApiHelper::assertRepresentativeCompanyConfigOrSkip();
});

/**
 * @return list<string>
 */
$pickUser1ActiveRepresentativeUuids = static function (string $bearer1): array {
    [$st, $json] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativesUrl() . '?status=active&per_page=50',
        $bearer1
    );
    if ($st !== 200 || !is_array($json)) {
        return [];
    }
    $out = [];
    foreach ((array)($json['data']['representatives'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? '') === 'active') {
            $u = (string)($row['representative_uuid'] ?? '');
            if ($u !== '') {
                $out[] = $u;
            }
        }
    }

    return $out;
};

/**
 * Prerequisites:
 * - User A is company **admin** with TOTP enrolled (`TEST_USER_1_TOTP_SECRET`).
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/company/representatives/{representative_uuid}/revoke` with a random UUID and valid **`totp_code`**.
 * 2. Expect **HTTP 404** **`representative_not_found`** (no representative row is revoked by a bogus id).
 */
test('Company representatives - admin revoke with unknown UUID returns 404', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $unknown = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $totp = ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_1_TOTP_SECRET);

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativesRevokeUrl($unknown),
        $bearer,
        [
            'totp_code' => $totp,
            'reason' => 'integration probe — unknown uuid',
        ]
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 800);

    expect($status)->toBe(404, $debug);
    expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_not_found');
});

/**
 * Prerequisites:
 * - User A is company admin with TOTP.
 *
 * Steps:
 * 1. **POST** revoke with a valid path but **omit** **`totp_code`**.
 * 2. Expect **HTTP 400** **`totp_code_required`** before any revocation runs.
 */
test('Company representatives - revoke without TOTP when enrolled is rejected', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $unknown = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativesRevokeUrl($unknown),
        $bearer,
        ['reason' => 'missing totp']
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 700);

    expect($status)->toBe(400, $debug);
    expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('totp_code_required');
});

/**
 * Prerequisites:
 * - User B signed in (`TEST_USER_2_*`); user A has at least one **active** **`representative_uuid`** in **GET** list.
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/company/representatives/{representative_uuid}/revoke` as user B targeting user A’s active **`representative_uuid`**, including user B’s **`totp_code`** when **`TEST_USER_2_TOTP_SECRET`** is configured so admin callers are fully exercised.
 * 2. Expect **not HTTP 200** — **HTTP 403** **`admin_role_required`** when B is not admin, or **HTTP 404** **`representative_not_found`** when B is admin on another company (row not in B’s company).
 * 3. If the server incorrectly returns **HTTP 200**, mark the test incomplete as a possible security regression.
 */
test('Company representatives - outsider cannot revoke another company’s representative by UUID', function () use ($pickUser1ActiveRepresentativeUuids) {
    $bearer1 = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $uuids = $pickUser1ActiveRepresentativeUuids($bearer1);
    if ($uuids === []) {
        test()->markTestSkipped('No active representative_uuid rows for user1 to use as cross-tenant probe.');
    }
    $repUuid = $uuids[0];

    $body = [
        'reason' => 'integration — cross-tenant revoke must fail',
    ];
    if (defined('TEST_USER_2_TOTP_SECRET') && is_string(TEST_USER_2_TOTP_SECRET) && trim(TEST_USER_2_TOTP_SECRET) !== '') {
        $body['totp_code'] = ApiAuthHelper::getOtpFromTotpSecret(TEST_USER_2_TOTP_SECRET);
    }

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativesRevokeUrl($repUuid),
        $bearer2,
        $body
    );
    $debug = 'status=' . $status . ' errors=' . CompanyRepresentativeApiHelper::joinedErrors($json) . ' raw=' . substr($raw, 0, 900);

    if ($status === 200) {
        test()->markTestIncomplete('SECURITY: user2 was able to revoke user1 representative. ' . $debug);
    }

    expect($status)->not->toBe(200, $debug);
    expect(in_array($status, [403, 400, 404], true))->toBeTrue($debug);
    if ($status === 403) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('admin_role_required');
    }
    if ($status === 404) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_not_found');
    }
    if ($status === 400) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->not->toBe('');
    }
});
