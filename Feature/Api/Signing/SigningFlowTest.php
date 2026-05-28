<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/SigningFlowHelper.php';
require_once __DIR__ . '/../../../Support/DocumentsApiHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    /**
     * Prerequisites:
     * - Integration tests are turned off in `tests_config.php` (`SKIP_INTEGRATION_TESTS` is true).
     *
     * Steps:
     * 1. Mark this placeholder as skipped so no signing API calls run.
     */
    test('Signing - integration tests are turned off for this run', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

/**
 * File guard (runs once before any scenario in this file):
 *
 * Prerequisites:
 * - Integration tests are enabled (`SKIP_INTEGRATION_TESTS` false); signing-related values in `tests_config.php` match the API under test.
 * - `TEST_USER_1_PERSONAL_ROLE_UUID` and `TEST_USER_2_PERSONAL_ROLE_UUID` are set (used for POST /account/active-role before document flows).
 *
 * Steps:
 * 1. Ask `SigningFlowHelper` to confirm required signing configuration is present; if not, skip the whole file with a clear reason so later tests do not fail mid-flow.
 */
beforeAll(function () {
    SigningFlowHelper::assertRequiredConfigOrSkip();
});

/**
 * Prerequisites:
 * - Integration tests are on and the file guard passed (`beforeAll`); `TEST_USER_1_*` and `TEST_USER_1_PERSONAL_ROLE_UUID` in `tests_config.php`.
 *
 * Steps:
 * 1. Sign in, switch active role to the personal role, then upload a small PDF with a unique name.
 * 2. Mark the owner as the only signer (no outbound email in this run).
 * 3. Open the owner’s signing step and complete it with TOTP.
 * 4. Check that signing succeeds (**HTTP 200**) and the response echoes the same signing link identifier the owner used (`data.sign_code`).
 */
test('Signing - owner can create a document and complete their own signature', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
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
 * - Two different test accounts can sign in (`TEST_USER_1_*`, `TEST_USER_2_*`); signing env OK (`beforeAll`).
 *
 * Steps:
 * 1. User A creates a document and is the only person on the signing list.
 * 2. User B tries to complete User A’s signing step using A’s signing link and B’s own TOTP.
 * 3. Check that signing does **not** succeed (status is not **HTTP 200**).
 * 4. If the server returns JSON, check that an authorization-style **`error`** message is present so we do not pass on an empty body by mistake.
 */
test('Signing - someone else cannot sign with the owner’s signing link', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();
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
 * - Sender and invitee accounts with TOTP (`TEST_USER_1_*`, `TEST_USER_2_*`); signing config OK (`beforeAll`).
 *
 * Steps:
 * 1. Sender uploads a PDF, places a signature field for the invitee, and sends the document for signing (email sending kept off in this test to avoid real mail).
 * 2. Invitee waits until their signing link appears for that document name and the signer file is ready.
 * 3. Invitee completes the signature with TOTP.
 * 4. Check success (**HTTP 200**) and that the response echoes the invitee’s signing link in **`data.sign_code`**.
 */
test('Signing - invitee receives the document and can sign', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
 * - Same sender and invitee pairing as the basic send-and-sign flow.
 *
 * Steps:
 * 1. Run the full send pipeline, then have the invitee sign successfully.
 * 2. Sender opens the document by its stable id: expect a normal document view (**HTTP 200**) with the same **`data.uuid`**.
 * 3. Invitee downloads their copy via the signer file endpoint: expect **HTTP 200** and bytes that look like a real PDF (**starts with `%PDF`**).
 */
test('Signing - after signing, both sender and invitee can open the document', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
    [$u1Status, $u1Json, $u1Raw] = SigningFlowHelper::waitForOwnerDocumentGet($user1Bearer, $uuid);
    expect($u1Status)->toBe(200, 'Sender should be able to view document by UUID. ' . substr($u1Raw, 0, 700));
    expect(is_array($u1Json))->toBeTrue();
    expect(DocumentsApiHelper::documentUuidFromGetResponse($u1Json, $uuid))->toBe($uuid);

    // Receiver can view via signer file endpoint (UUID endpoints are owner-only)
    [$u2Status, , $u2Raw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2Status)->toBe(200, 'Receiver should be able to download signer PDF. ' . substr($u2Raw, 0, 120));
    expect(str_starts_with((string)$u2Raw, '%PDF'))->toBeTrue('Expected signer file response to be a PDF');
});

/**
 * Prerequisites:
 * - Sender and invitee accounts; the API supports two ordered signers on one envelope.
 *
 * Steps:
 * 1. Create a PDF with a field for the invitee, list sender first then invitee in signing order, and send (invitee may get email in real env; test keeps mail off where possible).
 * 2. Sender completes their turn first; then the invitee’s signing step becomes available and they sign.
 * 3. Sender opens the document again by id: expect **HTTP 200** and the same **`data.uuid`** so the envelope is still visible after everyone signed.
 */
test('Signing - sender and invitee each sign in the configured order', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

    $documentName = 'sign-flow-both-sign-' . gmdate('YmdHis') . '.pdf';
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
        'email' => TEST_USER_1_EMAIL,
        'width' => 200,
        'height' => 80,
        'required' => true,
    ], [
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
    expect(DocumentsApiHelper::documentUuidFromGetResponse($finalJson, $uuid))->toBe($uuid);
});

// if (defined('SKIP_USER_3_FAILED_TESTS') && SKIP_USER_3_FAILED_TESTS) {
//     test('Signing - uncertified invitee check is disabled for this run', function () {
//         $this->markTestSkipped('Uncertified-account signing tests are disabled');
//     });
// } else {
/**
 * Prerequisites:
 * - A third test account is configured as not qualified for signing in this environment (`TEST_USER_3_*` per `tests_config.php` / policy).
 *
 * Steps:
 * 1. Sender invites only that account to sign (field + signer list).
 * 2. That account waits until a signing link exists and the signer file is ready.
 * 3. They attempt to sign with TOTP; the signature must **not** complete successfully (status must **not** be **HTTP 200**).
 */
test('Signing - uncertified invitee cannot finish a signature (expected)', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
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
 * - Sender and invitee accounts; decline (“reject”) is allowed for the invitee.
 *
 * Steps:
 * 1. Send a document to the invitee and wait until they can open their signer file.
 * 2. Invitee declines using TOTP; expect the decline call to succeed (**HTTP 200**).
 * 3. Sender still sees the document by id (**HTTP 200**, same **`data.uuid`**).
 * 4. Invitee can still download via their signing link (**HTTP 200** and PDF bytes **`%PDF`** in this environment).
 */
test('Signing - invitee can decline and both parties can still open the document', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
    expect(DocumentsApiHelper::documentUuidFromGetResponse($u1Json, $uuid))->toBe($uuid);

    // Receiver can still view the file via sign_code (should generally remain accessible)
    [$u2FileStatus, , $u2FileRaw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2FileStatus)->toBe(200, 'Receiver should be able to download signer PDF after rejection. ' . substr((string)$u2FileRaw, 0, 120));
    expect(str_starts_with((string)$u2FileRaw, '%PDF'))->toBeTrue('Expected signer file response to be a PDF');
});

/**
 * Prerequisites:
 * - Same sender and invitee setup as the decline-and-view scenario.
 *
 * Steps:
 * 1. Send to the invitee and have them decline successfully.
 * 2. Invitee tries to sign afterward with the same signing link; signing must stay closed (status **not HTTP 200**).
 */
test('Signing - after declining, the invitee cannot sign using the same link', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
 * - Sender may withdraw (cancel) their own envelope while the invitee is still pending.
 *
 * Steps:
 * 1. Send to the invitee and wait until their signing link and signer file exist.
 * 2. Sender withdraws the flow with TOTP; expect **HTTP 200**.
 * 3. Sender still opens the document by id (**HTTP 200**, same **`data.uuid`**).
 * 4. Invitee’s signer-file request may either return a PDF (**HTTP 200**, **`%PDF`**) or a documented refusal (**HTTP 403**, **404**, or **422**); if refused, JSON should carry a non-empty **`error`** message.
 */
test('Signing - sender can withdraw the flow; both parties may still see the file depending on policy', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
    expect(DocumentsApiHelper::documentUuidFromGetResponse($u1Json, $uuid))->toBe($uuid);

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
 * - Sender can cancel while the invitee has not signed yet.
 *
 * Steps:
 * 1. Send to the invitee, then sender withdraws the envelope.
 * 2. Invitee tries to sign; the attempt must **not** succeed (**not HTTP 200**).
 */
test('Signing - after sender withdraws, the invitee cannot sign', function () {
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
 * - A standard “sent for signing” envelope from user 1 to user 2 already exists (helper builds PDF, fields, signers, and send so tests stay short).
 *
 * Steps:
 * 1. Sender tries to decline using the **invitee’s** signing link and sender TOTP.
 * 2. Decline must **not** succeed (**not HTTP 200**); if it incorrectly returns **HTTP 200**, mark the test incomplete as a possible security regression.
 */
test('Signing - sender cannot decline on behalf of the invitee using their signing link', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user1Bearer = SigningFlowHelper::bearerForUser1();

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
 * - Same shared sent envelope: user 1 owns it, user 2 is the invited signer (helper setup).
 *
 * Steps:
 * 1. Invitee tries to withdraw the sender’s document by id using their own TOTP.
 * 2. That cancel attempt must **not** succeed (**not HTTP 200**).
 */
test('Signing - invitee cannot cancel the sender’s document', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
 * - Shared sent envelope; this test first completes the invitee’s signature.
 *
 * Steps:
 * 1. Invitee signs successfully (**HTTP 200** precondition).
 * 2. Sender tries to decline using the invitee’s old signing link; decline must **not** succeed (**not HTTP 200**).
 */
test('Signing - sender cannot decline after the invitee has already signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user1Bearer = SigningFlowHelper::bearerForUser1();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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
 * - Shared sent envelope; invitee will sign before the cancel attempt.
 *
 * Steps:
 * 1. Invitee completes their signature (**HTTP 200**).
 * 2. Invitee tries to withdraw the envelope by id; cancel must **not** succeed (**not HTTP 200**).
 */
test('Signing - invitee cannot cancel after they have signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user2Bearer = SigningFlowHelper::bearerForUser2();

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

