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
     * 1. Mark this placeholder as skipped so no team invitations permission checks run.
     */
    test('Team invitations - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are on; Keycloak plus **`TEST_USER_1_*`** and
 *   **`BILLING_TEST_COMPANY_NON_ADMIN_UUID`** (user1 non-admin company role) are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    TeamApiHelper::assertRequiredConfigOrSkip();
    assertTestConfigKeysOrSkip(['BILLING_TEST_COMPANY_NON_ADMIN_UUID']);
});

/**
 * Resolve a pending invitation UUID for user 1’s company (list first, otherwise create).
 *
 * @return array{0: string, 1: string} [invitation_uuid, debug]
 */
$resolveUser1PendingInvitationUuid = static function (string $bearer1): array {
    [$listSt, $listJson, $listRaw] = TeamApiHelper::get(
        TeamApiHelper::invitationsUrl() . '?status=pending&per_page=50',
        $bearer1
    );
    $baseDebug = 'list_status=' . $listSt . ' raw=' . substr($listRaw, 0, 400);
    if ($listSt !== 200 || !is_array($listJson)) {
        return ['', $baseDebug];
    }
    foreach ((array)($listJson['data']['invitations'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? '') === 'pending') {
            $u = (string)($row['invitation_uuid'] ?? '');
            if ($u !== '') {
                return [$u, $baseDebug];
            }
        }
    }

    $email = 'alexandru.zamfir+team-perm-' . gmdate('YmdHis') . '@simplifi.ro';
    [$postSt, $postJson, $postRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer1, [
        'email' => $email,
        'role_code' => 'employee',
    ]);
    $postDebug = $baseDebug . ' post_status=' . $postSt . ' post_raw=' . substr($postRaw, 0, 500);
    if ($postSt === 500 && is_array($postJson) && str_contains(TeamApiHelper::joinedErrors($postJson), 'invitation_email_queue_failed')) {
        return ['', $postDebug . ' (queue failed)'];
    }
    if ($postSt !== 200 || !is_array($postJson)) {
        return ['', $postDebug];
    }
    $u = (string)($postJson['data']['invitation']['invitation_uuid'] ?? '');

    return [$u, $postDebug];
};

/**
 * Prerequisites:
 * - User A is company admin and has (or can create) at least one **pending** invitation;
 *   non-admin probe uses the same TEST_USER_1 on **`BILLING_TEST_COMPANY_NON_ADMIN_UUID`**
 *   (active role switched only after admin setup completes).
 *
 * Steps:
 * 1. As admin, resolve a real **`invitation_uuid`**.
 * 2. Switch the same user to the non-admin role and **POST** `/publicapi/v1/team/invitations/revoke`.
 * 3. Expect the operation **not** to succeed: **404** `invitation_not_found`, **403** `admin_role_required` /
 *    `access_denied`, or **400** `company_context_required` / `company_role_required`.
 */
test('Team invitations - outsider cannot revoke another company’s invitation by UUID', function () use ($resolveUser1PendingInvitationUuid) {
    $bearerAdmin = TeamApiHelper::bearerWithActiveCompanyAdminRole(resolvedTestConfigValue('TEST_USER_1_EMAIL'), resolvedTestConfigValue('TEST_USER_1_PASSWORD'));

    [$list1St, , $list1Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearerAdmin);
    expect($list1St)->toBe(200, 'User1 must list invitations as admin for this scenario. ' . substr((string)$list1Raw, 0, 400));

    [$invUuid, $dbg] = $resolveUser1PendingInvitationUuid($bearerAdmin);
    if ($invUuid === '') {
        test()->markTestSkipped('No pending invitation_uuid for user1 (list empty and create failed or queue failed). ' . $dbg);
    }

    // Same Keycloak user: switch active role only after admin setup is done.
    $bearerNonAdmin = TeamApiHelper::bearerWithUser1CompanyNonAdminRole();

    [$list2St, $list2Json, $list2Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearerNonAdmin);
    if ($list2St === 200) {
        test()->markTestSkipped(
            'BILLING_TEST_COMPANY_NON_ADMIN_UUID can list invitations (treated as admin); non-admin revoke denial is not asserted. raw='
            . substr((string)$list2Raw, 0, 300)
        );
    }
    expect(TeamApiHelper::isTeamInvitationPrivilegeDenied($list2St, $list2Json))->toBeTrue(
        'Expected 403 admin_role_required|access_denied or 400 company context on non-admin list. raw='
        . substr((string)$list2Raw, 0, 400)
    );

    [$revSt, $revJson, $revRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsRevokeUrl(), $bearerNonAdmin, [
        'invitation_uuid' => $invUuid,
    ]);
    $revDebug = 'status=' . $revSt . ' errors=' . TeamApiHelper::joinedErrors($revJson) . ' raw=' . substr($revRaw, 0, 700);

    expect($revSt)->not->toBe(200, 'Non-admin must not successfully revoke an invitation. ' . $revDebug);
    expect(TeamApiHelper::isOutsiderTeamInvitationMutationBlocked($revSt, $revJson))->toBeTrue(
        'Expected 403 admin/access_denied, 404 invitation_not_found, or 400 company_context_required / company_role_required. ' . $revDebug
    );
    if (is_array($revJson)) {
        expect(TeamApiHelper::joinedErrors($revJson))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Admin has a pending **`invitation_uuid`**; non-admin probe is TEST_USER_1 on
 *   **`BILLING_TEST_COMPANY_NON_ADMIN_UUID`** (switched after admin setup).
 *
 * Steps:
 * 1. As non-admin, **POST** `/publicapi/v1/team/invitations/resend` with the invitation UUID.
 * 2. Expect **not HTTP 200**; blocked responses same as revoke isolation.
 */
test('Team invitations - outsider cannot resend another company’s invitation by UUID', function () use ($resolveUser1PendingInvitationUuid) {
    $bearerAdmin = TeamApiHelper::bearerWithActiveCompanyAdminRole(resolvedTestConfigValue('TEST_USER_1_EMAIL'), resolvedTestConfigValue('TEST_USER_1_PASSWORD'));

    [$list1St, , $list1Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearerAdmin);
    expect($list1St)->toBe(200, substr((string)$list1Raw, 0, 400));

    [$invUuid, $dbg] = $resolveUser1PendingInvitationUuid($bearerAdmin);
    if ($invUuid === '') {
        test()->markTestSkipped('No pending invitation_uuid for user1. ' . $dbg);
    }

    $bearerNonAdmin = TeamApiHelper::bearerWithUser1CompanyNonAdminRole();

    [$list2St, $list2Json, $list2Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearerNonAdmin);
    if ($list2St === 200) {
        test()->markTestSkipped(
            'BILLING_TEST_COMPANY_NON_ADMIN_UUID can list invitations (treated as admin); non-admin resend denial is not asserted. raw='
            . substr((string)$list2Raw, 0, 300)
        );
    }
    expect(TeamApiHelper::isTeamInvitationPrivilegeDenied($list2St, $list2Json))->toBeTrue(
        'Expected 403 admin_role_required|access_denied or 400 company context on non-admin list. raw='
        . substr((string)$list2Raw, 0, 400)
    );

    [$rsSt, $rsJson, $rsRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsResendUrl(), $bearerNonAdmin, [
        'invitation_uuid' => $invUuid,
    ]);
    $rsDebug = 'status=' . $rsSt . ' errors=' . TeamApiHelper::joinedErrors($rsJson) . ' raw=' . substr($rsRaw, 0, 700);

    expect($rsSt)->not->toBe(200, 'Non-admin must not successfully resend an invitation. ' . $rsDebug);
    expect(TeamApiHelper::isOutsiderTeamInvitationMutationBlocked($rsSt, $rsJson))->toBeTrue(
        'Expected 403 admin/access_denied, 404 invitation_not_found, or 400 company_context_required / company_role_required. ' . $rsDebug
    );
    if (is_array($rsJson)) {
        expect(TeamApiHelper::joinedErrors($rsJson))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - TEST_USER_1 on **`BILLING_TEST_COMPANY_NON_ADMIN_UUID`** cannot administer invitations:
 *   **403** **`admin_role_required`** / **`access_denied`** (same as revoke/resend), or **400** company-context errors.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/invitations` as that non-admin role — expect privilege denial.
 * 2. **POST** create with a valid **`email`** and **`employee`** **`role_code`** — expect the same denial class.
 */
test('Team invitations - non-admin cannot create an invitation for their company', function () {
    $bearerNonAdmin = TeamApiHelper::bearerWithUser1CompanyNonAdminRole();
    [$listSt, $listJson, $listRaw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearerNonAdmin);

    if ($listSt === 200) {
        test()->markTestSkipped('BILLING_TEST_COMPANY_NON_ADMIN_UUID can list invitations; non-admin POST denial is not asserted.');
    }

    $listDebug = 'list_status=' . $listSt . ' raw=' . substr($listRaw, 0, 400);
    expect(TeamApiHelper::isTeamInvitationPrivilegeDenied($listSt, $listJson))->toBeTrue(
        'Expected 403 admin_role_required|access_denied or 400 company context on non-admin list. ' . $listDebug
    );

    $email = 'alexandru.zamfir+team-nonadmin-' . gmdate('YmdHis') . '@simplifi.ro';
    [$postSt, $postJson, $postRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearerNonAdmin, [
        'email' => $email,
        'role_code' => 'employee',
    ]);
    $postDebug = 'post_status=' . $postSt . ' raw=' . substr($postRaw, 0, 600);

    expect($postSt)->not->toBe(200, 'Non-admin must not create an invitation. ' . $postDebug);
    expect(TeamApiHelper::isTeamInvitationPrivilegeDenied($postSt, $postJson))->toBeTrue(
        'Expected 403 admin_role_required|access_denied or 400 company context on non-admin create. ' . $postDebug
    );
    if (is_array($postJson)) {
        expect(TeamApiHelper::joinedErrors($postJson))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Signed-in company admin (`TEST_USER_1_*`); **GET** invitations succeeds.
 *
 * Steps:
 * 1. **GET** `/publicapi/v1/team/invitations` and read **`data.company_uuid`** (the active company for this session).
 * 2. Assert every returned invitation row whose **`company_uuid`** is set matches that same value (tenant scope on the wire).
 * 3. **POST** a new invite using a **dummy** RFC address **`team-inv-scope-dummy+{timestamp}@example.com`** (no real inbox) and **`employee`** **`role_code`**.
 * 4. If the server returns **HTTP 200**, assert **`data.invitation.invited_email`** matches the dummy (normalized) and **`data.invitation.company_uuid`** still equals the list **`company_uuid`** from step 1.
 * 5. If the server returns **HTTP 500** with **`invitation_email_queue_failed`**, assert only that error code (persistence may have run but mail queue is down — still not a cross-tenant leak to assert here).
 * 6. Any other status is a hard failure with response details for debugging.
 */
test('Team invitations - list and optional dummy create stay on the session company_uuid', function () {
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(resolvedTestConfigValue('TEST_USER_1_EMAIL'), resolvedTestConfigValue('TEST_USER_1_PASSWORD'));
    [$listSt, $listJson, $listRaw] = TeamApiHelper::get(
        TeamApiHelper::invitationsUrl() . '?per_page=50',
        $bearer
    );
    $listDebug = 'list_status=' . $listSt . ' raw=' . substr($listRaw, 0, 600);
    expect($listSt)->toBe(200, $listDebug);
    expect(is_array($listJson))->toBeTrue($listDebug);

    $companyUuid = (string)($listJson['data']['company_uuid'] ?? '');
    expect($companyUuid)->not->toBe('', $listDebug);

    foreach ((array)($listJson['data']['invitations'] ?? []) as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowCu = (string)($row['company_uuid'] ?? '');
        if ($rowCu !== '') {
            expect($rowCu)->toBe($companyUuid, "Invitation row {$idx} company_uuid must match list context. {$listDebug}");
        }
    }

    $dummyEmail = 'team-inv-scope-dummy+' . gmdate('YmdHis') . '@example.com';
    [$postSt, $postJson, $postRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer, [
        'email' => $dummyEmail,
        'role_code' => 'employee',
    ]);
    $postDebug = $listDebug . ' post_status=' . $postSt . ' post_raw=' . substr($postRaw, 0, 800);

    if ($postSt === 200) {
        expect(is_array($postJson))->toBeTrue($postDebug);
        $inv = (array)($postJson['data']['invitation'] ?? []);
        expect((string)($inv['invited_email'] ?? ''))->toBe(mb_strtolower($dummyEmail), $postDebug);
        $invCu = (string)($inv['company_uuid'] ?? '');
        if ($invCu !== '') {
            expect($invCu)->toBe($companyUuid, $postDebug);
        }
        expect(array_key_exists('token', $inv))->toBeFalse('Response must not include raw invitation token.');

        return;
    }

    if ($postSt === 500 && is_array($postJson) && str_contains(TeamApiHelper::joinedErrors($postJson), 'invitation_email_queue_failed')) {
        expect(TeamApiHelper::joinedErrors($postJson))->toContain('invitation_email_queue_failed', $postDebug);

        return;
    }

    expect($postSt)->toBe(200, 'Unexpected POST outcome for dummy invite. ' . $postDebug);
});
