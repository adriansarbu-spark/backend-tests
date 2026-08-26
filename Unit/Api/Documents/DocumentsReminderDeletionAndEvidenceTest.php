<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once PUBLIC_API . 'documents.php';
require_once __DIR__ . '/_support/DocumentsTestDoubles.php';

beforeEach(function () {
    $this->savedDocumentsLifecycleMethod = $_SERVER['REQUEST_METHOD'] ?? null;
});

afterEach(function () {
    if ($this->savedDocumentsLifecycleMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedDocumentsLifecycleMethod;
    }
});

/** @return array<string, mixed> */
function documents_reminder_signer(array $overrides = []): array
{
    return array_replace([
        'document_signer_id' => 802,
        'document_id' => 71,
        'customer_role_id' => 0,
        'email' => 'invitee@example.com',
        'signing_type' => 'SIGNATURE',
        'signature_kind' => ModelSigningSigner::KIND_QUALIFIED,
        'status_code' => 'INVITED',
        'sign_code' => 'invitee-sign-code',
        'invitation_type' => ModelSigningSigner::INVITATION_STANDARD,
    ], $overrides);
}

/**
 * Prerequisites:
 * - A pending owner document has an invited signer and a reserved reminder slot.
 *
 * Steps:
 * 1. Queue a reminder successfully, then repeat with a queue insert failure.
 * 2. Observe reservation commit/release calls around the email boundary.
 * 3. Assert success commits once and failure releases once without leaking a sign code.
 */
test('Documents API — reminder reservation commits only after queue success', function () {
    [$success, $successDocument, $successSigner, , , $successDb] = documents_test_controller();
    $successDocument->document = documents_test_document(['lifecycle_status_code' => 'PENDING']);
    $successSigner->signers = [documents_reminder_signer()];
    $success->testPost = ['email' => 'invitee@example.com'];
    $success->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'remind'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $success->index();

    [$failure, $failureDocument, $failureSigner, , , $failureDb] = documents_test_controller();
    $failureDocument->document = documents_test_document(['lifecycle_status_code' => 'PENDING']);
    $failureSigner->signers = [documents_reminder_signer()];
    $failureDb->lastId = 0;
    $failure->testPost = ['email' => 'invitee@example.com'];
    $failure->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'remind'];
    $failure->index();

    expect($success->statusCode)->toBe(200)
        ->and($success->committedReservations)->toBe([['key' => 'reservation']])
        ->and($success->releasedReservations)->toBe([])
        ->and($success->json['data']['email'])->toBe('invitee@example.com')
        ->and($failure->statusCode)->toBe(500)
        ->and($failure->json['error'])->toContain('document_remind_email_queue_failed')
        ->and($failure->releasedReservations)->toBe([['key' => 'reservation']])
        ->and($failure->committedReservations)->toBe([])
        ->and(json_encode([$success->json, $failure->json]))->not->toContain('invitee-sign-code')
        ->and(array_filter($successDb->queries, static fn (string $sql): bool => str_contains($sql, 'mail_queue')))->not->toBe([]);
});

/**
 * Prerequisites:
 * - Reminder lifecycle, signer state, and cooldown checks precede queueing.
 *
 * Steps:
 * 1. Exercise a non-pending document, non-actionable signer, missing sign code, and cooldown denial.
 * 2. Assert stable status/application codes.
 * 3. Assert no reservation is committed or released by a pre-queue denial.
 */
test('Documents API — reminder guards stop before the email queue', function (array $documentPatch, array $signerPatch, ?array $remindCheck, int $status, string $error) {
    [$controller, $document, $signer, , , $db] = documents_test_controller();
    $document->document = documents_test_document(array_replace(['lifecycle_status_code' => 'PENDING'], $documentPatch));
    $signer->signers = [documents_reminder_signer($signerPatch)];
    if ($remindCheck !== null) {
        $controller->remindCheck = $remindCheck;
    }
    $controller->testPost = ['email' => 'invitee@example.com'];
    $controller->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'remind'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $controller->index();

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toContain($error)
        ->and($controller->committedReservations)->toBe([])
        ->and($controller->releasedReservations)->toBe([])
        ->and(array_filter($db->queries, static fn (string $sql): bool => str_contains($sql, 'mail_queue')))->toBe([]);
})->with([
    'wrong lifecycle' => [['lifecycle_status_code' => 'DRAFT'], [], null, 422, 'invalid_document_lifecycle_for_reminder'],
    'signed signer' => [[], ['status_code' => 'SIGNED'], null, 422, 'invalid_signer_status_for_reminder'],
    'missing sign code' => [[], ['sign_code' => ''], null, 500, 'signer_sign_code_missing'],
    'cooldown' => [[], [], ['ok' => false, 'error' => 'document_remind_rate_limited', 'retry_after_seconds' => 120], 429, 'document_remind_rate_limited'],
]);

/**
 * Prerequisites:
 * - Active owner and signer ceremonies cannot be silently hidden.
 *
 * Steps:
 * 1. Delete a pending document as owner and as an unanswered signer.
 * 2. Assert cancel/reject requirements.
 * 3. Assert no document, visibility, or file-version write occurs.
 */
test('Documents API — pending delete requires explicit lifecycle action', function () {
    [$owner, $ownerDocument, $ownerSigner, $ownerVisibility, $ownerFiles] = documents_test_controller();
    $ownerDocument->document = documents_test_document(['lifecycle_status_code' => 'PENDING']);
    $ownerSigner->signers = [documents_reminder_signer()];
    $owner->request->get = ['uuid' => 'document-owner-uuid'];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $owner->index();

    $signerCustomer = new DocumentsCustomerStub(customerId: 202, roleId: 55, companyId: 88);
    [$invitee, $inviteeDocument, $inviteeSigner, $inviteeVisibility, $inviteeFiles] = documents_test_controller($signerCustomer);
    $inviteeDocument->document = documents_test_document(['lifecycle_status_code' => 'PENDING']);
    $inviteeDocument->canActAsOwner = false;
    $inviteeSigner->signers = [documents_reminder_signer(['customer_role_id' => 55])];
    $invitee->useremail = 'invitee@example.com';
    $invitee->request->get = ['uuid' => 'document-owner-uuid'];
    $invitee->index();

    expect($owner->statusCode)->toBe(409)
        ->and($owner->json['error'])->toContain('cancel_required_before_delete')
        ->and($invitee->statusCode)->toBe(409)
        ->and($invitee->json['error'])->toContain('reject_required_before_delete')
        ->and($ownerDocument->writes)->toBe([])
        ->and($inviteeDocument->writes)->toBe([])
        ->and($ownerVisibility->writes)->toBe([])
        ->and($inviteeVisibility->writes)->toBe([])
        ->and($ownerFiles->purgedDocumentIds)->toBe([])
        ->and($inviteeFiles->purgedDocumentIds)->toBe([]);
});

/**
 * Prerequisites:
 * - A signer who has completed their action may hide only their visibility row.
 *
 * Steps:
 * 1. Delete a completed document as a bound signer.
 * 2. Assert one principal visibility hide.
 * 3. Assert document metadata and files are never purged by the signer path.
 */
test('Documents API — completed signer deletion hides only that principal', function () {
    $customer = new DocumentsCustomerStub(customerId: 202, roleId: 55, companyId: 88);
    [$controller, $document, $signer, $visibility, $files] = documents_test_controller($customer);
    $document->document = documents_test_document(['lifecycle_status_code' => 'COMPLETED']);
    $document->canActAsOwner = false;
    $signer->signers = [documents_reminder_signer(['customer_role_id' => 55, 'status_code' => 'SIGNED'])];
    $controller->useremail = 'invitee@example.com';
    $controller->request->get = ['uuid' => 'document-owner-uuid'];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';

    $controller->index();

    expect($controller->statusCode)->toBe(200)
        ->and($visibility->writes)->toHaveCount(1)
        ->and($visibility->writes[0]['method'])->toBe('hideVisibilityForPrincipal')
        ->and($visibility->writes[0]['roleId'])->toBe(55)
        ->and($document->writes)->toBe([])
        ->and($files->purgedDocumentIds)->toBe([]);
});

/**
 * Prerequisites:
 * - Owner deletion soft-hides metadata and purges only when lifecycle/stakeholder rules allow it.
 *
 * Steps:
 * 1. Delete a draft, a completed document with a live stakeholder, and an explicit-purge document.
 * 2. Exercise company-admin uploader visibility and explicit purge failure.
 * 3. Assert the exact soft-delete, visibility, and purge side effects.
 */
test('Documents API — owner delete and purge preserve stakeholder invariants', function () {
    [$draft, $draftDocument, , $draftVisibility, $draftFiles] = documents_test_controller();
    $draftDocument->document = documents_test_document(['lifecycle_status_code' => 'DRAFT']);
    $draft->request->get = ['uuid' => 'document-owner-uuid'];
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $draft->index();

    [$completed, $completedDocument, , $completedVisibility, $completedFiles] = documents_test_controller();
    $completedDocument->document = documents_test_document(['lifecycle_status_code' => 'COMPLETED']);
    $completedVisibility->hasLiveStakeholders = true;
    $completed->request->get = ['uuid' => 'document-owner-uuid'];
    $completed->index();

    $adminCustomer = new DocumentsCustomerStub(customerId: 303, roleId: 99, companyId: 77);
    [$purge, $purgeDocument, , $purgeVisibility, $purgeFiles] = documents_test_controller($adminCustomer);
    $purgeDocument->document = documents_test_document(['lifecycle_status_code' => 'COMPLETED']);
    $purgeDocument->canActAsOwner = true;
    $purgeFiles->purgeResult = ['deleted' => 0, 'failed' => 1];
    $purge->request->get = ['uuid' => 'document-owner-uuid', 'mode' => 'purge'];
    $purge->index();

    expect($draft->statusCode)->toBe(200)
        ->and($draftDocument->writes[0]['method'])->toBe('softDeleteDocument')
        ->and($draftFiles->purgedDocumentIds)->toBe([71])
        ->and($completed->statusCode)->toBe(200)
        ->and($completedFiles->purgedDocumentIds)->toBe([])
        ->and($purge->statusCode)->toBe(500)
        ->and($purge->json['error'])->toContain('document_purge_failed')
        ->and($purgeFiles->purgedDocumentIds)->toBe([71])
        ->and(array_column($purgeVisibility->writes, 'roleId'))->toBe([99, 9]);
});

/**
 * Prerequisites:
 * - Certificate-of-completion access is owner-only and only valid after completion.
 *
 * Steps:
 * 1. Request evidence for an incomplete document, a hidden document, and a completed document with missing storage.
 * 2. Assert lifecycle, existence-hiding, and missing-evidence codes.
 * 3. Assert no upload path or file code is disclosed.
 */
test('Documents API — completion evidence fails closed for lifecycle access and storage gaps', function () {
    [$incomplete, $incompleteDocument] = documents_test_controller();
    $incompleteDocument->document = documents_test_document(['lifecycle_status_code' => 'PENDING']);
    $incomplete->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'audit-certificate'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $incomplete->index();

    [$hidden, $hiddenDocument, , $hiddenVisibility] = documents_test_controller();
    $hiddenDocument->document = documents_test_document(['lifecycle_status_code' => 'COMPLETED']);
    $hiddenVisibility->accessible = false;
    $hidden->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'audit-certificate'];
    $hidden->index();

    [$missing, $missingDocument] = documents_test_controller();
    $missingDocument->document = documents_test_document([
        'lifecycle_status_code' => 'COMPLETED',
        'audit_certificate_file_code' => 'private-audit-file-code',
    ]);
    $missing->request->get = ['uuid' => 'document-owner-uuid', 'action' => 'audit-certificate'];
    $missing->index();

    expect($incomplete->statusCode)->toBe(422)
        ->and($incomplete->json['error'])->toContain('document_not_completed')
        ->and($hidden->statusCode)->toBe(404)
        ->and($hidden->json['error'])->toContain('document_not_found')
        ->and($missing->statusCode)->toBe(404)
        ->and($missing->json['error'])->toContain('audit_certificate_not_available')
        ->and(json_encode([$incomplete->json, $hidden->json, $missing->json]))
        ->not->toContain('private-audit-file-code')
        ->not->toContain(DIR_UPLOAD);
});
