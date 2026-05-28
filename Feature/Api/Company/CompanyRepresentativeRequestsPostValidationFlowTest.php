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
     * 1. Mark this placeholder as skipped so no company representative-requests API calls run.
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
 * - Integration tests are on; Keycloak and `TEST_USER_1_*` are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    CompanyRepresentativeApiHelper::assertRepresentativeCompanyConfigOrSkip();
});

/**
 * Prerequisites:
 * - Signed-in user is **company admin** for the active company (otherwise **POST** create returns **403** before validation).
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/company/representative-requests` with an empty **`candidate_role_uuid`** (or omit it).
 * 2. Expect **HTTP 400** and **`candidate_role_uuid_required`**.
 */
test('Company representative-requests - POST create rejects missing candidate_role_uuid', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$listSt] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestsUrl() . '?per_page=1',
        $bearer
    );
    if ($listSt === 403) {
        test()->markTestSkipped('TEST_USER_1 is not company admin in this environment; admin-only POST validation not asserted.');
    }
    expect($listSt)->toBe(200);

    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativeRequestsUrl(),
        $bearer,
        ['title' => 'x', 'justification' => 'y']
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(400, $debug);
    expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('candidate_role_uuid_required');
});

/**
 * Prerequisites:
 * - Company admin (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **POST** create with a syntactically valid but unknown **`candidate_role_uuid`** for this company.
 * 2. Expect **HTTP 404** and **`candidate_role_not_found`**.
 */
test('Company representative-requests - POST create rejects unknown candidate role UUID', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    [$listSt] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestsUrl() . '?per_page=1',
        $bearer
    );
    if ($listSt === 403) {
        test()->markTestSkipped('TEST_USER_1 is not company admin in this environment.');
    }

    $fakeUuid = '00000000-0000-4000-a000-000000000099';
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::postJson(
        CompanyRepresentativeApiHelper::representativeRequestsUrl(),
        $bearer,
        [
            'candidate_role_uuid' => $fakeUuid,
            'title' => 'Integration test',
            'justification' => 'No real workflow',
        ]
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 700);

    expect($status)->toBe(404, $debug);
    expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('candidate_role_not_found');
});

/**
 * Prerequisites:
 * - Signed-in member (`TEST_USER_1_*`).
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/company/representative-requests/{uuid}` with a random UUID that does not exist for this company.
 * 2. Expect **HTTP 404** and **`representative_request_not_found`**.
 */
test('Company representative-requests - GET single returns 404 for unknown request UUID', function () {
    $bearer = CompanyRepresentativeApiHelper::bearerTokenForUser1AsCompanyRepresentative();
    $unknown = 'aaaaaaaa-bbbb-bbbb-bbbb-aaaaaaaaaaaa';
    [$status, $json, $raw] = CompanyRepresentativeApiHelper::get(
        CompanyRepresentativeApiHelper::representativeRequestDetailUrl($unknown),
        $bearer
    );
    $debug = 'status=' . $status . ' raw=' . substr($raw, 0, 600);

    expect($status)->toBe(404, $debug);
    if (is_array($json) && CompanyRepresentativeApiHelper::joinedErrors($json) !== '') {
        expect(CompanyRepresentativeApiHelper::joinedErrors($json))->toContain('representative_request_not_found');
    }
});
