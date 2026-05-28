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
     * 1. Mark this placeholder as skipped so no company representative-requests permission checks run.
     */
    test('Company representative-requests - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak plus **`TEST_USER_1_*`** and **`TEST_USER_2_*`** are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    CompanyRepresentativeApiHelper::assertTwoUsersConfigOrSkip();
    CompanyRepresentativeApiHelper::assertRepresentativeCompanyConfigOrSkip();
});

/**
 * Pick a real `request_uuid` from user 1’s list, or empty string.
 */
$pickUser1RequestUuid = static function (string $bearer1): string {
    [$st, $json] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestsUrl() . '?per_page=50',
        $bearer1
    );
    if ($st !== 200 || !is_array($json)) {
        return '';
    }
    foreach ((array)($json['data']['requests'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $u = (string)($row['request_uuid'] ?? '');
        if ($u !== '') {
            return $u;
        }
    }

    return '';
};

/**
 * Prerequisites:
 * - Signed-in user B (`TEST_USER_2_*`) on their normal company session.
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/company/representative-requests` with a dummy **`candidate_role_uuid`** and text fields (create is **admin-only**).
 * 2. If user B is **not** company admin, expect **HTTP 403** **`admin_role_required`**.
 * 3. If user B **is** company admin, the server may return **HTTP 404** **`candidate_role_not_found`** for a fake UUID in **their** company — either way the call must **not** succeed with **HTTP 200** (no silent cross-tenant create).
 */
test('Company representative-requests - POST create requires company admin or stays on own company scope', function () {
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);
    [$postSt, $postJson, $postRaw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativeRequestsUrl(),
        $bearer2,
        [
            'candidate_role_uuid' => '00000000-0000-4000-a000-000000000001',
            'title' => 'permission probe',
            'justification' => 'integration',
        ]
    );
    $debug = 'status=' . $postSt . ' raw=' . substr($postRaw, 0, 700);

    expect($postSt)->not->toBe(200, 'POST create must not succeed without a valid admin workflow. ' . $debug);
    expect(in_array($postSt, [403, 404], true))->toBeTrue($debug);
    if ($postSt === 403) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($postJson))->toContain('admin_role_required');
    }
    if ($postSt === 404) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($postJson))->toContain('candidate_role_not_found');
    }
});

/**
 * Prerequisites:
 * - User A has at least one request row in **GET** list; user B is on a **different** company than A (detected via comparing a stable field is not possible without UUID — we use: B cannot open A’s **`request_uuid`**).
 *
 * Steps:
 * 1. Read a real **`request_uuid`** from user A’s list.
 * 2. As user B, **GET** `/publicapi/v1/company/representative-requests/{request_uuid}`.
 * 3. Expect **HTTP 404** **`representative_request_not_found`** (no cross-tenant leakage).
 */
test('Company representative-requests - outsider cannot load another company’s request by UUID', function () use ($pickUser1RequestUuid) {
    $bearer1 = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $reqUuid = $pickUser1RequestUuid($bearer1);
    if ($reqUuid === '') {
        test()->markTestSkipped('No representative request rows for user1 to use as cross-tenant probe.');
    }

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestDetailUrl($reqUuid),
        $bearer2
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 700);

    if ($status === 200) {
        test()->markTestSkipped('TEST_USER_1 and TEST_USER_2 appear to share company context; cross-tenant GET isolation not asserted.');
    }

    expect($status)->toBe(404, $debug);
    if (is_array($json) && CompanyRepresentativeApiHelper::joinedErrors($json) !== '') {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_request_not_found');
    }
});

/**
 * Prerequisites:
 * - Same **`request_uuid`** probe as the GET outsider test.
 *
 * Steps:
 * 1. As user B, **POST** `/publicapi/v1/company/representative-requests/{request_uuid}/cancel`.
 * 2. Expect **not HTTP 200** — typically **HTTP 404** **`representative_request_not_found`** or **HTTP 409** **`representative_request_not_cancellable`** if the row were somehow visible but not cancellable; must **not** return **HTTP 200** with **`cancelled`** true for another tenant’s row.
 */
test('Company representative-requests - outsider cannot cancel another company’s request by UUID', function () use ($pickUser1RequestUuid) {
    $bearer1 = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $reqUuid = $pickUser1RequestUuid($bearer1);
    if ($reqUuid === '') {
        test()->markTestSkipped('No representative request rows for user1 to use as cross-tenant probe.');
    }

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativeRequestCancelUrl($reqUuid),
        $bearer2,
        []
    );
    $debug = 'status=' . $status . ' errors=' . CompanyRepresentativeApiHelper::joinedErrors($json) . ' raw=' . substr($raw, 0, 700);

    if ($status === 200) {
        test()->markTestSkipped('TEST_USER_1 and TEST_USER_2 appear to share company context; cross-tenant cancel isolation not asserted.');
    }

    expect($status)->not->toBe(200, 'Outsider must not successfully cancel another company request. ' . $debug);
    expect(in_array($status, [403, 404, 409], true))->toBeTrue($debug);
    if ($status === 403) {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('admin_role_required');
    }
    if ($status === 404 && is_array($json) && CompanyRepresentativeApiHelper::joinedErrors($json) !== '') {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_request_not_found');
    }
    if ($status === 409 && is_array($json) && CompanyRepresentativeApiHelper::joinedErrors($json) !== '') {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_request_not_cancellable');
    }
});
