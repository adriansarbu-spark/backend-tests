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
 * - Integration tests are on; Keycloak plus **`TEST_USER_1_*`** and **`TEST_USER_2_*`** are configured.
 *
 * Steps:
 * 1. Confirm required constants are present; if not, skip the whole file.
 */
beforeAll(function () {
    TeamApiHelper::assertUserTwoConfigOrSkip();
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
 * - User A is company admin and has (or can create) at least one **pending** invitation; user B is signed in on a **different** company than A (same `company_uuid` on both accounts would make isolation impossible to assert here).
 *
 * Steps:
 * 1. Read user A’s **`data.company_uuid`** and a real **`invitation_uuid`** for that company.
 * 2. As user B, **POST** `/publicapi/v1/team/invitations/revoke` with A’s **`invitation_uuid`**.
 * 3. Expect the operation **not** to succeed: **404** `invitation_not_found`, **403** `admin_role_required`, or **400** `company_context_required` / `company_role_required` when B has no company or cannot act as admin.
 */
test('Team invitations - outsider cannot revoke another company’s invitation by UUID', function () use ($resolveUser1PendingInvitationUuid) {
    $bearer1 = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$list1St, $list1Json, $list1Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearer1);
    expect($list1St)->toBe(200, 'User1 must list invitations as admin for this scenario. ' . substr((string)$list1Raw, 0, 400));

    $companyUuid1 = (string)($list1Json['data']['company_uuid'] ?? '');

    [$list2St, $list2Json, $list2Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearer2);
    if ($list2St === 403) {
        // User2 is not company admin — still must not revoke user1’s invite (exercise POST revoke path).
        expect(TeamApiHelper::joinedErrors($list2Json))->toMatch('/admin_role_required|access_denied/');
    } elseif ($list2St === 400 && TeamApiHelper::isCompanyMembershipRequiredError($list2Json)) {
        // No active company / wrong membership — cannot list invitations for a tenant.
        expect(TeamApiHelper::joinedErrors($list2Json))->not->toBe('');
    } elseif ($list2St === 200 && is_array($list2Json)) {
        $companyUuid2 = (string)($list2Json['data']['company_uuid'] ?? '');
        if ($companyUuid1 !== '' && $companyUuid2 !== '' && $companyUuid1 === $companyUuid2) {
            test()->markTestSkipped('TEST_USER_1 and TEST_USER_2 share the same company_uuid; cross-company revoke isolation is not asserted in this environment.');
        }
    }

    [$invUuid, $dbg] = $resolveUser1PendingInvitationUuid($bearer1);
    if ($invUuid === '') {
        test()->markTestSkipped('No pending invitation_uuid for user1 (list empty and create failed or queue failed). ' . $dbg);
    }

    [$revSt, $revJson, $revRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsRevokeUrl(), $bearer2, [
        'invitation_uuid' => $invUuid,
    ]);
    $revDebug = 'status=' . $revSt . ' errors=' . TeamApiHelper::joinedErrors($revJson) . ' raw=' . substr($revRaw, 0, 700);

    expect($revSt)->not->toBe(200, 'Outsider must not successfully revoke another company invitation. ' . $revDebug);
    expect(TeamApiHelper::isOutsiderTeamInvitationMutationBlocked($revSt, $revJson))->toBeTrue(
        'Expected 403 admin, 404 invitation_not_found, or 400 company_context_required / company_role_required. ' . $revDebug
    );
    if (is_array($revJson)) {
        expect(TeamApiHelper::joinedErrors($revJson))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - Same as revoke outsider scenario: user A has a pending **`invitation_uuid`**; user B on a different company (or non-admin on theirs).
 *
 * Steps:
 * 1. As user B, **POST** `/publicapi/v1/team/invitations/resend` with A’s **`invitation_uuid`**.
 * 2. Expect **not HTTP 200**; blocked responses same as revoke isolation.
 */
test('Team invitations - outsider cannot resend another company’s invitation by UUID', function () use ($resolveUser1PendingInvitationUuid) {
    $bearer1 = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$list1St, $list1Json, $list1Raw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearer1);
    expect($list1St)->toBe(200, substr((string)$list1Raw, 0, 400));

    $companyUuid1 = (string)($list1Json['data']['company_uuid'] ?? '');

    [$list2St, $list2Json] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearer2);
    if ($list2St === 200 && is_array($list2Json)) {
        $companyUuid2 = (string)($list2Json['data']['company_uuid'] ?? '');
        if ($companyUuid1 !== '' && $companyUuid2 !== '' && $companyUuid1 === $companyUuid2) {
            test()->markTestSkipped('TEST_USER_1 and TEST_USER_2 share the same company_uuid; cross-company resend isolation is not asserted.');
        }
    }

    [$invUuid, $dbg] = $resolveUser1PendingInvitationUuid($bearer1);
    if ($invUuid === '') {
        test()->markTestSkipped('No pending invitation_uuid for user1. ' . $dbg);
    }

    [$rsSt, $rsJson, $rsRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsResendUrl(), $bearer2, [
        'invitation_uuid' => $invUuid,
    ]);
    $rsDebug = 'status=' . $rsSt . ' errors=' . TeamApiHelper::joinedErrors($rsJson) . ' raw=' . substr($rsRaw, 0, 700);

    expect($rsSt)->not->toBe(200, 'Outsider must not successfully resend another company invitation. ' . $rsDebug);
    expect(TeamApiHelper::isOutsiderTeamInvitationMutationBlocked($rsSt, $rsJson))->toBeTrue(
        'Expected 403 admin, 404 invitation_not_found, or 400 company_context_required / company_role_required. ' . $rsDebug
    );
    if (is_array($rsJson)) {
        expect(TeamApiHelper::joinedErrors($rsJson))->not->toBe('');
    }
});

/**
 * Prerequisites:
 * - User B cannot administer invitations: either not company admin (**403** **`admin_role_required`**) or outside company context (**400** **`company_context_required`** / **`company_role_required`**).
 *
 * Steps:
 * 1. **POST** `/publicapi/v1/team/invitations` as user B with a valid **`email`** and **`employee`** **`role_code`**.
 * 2. Expect the same class of refusal on **POST** as on **GET** list (non-admin or no company).
 */
test('Team invitations - non-admin cannot create an invitation for their company', function () {
    $bearer2 = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);
    [$listSt, $listJson, $listRaw] = TeamApiHelper::get(TeamApiHelper::invitationsUrl() . '?per_page=1', $bearer2);

    $listErr = TeamApiHelper::joinedErrors($listJson);
    $listDeniedNonAdmin = $listSt === 403 && str_contains($listErr, 'admin_role_required');
    $listDeniedNoCompany = $listSt === 400 && TeamApiHelper::isCompanyMembershipRequiredError($listJson);

    if ($listSt === 200) {
        test()->markTestSkipped('TEST_USER_2 is a company admin in this environment; non-admin POST denial is not asserted.');
    }
    if (!$listDeniedNonAdmin && !$listDeniedNoCompany) {
        test()->markTestSkipped('Unexpected GET invitations status for user2 (status=' . $listSt . ' raw=' . substr($listRaw, 0, 400) . ').');
    }
    if ($listDeniedNonAdmin) {
        expect($listErr)->toContain('admin_role_required');
    } else {
        expect(TeamApiHelper::isCompanyMembershipRequiredError($listJson))->toBeTrue('Expected company_context_required or company_role_required on list.');
    }

    $email = 'alexandru.zamfir+team-nonadmin-' . gmdate('YmdHis') . '@simplifi.ro';
    [$postSt, $postJson, $postRaw] = TeamApiHelper::postJson(TeamApiHelper::invitationsUrl(), $bearer2, [
        'email' => $email,
        'role_code' => 'employee',
    ]);
    $debug = 'status=' . $postSt . ' raw=' . substr($postRaw, 0, 600);

    if ($listDeniedNonAdmin) {
        expect($postSt)->toBe(403, $debug);
        expect(TeamApiHelper::joinedErrors($postJson))->toContain('admin_role_required');
    } else {
        expect($postSt)->toBe(400, $debug);
        expect(TeamApiHelper::isCompanyMembershipRequiredError($postJson))->toBeTrue(
            'POST should reject without company context. errors=' . TeamApiHelper::joinedErrors($postJson)
        );
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
    $bearer = TeamApiHelper::bearerWithActiveCompanyAdminRole(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
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
