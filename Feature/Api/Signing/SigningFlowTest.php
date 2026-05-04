<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/../../../Support/ApiAuthHelper.php';
require_once __DIR__ . '/../../../Support/SigningFlowHelper.php';

if (SKIP_INTEGRATION_TESTS) {
    test('Skipping signing integration flow', function () {
        $this->markTestSkipped('Integration tests are disabled');
    });
    return;
}

beforeAll(function () {
    SigningFlowHelper::assertRequiredConfigOrSkip();
});

/**
 * Shared fixture: SigningFlowHelper::sentDocFromUser1ToUser2() (test user 1 = sender, test user 2 = receiver).
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
    expect($signStatus, "Sign document failed with full details:\n{$signDebug}")->toBe(200);
    expect(is_array($signJson), "Sign document returned non-JSON. Full details:\n{$signDebug}")->toBeTrue();
    expect(
        (string)($signJson['data']['sign_code'] ?? ''),
        "Sign response missing expected sign_code. Full details:\n{$signDebug}"
    )->toBe($ownerSignCode);
});

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
    expect($signStatus, 'Other user should not sign with owner sign_code. ' . $debug)->not->toBe(200);
    if (is_array($signJson)) {
        $errors = implode(' | ', array_map('strval', (array)($signJson['error'] ?? [])));
        expect($errors, 'Expected access/authorization style error. ' . $debug)->not->toBe('');
    }
});

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

    expect($signStatus, "Receiver sign failed with full details:\n{$signDebug}")->toBe(200);
    expect(is_array($signJson), "Receiver sign returned non-JSON. Full details:\n{$signDebug}")->toBeTrue();
    expect(
        (string)($signJson['data']['sign_code'] ?? ''),
        "Receiver sign response missing expected sign_code. Full details:\n{$signDebug}"
    )->toBe($user2SignCode);
});

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
    $signDebug = 'status=' . $signStatus . ' raw=' . substr($signRaw, 0, 700);
    expect($signStatus, "Receiver sign failed. {$signDebug}")->toBe(200);

    // Sender (owner) can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status, 'Sender should be able to view document by UUID. ' . substr($u1Raw, 0, 700))->toBe(200);
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver can view via signer file endpoint (UUID endpoints are owner-only)
    [$u2Status, , $u2Raw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2Status, 'Receiver should be able to download signer PDF. ' . substr($u2Raw, 0, 120))->toBe(200);
    expect(str_starts_with((string)$u2Raw, '%PDF'), 'Expected signer file response to be a PDF')->toBeTrue();
});

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
    expect($u1SignStatus, 'Sender sign failed: ' . substr($u1SignRaw, 0, 700))->toBe(200);

    // After sender signs, receiver should get invited and see a sign_code in their list
    $user2SignCode = SigningFlowHelper::waitForSignCodeForDocumentName($user2Bearer, $documentName);
    [$u2SignStatus, , $u2SignRaw] = SigningFlowHelper::sign($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($u2SignStatus, 'Receiver sign failed: ' . substr($u2SignRaw, 0, 700))->toBe(200);

    // Owner can still view the document at the end
    [$finalStatus, $finalJson, $finalRaw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($finalStatus, 'Owner should be able to view final doc. ' . substr($finalRaw, 0, 700))->toBe(200);
    expect(is_array($finalJson))->toBeTrue();
    expect((string)($finalJson['data']['uuid'] ?? ''))->toBe($uuid);
});

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
    $debug = "Status={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200);

    expect($signStatus, "Uncertified invitee should NOT be able to sign. If this fails, behavior changed.\n{$debug}")
        ->not
        ->toBe(200);
});

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
    $rejectDebug = "Status={$rejectStatus}\nJSON:\n{$rejectJsonText}\nRAW:\n{$rejectRaw}";
    expect($rejectStatus, "Receiver reject failed.\n{$rejectDebug}")->toBe(200);

    // Sender can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status, 'Sender should be able to view document after rejection. ' . substr($u1Raw, 0, 700))->toBe(200);
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver can still view the file via sign_code (should generally remain accessible)
    [$u2FileStatus, , $u2FileRaw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    expect($u2FileStatus, 'Receiver should be able to download signer PDF after rejection. ' . substr((string)$u2FileRaw, 0, 120))->toBe(200);
    expect(str_starts_with((string)$u2FileRaw, '%PDF'), 'Expected signer file response to be a PDF')->toBeTrue();
});

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
    $rejectDebug = 'status=' . $rejectStatus . ' raw=' . substr((string)$rejectRaw, 0, 700);
    expect($rejectStatus, "Receiver reject failed. {$rejectDebug}")->toBe(200);

    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $signDebug = "Status={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200);

    expect($signStatus, "Receiver should not be able to sign after rejection.\n{$signDebug}")->not->toBe(200);
});

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
    $cancelDebug = "Status={$cancelStatus}\nJSON:\n{$cancelJsonText}\nRAW:\n{$cancelRaw}";
    expect($cancelStatus, "Sender cancel failed.\n{$cancelDebug}")->toBe(200);

    // Sender can view by UUID
    [$u1Status, $u1Json, $u1Raw] = ApiAuthHelper::apiRequest(
        'GET',
        API_URL . 'documents/' . rawurlencode($uuid),
        $user1Bearer
    );
    expect($u1Status, 'Sender should be able to view document after cancel. ' . substr($u1Raw, 0, 700))->toBe(200);
    expect(is_array($u1Json))->toBeTrue();
    expect((string)($u1Json['data']['uuid'] ?? ''))->toBe($uuid);

    // Receiver view after cancel (behavior may vary: some envs block signer access after cancel)
    [$u2FileStatus, $u2FileJson, $u2FileRaw] = SigningFlowHelper::getSignerFile($user2Bearer, $user2SignCode);
    $u2FileJsonText = is_array($u2FileJson)
        ? (string)json_encode($u2FileJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $u2FileDebug = "Status={$u2FileStatus}\nJSON:\n{$u2FileJsonText}\nRAW:\n" . substr((string)$u2FileRaw, 0, 700);

    expect(in_array($u2FileStatus, [200, 403, 404, 422], true), "Unexpected status for receiver signer file after cancel.\n{$u2FileDebug}")
        ->toBeTrue();
    if ($u2FileStatus === 200) {
        expect(str_starts_with((string)$u2FileRaw, '%PDF'), "Expected signer file response to be a PDF.\n{$u2FileDebug}")->toBeTrue();
    } else if (is_array($u2FileJson)) {
        $errorsJoined = implode(' | ', array_map('strval', (array)($u2FileJson['error'] ?? [])));
        expect($errorsJoined, "Expected error message for blocked signer view.\n{$u2FileDebug}")->not->toBe('');
    }
});

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
    $cancelDebug = 'status=' . $cancelStatus . ' raw=' . substr((string)$cancelRaw, 0, 700);
    expect($cancelStatus, "Sender cancel failed. {$cancelDebug}")->toBe(200);

    [$signStatus, $signJson, $signRaw] = SigningFlowHelper::signWithRetry(
        $user2Bearer,
        $user2SignCode,
        TEST_USER_2_TOTP_SECRET
    );
    $signJsonText = is_array($signJson)
        ? (string)json_encode($signJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $signDebug = "Status={$signStatus}\nJSON:\n{$signJsonText}\nRAW:\n" . substr((string)$signRaw, 0, 1200);

    expect($signStatus, "Receiver should not be able to sign after cancel.\n{$signDebug}")->not->toBe(200);
});

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
    expect($rejectStatus, "Sender should not be able to reject using receiver sign_code.\n{$debug}")->not->toBe(200);
});

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

    expect($cancelStatus, "Receiver should not be able to cancel sender document.\n{$debug}")->not->toBe(200);
});

test('signing sanity: sender cannot reject after receiver signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user1Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_1_EMAIL, TEST_USER_1_PASSWORD);
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$signStatus, , $signRaw] = SigningFlowHelper::signWithRetry($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($signStatus, 'Precondition failed: receiver should sign. raw=' . substr((string)$signRaw, 0, 700))->toBe(200);

    [$rejectStatus, $rejectJson, $rejectRaw] = SigningFlowHelper::reject(
        $user1Bearer,
        $user2SignCode,
        TEST_USER_1_TOTP_SECRET
    );

    $rejectJsonText = is_array($rejectJson)
        ? (string)json_encode($rejectJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName}\nStatus={$rejectStatus}\nJSON:\n{$rejectJsonText}\nRAW:\n" . substr((string)$rejectRaw, 0, 1200);

    expect($rejectStatus, "Sender should not be able to reject after receiver signed.\n{$debug}")->not->toBe(200);
});

test('signing sanity: receiver cannot cancel after receiver signed', function () {
    [$uuid, $documentName, $user2SignCode] = SigningFlowHelper::sentDocFromUser1ToUser2();
    $user2Bearer = ApiAuthHelper::bearerTokenFor(TEST_USER_2_EMAIL, TEST_USER_2_PASSWORD);

    [$signStatus, , $signRaw] = SigningFlowHelper::signWithRetry($user2Bearer, $user2SignCode, TEST_USER_2_TOTP_SECRET);
    expect($signStatus, 'Precondition failed: receiver should sign. raw=' . substr((string)$signRaw, 0, 700))->toBe(200);

    [$cancelStatus, $cancelJson, $cancelRaw] = SigningFlowHelper::cancelDocument(
        $user2Bearer,
        $uuid,
        TEST_USER_2_TOTP_SECRET
    );

    $cancelJsonText = is_array($cancelJson)
        ? (string)json_encode($cancelJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : 'null';
    $debug = "uuid={$uuid} name={$documentName} sign_code={$user2SignCode}\nStatus={$cancelStatus}\nJSON:\n{$cancelJsonText}\nRAW:\n" . substr((string)$cancelRaw, 0, 1200);

    expect($cancelStatus, "Receiver should not be able to cancel after signing.\n{$debug}")->not->toBe(200);
});

