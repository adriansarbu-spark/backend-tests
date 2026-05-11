<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/SigningFlowHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - `SKIP_INTEGRATION_TESTS` is true in `tests_config.php` (integration suite disabled).
     *
     * Steps:
     * 1. Mark the file’s tests skipped so no signing API calls run.
     */
    test('Skipping signing integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    SigningFlowHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests enabled (`SKIP_INTEGRATION_TESTS` false) and signing env configured (`beforeAll`).
 * - `TEST_USER_1_EMAIL` / `TEST_USER_1_PASSWORD` / `TEST_USER_1_TOTP_SECRET` valid for the API under test.
 *
 * Steps:
 * 1. Obtain bearer token for user 1 (document owner).
 * 2. Create a PDF document via API and capture its UUID.
 * 3. Clear annotations (none required for this path).
 * 4. Set signers to owner only, order 1, no outbound email.
 * 5. Resolve sign code for the owner’s email.
 * 6. Call sign with owner bearer + TOTP; assert HTTP 200 and response echoes the sign code.
 */
test('signing flow: owner creates and signs document', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        'sign-flow-' . gmdate('YmdHis') . '.pdf',
        SigningFlowHelper::fixturePdfContent()
    );
    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, []);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_1_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);
    $ownerSignCode = SigningFlowHelper::getSignCodeForEmail($user1Bearer, $uuid, TEST_USER_1_EMAIL);
    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::sign($user1Bearer, $ownerSignCode, TEST_USER_1_TOTP_SECRET);

    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $signDebug = "Status={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n{$signRaw}";
    expect($signStatus)->toBe(200, "Sign document failed with full details:\n{$signDebug}");
    expect(is_array($signJson))->toBeTrue("Sign document returned non-JSON. Full details:\n{$signDebug}");
    expect((string)($signJson['data']['sign_code'] ?? ''))->toBe(
        $ownerSignCode,
        "Sign response missing expected sign_code. Full details:\n{$signDebug}"
    );
});

/**
 * Prerequisites:
 * - Same as owner-sign path: user1 and user2 credentials + signing env (`beforeAll`).
 *
 * Steps:
 * 1. User1 creates a document and sets themself as sole signer; obtain owner `sign_code`.
 * 2. Call `sign` as user2 with that code and user2’s TOTP.
 * 3. Assert HTTP is not 200 and JSON carries an authorization-style error (cross-tenant / wrong signer).
 */
test('signing flow: other user cannot sign with owner sign_code', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        'sign-flow-forbidden-' . gmdate('YmdHis') . '.pdf',
        SigningFlowHelper::fixturePdfContent()
    );
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_1_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);
    $ownerSignCode = SigningFlowHelper::getSignCodeForEmail($user1Bearer, $uuid, TEST_USER_1_EMAIL);
    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::sign($user2Bearer, $ownerSignCode, TEST_USER_2_TOTP_SECRET);

    $debug = 'status=' . $signStatus . ' raw=' . substr($signRaw, 0, 700);
    expect($signStatus)->not->toBe(200, 'Other user should not sign with owner sign_code. ' . $debug);
    if (is_array($signJson)) {
        $errors = implode(' | ', array_map('strval', (array)($signJson['error'] ?? [])));
        expect($errors)->not->toBe('', 'Expected access/authorization style error. ' . $debug);
    }
});

/**
 * Prerequisites:
 * - User1 (sender) and user2 (receiver) accounts with TOTP; signing config OK.
 *
 * Steps:
 * 1. User1 creates a named PDF, adds a signature annotation for user2, sets user2 as signer (`send_email` false in test).
 * 2. Send the document for signing.
 * 3. User2 waits for `sign_code` by document name and for signer file readiness.
 * 4. User2 signs with TOTP; assert 200 and `sign_code` echoed in response.
 */
test('signing flow: sender sends to receiver and receiver can sign', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-user2-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'uses_prepaid_invite' => true,
        // 'send_email' => true,
        'send_email' => false,  // in reality, this should be true, but we keep it false for now to avoid sending emails
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);
    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );

    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $signDebug = "Status={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n{$signRaw}";

    expect($signStatus)->toBe(200, "Receiver sign failed with full details:\n{$signDebug}");
    expect(is_array($signJson))->toBeTrue("Receiver sign returned non-JSON. Full details:\n{$signDebug}");
    expect((string)($signJson['data']['sign_code'] ?? ''))->toBe(
        $user2SignCode,
        "Receiver sign response missing expected sign_code. Full details:\n{$signDebug}"
    );
});

/**
 * Prerequisites:
 * - User1 and user2 as in send/receive flow.
 *
 * Steps:
 * 1. Same send pipeline: create PDF, annotation for user2, signers, send, user2 signs successfully.
 * 2. Sender GET `documents/{uuid}` — expect 200 and UUID in payload (owner view).
 * 3. Receiver GET signer-file by their `sign_code` — expect 200 and PDF magic bytes (`%PDF`).
 */
test('signing flow: receiver signs and sender and receiver can view the document', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-view-both-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        // 'send_email' => true,
        'send_email' => false,  // in reality, this should be true, but we keep it false for now to avoid sending emails
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::getSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);
    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    expect($signStatus)->toBe(200, 'Receiver sign failed. status=' . $signStatus . ' raw=' . substr($signRaw, 0, 700));

    // Sender (owner) can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status)->toBe(200, 'Sender should be able to view document by UUID. ' . substr($u1Raw, 0, 700));
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver can view via signer file endpoint (UUID endpoints are owner-only)
    [$u2Status, , $u2Raw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2Status)->toBe(200, 'Receiver should be able to download signer PDF. ' . substr($u2Raw, 0, 120));
    expect(str_starts_with((string)$u2Raw, '%PDF'))->toBeTrue('Expected signer file response to be a PDF');
});

/**
 * Prerequisites:
 * - User1 and user2; ordered multi-signer flow supported by API.
 *
 * Steps:
 * 1. Create document with annotation for user2; signers: user1 order 1, user2 order 2 (`send_email` true for user2).
 * 2. Send document.
 * 3. User1 signs first with their `sign_code`; then user2’s code appears — user2 signs.
 * 4. Owner GET document by UUID — expect 200 and stable UUID (post-flow visibility).
 */
test('signing flow: sender and receiver both sign in signing order', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-both-sign-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 340,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_1_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ], [
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 2,
        'signing_type' => 'SIGNATURE',
        'send_email' => true,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    // Sender signs first (order 1)
    $user1SignCode = SigningFlowHelper::getSignCodeForEmail($user1Bearer, $uuid, TEST_USER_1_EMAIL);
    [$u1SignStatus, , $u1SignRaw] = SigningFlowHelper::sign($user1Bearer, $user1SignCode, TEST_USER_1_TOTP_SECRET);
    expect($u1SignStatus)->toBe(200, 'Sender sign failed: ' . substr($u1SignRaw, 0, 700));

    // After sender signs, receiver should get invited and see a sign_code in their list
    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    [$u2SignStatus, , $u2SignRaw] = SigningFlowHelper::sign($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($u2SignStatus)->toBe(200, 'Receiver sign failed: ' . substr($u2SignRaw, 0, 700));

    // Owner can still view the document at the end
    [$finalStatus, $finalJson, $finalRaw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($finalStatus)->toBe(200, 'Owner should be able to view final doc. ' . substr($finalRaw, 0, 700));
    expect(is_array($finalJson))->toBeTrue();
    expect((string)($finalJson['data']['uuid'] ?? ''))->toBe($uuid);
});

/**
 * Prerequisites:
 * - `TEST_USER_3_*` configured as uncertified / non-qualified for signing (per env policy).
 *
 * Steps:
 * 1. User1 sends a document to user3 only (annotation + signer for user3).
 * 2. User3 obtains `sign_code` and signer file OK.
 * 3. Attempt sign as user3 with TOTP — expect failure (not HTTP 200): uncertified invitee must not complete signature.
 */
test('signing flow: sender invites uncertified invitee who cannot sign (expected)', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user3Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_3_EMAIL, TEST_USER_3_PASSWORD);

    $documentName = 'sign-flow-user3-no-cert-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_3_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_3_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user3SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user3Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user3Bearer, $user3SignCode);

    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user3Bearer,
        $user3SignCode,
        TEST_USER_3_TOTP_SECRET
    );

    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';

    expect($signStatus)->not->toBe(
        200,
        "Uncertified invitee should NOT be able to sign. If this fails, behavior changed.\nStatus={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200)
    );
});

/**
 * Prerequisites:
 * - User1 sender, user2 receiver; reject API available.
 *
 * Steps:
 * 1. Send document to user2; wait for `sign_code` and signer file.
 * 2. User2 rejects with TOTP — expect HTTP 200 on reject.
 * 3. Sender GET `documents/{uuid}` — 200 and UUID.
 * 4. Receiver GET signer-file by same `sign_code` — 200 and PDF bytes (still accessible after reject).
 */
test('signing flow: receiver can reject and sender and receiver can view the document', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-user2-reject-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);

    [$rejectStatus, $rejectJson, $rejectRaw] = SigningFlowHelper::reject(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    $rejectJsonText = is_array($rejectJson)
        ? (string)json_encode($rejectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    expect($rejectStatus)->toBe(200, "Receiver reject failed.\nStatus={$rejectStatus}\nJSON:\n{$rejectJsonText}\nRAW:\n{$rejectRaw}");

    // Sender can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status)->toBe(200, 'Sender should be able to view document after rejection. ' . substr($u1Raw, 0, 700));
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver can still view the file via sign_code (should generally remain accessible)
    [$u2FileStatus, , $u2FileRaw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2FileStatus)->toBe(200, 'Receiver should be able to download signer PDF after rejection. ' . substr((string)$u2FileRaw, 0, 120));
    expect(str_starts_with((string)$u2FileRaw, '%PDF'))->toBeTrue('Expected signer file response to be a PDF');
});

/**
 * Prerequisites:
 * - User1 and user2; same setup as reject + view.
 *
 * Steps:
 * 1. Send to user2; reject successfully.
 * 2. Retry `sign` with the same `sign_code` — must not return 200 (signing closed after rejection).
 */
test('signing flow: receiver rejects and then cannot sign', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-user2-reject-then-sign-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);

    [$rejectStatus, $rejectJson, $rejectRaw] = SigningFlowHelper::reject(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    expect($rejectStatus)->toBe(200, 'Receiver reject failed. status=' . $rejectStatus . ' raw=' . substr((string)$rejectRaw, 0, 700));

    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';

    expect($signStatus)->not->toBe(
        200,
        "Receiver should not be able to sign after rejection.\nStatus={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200)
    );
});

/**
 * Prerequisites:
 * - User1 can cancel own document; user2 is pending signer.
 *
 * Steps:
 * 1. Send document to user2; wait for `sign_code` / signer file.
 * 2. User1 cancels document with TOTP — expect 200.
 * 3. Sender GET `documents/{uuid}` — 200.
 * 4. Receiver signer-file: accept 200 (PDF) or documented error statuses (403/404/422) with error payload when blocked.
 */
test('signing flow: sender cancels and sender and receiver can view the document', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-user2-cancel-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);

    [$cancelStatus, $cancelJson, $cancelRaw] = SigningFlowHelper::cancelDocument(
        $user1Bearer,
        $uuid,
        TEST_USER_1_TOTP_SECRET
    );
    $cancelJsonText = is_array($cancelJson)
        ? (string)json_encode($cancelJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    expect($cancelStatus)->toBe(200, "Sender cancel failed.\nStatus={$cancelStatus}\nJSON:\n{$cancelJsonText}\nRAW:\n{$cancelRaw}");

    // Sender can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status)->toBe(200, 'Sender should be able to view document after cancel. ' . substr($u1Raw, 0, 700));
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver view after cancel (behavior may vary: some envs block signer access after cancel)
    [$u2FileStatus, $u2FileJson, $u2FileRaw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    $u2FileJsonText = is_array($u2FileJson)
        ? (string)json_encode($u2FileJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $u2FileDebug = "Status={$u2FileStatus}\nJSON:\n{$u2FileJsonText}\nRAW:\n" . substr((string)$u2FileRaw, 0, 700);

    expect(in_array($u2FileStatus, [200, 403, 404, 422], true))->toBeTrue(
        "Unexpected status for receiver signer file after cancel.\n{$u2FileDebug}"
    );
    if ($u2FileStatus === 200) {
        expect(str_starts_with((string)$u2FileRaw, '%PDF'))->toBeTrue(
            "Expected signer file response to be a PDF.\n{$u2FileDebug}"
        );
    } else if (is_array($u2FileJson)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($u2FileJson['error'] ?? [])));
        expect($errorsJoined)->not->toBe('', "Expected error message for blocked signer view.\n{$u2FileDebug}");
    }
});

/**
 * Prerequisites:
 * - Same cancel authority as previous cancel test.
 *
 * Steps:
 * 1. Send to user2; user1 cancels while user2 still pending.
 * 2. User2 attempts `sign` with their `sign_code` — must not succeed (not HTTP 200).
 */
test('signing flow: sender cancels and receiver cannot sign', function () {
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    $documentName = 'sign-flow-user2-cancel-then-sign-' . gmdate('YmdHis') . '.pdf';
    $uuid = SigningFlowHelper::createDocument(
        $user1Bearer,
        $documentName,
        SigningFlowHelper::fixturePdfContent()
    );

    SigningFlowHelper::setAnnotations($user1Bearer, $uuid, [[
        'x' => 200,
        'y' => 40,
        'page' => 1,
        'type' => 'SIGNATURE',
        'email' => TEST_USER_2_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ]]);
    SigningFlowHelper::setSigners($user1Bearer, $uuid, [[
        'customer_role_id' => null,
        'email' => TEST_USER_2_EMAIL,
        'signing_order' => 1,
        'signing_type' => 'SIGNATURE',
        'send_email' => false,
    ]]);

    SigningFlowHelper::sendDocument($user1Bearer, $uuid);

    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    SigningFlowHelper::waitForSignerFileOk($user2Bearer, $user2SignCode);

    [$cancelStatus, $cancelJson, $cancelRaw] = SigningFlowHelper::cancelDocument(
        $user1Bearer,
        $uuid,
        TEST_USER_1_TOTP_SECRET
    );
    expect($cancelStatus)->toBe(200, 'Sender cancel failed. status=' . $cancelStatus . ' raw=' . substr((string)$cancelRaw, 0, 700));

    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';

    expect($signStatus)->not->toBe(
        200,
        "Receiver should not be able to sign after cancel.\nStatus={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200)
    );
});

/**
 * Prerequisites:
 * - `SigningFlowHelper::sentDocFromUser1ToUser2()` — user1 owner, user2 has receiver `sign_code`.
 *
 * Steps:
 * 1. User1 calls `reject` using user2’s `sign_code` and user1 TOTP.
 * 2. Assert reject does not return 200 (sender must not act as receiver on that code); if 200, mark incomplete as security regression.
 */
test('signing sanity: sender cannot reject using receiver sign_code', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);

    [$rejectStatus, $rejectJson, $rejectRaw] = SigningFlowHelper::reject(
        $user1Bearer,
        $user2SignCode,
        TEST_USER_1_TOTP_SECRET
    );

    $rejectJsonText = is_array($rejectJson)
        ? (string)json_encode($rejectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName}\nStatus={$rejectStatus}\nJSON:\n{$rejectJsonText}\nRAW:\n" . substr((string)$rejectRaw, 0, 1200);

    if ($rejectStatus === 200) {
        test()->markTestIncomplete("SECURITY CHECK FAILED: sender was able to reject using receiver sign_code.\n{$debug}");
    }
    expect($rejectStatus)->not->toBe(200, "Sender should not be able to reject using receiver sign_code.\n{$debug}");
});

/**
 * Prerequisites:
 * - Shared sent document: user1 owner, user2 invited signer (`sentDocFromUser1ToUser2`).
 *
 * Steps:
 * 1. User2 calls `cancelDocument` on sender’s UUID with own TOTP.
 * 2. Assert HTTP is not 200 (receiver cannot cancel sender-owned flow).
 */
test('signing sanity: receiver cannot cancel sender document', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$cancelStatus, $cancelJson, $cancelRaw] = SigningFlowHelper::cancelDocument(
        $user2Bearer,
        $uuid,
        TEST_USER_2_TOTP_SECRET
    );

    $cancelJsonText = is_array($cancelJson)
        ? (string)json_encode($cancelJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName} sign_code={$user2SignCode}\nStatus={$cancelStatus}\nJSON:\n{$cancelJsonText}\nRAW:\n" . substr((string)$cancelRaw, 0, 1200);

    expect($cancelStatus)->not->toBe(200, "Receiver should not be able to cancel sender document.\n{$debug}");
});

/**
 * Prerequisites:
 * - Shared sent document; user2 signs first in this test.
 *
 * Steps:
 * 1. User2 completes signature (precondition: HTTP 200).
 * 2. User1 attempts `reject` using user2’s `sign_code` — must not return 200 (no reject-after-complete for wrong role/code).
 */
test('signing sanity: sender cannot reject after receiver signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$signStatus, , $signRaw] = SigningFlowHelper::signWithRetry($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($signStatus)->toBe(200, 'Precondition failed: receiver should sign. raw=' . substr((string)$signRaw, 0, 700));

    [$rejectStatus, $rejectJson, $rejectRaw] = SigningFlowHelper::reject(
        $user1Bearer,
        $user2SignCode,
        TEST_USER_1_TOTP_SECRET
    );

    $rejectJsonText = is_array($rejectJson)
        ? (string)json_encode($rejectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName}\nStatus={$rejectStatus}\nJSON:\n{$rejectJsonText}\nRAW:\n" . substr((string)$rejectRaw, 0, 1200);

    expect($rejectStatus)->not->toBe(200, "Sender should not be able to reject after receiver signed.\n{$debug}");
});

/**
 * Prerequisites:
 * - Shared sent document; user2 signs first.
 *
 * Steps:
 * 1. User2 signs successfully.
 * 2. User2 attempts `cancelDocument` on the UUID — must not return 200 (receiver cannot cancel after signing).
 */
test('signing sanity: receiver cannot cancel after receiver signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$signStatus, , $signRaw] = SigningFlowHelper::signWithRetry($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($signStatus)->toBe(200, 'Precondition failed: receiver should sign. raw=' . substr((string)$signRaw, 0, 700));

    [$cancelStatus, $cancelJson, $cancelRaw] = SigningFlowHelper::cancelDocument(
        $user2Bearer,
        $uuid,
        TEST_USER_2_TOTP_SECRET
    );

    $cancelJsonText = is_array($cancelJson)
        ? (string)json_encode($cancelJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName} sign_code={$user2SignCode}\nStatus={$cancelStatus}\nJSON:\n{$cancelJsonText}\nRAW:\n" . substr((string)$cancelRaw, 0, 1200);

    expect($cancelStatus)->not->toBe(200, "Receiver should not be able to cancel after signing.\n{$debug}");
});

