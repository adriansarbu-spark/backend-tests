<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnroll::handleChooseSca() and
 * ::handleAcceptTerms(): state validation, SCA method allow-listing, and the
 * legal-document acceptance gate.
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
});

/**
 * Prerequisites:
 * - Session already past the choosable window (state=sms_verified is
 *   neither 'created' nor 'terms_accepted').
 *
 * Steps:
 * 1. POST choose_sca with a nominally valid method.
 * 2. Assert 422 invalid_enrollment_state and the model's
 *    setScaMethodChosen() is never called.
 */
test('CSC hosted enrollment — choose_sca in wrong state returns 422 invalid_enrollment_state', function () {
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_SMS_VERIFIED]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'choose_sca');
    $c->setPostPayload(['sca_method' => 'sms']);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['invalid_enrollment_state'])
        ->and($enrollment->setScaMethodChosenCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session in state=created, offering both sms and totp.
 *
 * Steps:
 * 1. POST choose_sca with a method outside the offered set ('email').
 * 2. Assert 400 invalid_sca_method.
 */
test('CSC hosted enrollment — choose_sca with a method not offered returns 400 invalid_sca_method', function () {
    $session = csc_hosted_enrollment_session([
        'state'       => ModelCscEnrollmentSession::STATE_CREATED,
        'sca_methods' => 'sms,totp',
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'choose_sca');
    $c->setPostPayload(['sca_method' => 'email']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_sca_method'])
        ->and($enrollment->setScaMethodChosenCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session in state=created offering both sms and totp; no method chosen
 *   yet.
 *
 * Steps:
 * 1. POST choose_sca with 'totp' (an offered method).
 * 2. Assert 200, data.sca_method='totp', and setScaMethodChosen() was called
 *    once with the chosen method.
 */
test('CSC hosted enrollment — choose_sca with an offered method records the choice', function () {
    $session = csc_hosted_enrollment_session([
        'state'       => ModelCscEnrollmentSession::STATE_CREATED,
        'sca_methods' => 'sms,totp',
    ]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'choose_sca');
    $c->setPostPayload(['sca_method' => 'totp']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['sca_method' => 'totp'])
        ->and($enrollment->setScaMethodChosenCalls)->toBe(1)
        ->and($enrollment->lastScaMethodChosen)->toBe('totp');
});

/**
 * Prerequisites:
 * - Session in state=terms_accepted (choose_sca also allows this state, not
 *   only 'created').
 *
 * Steps:
 * 1. POST choose_sca with 'sms'.
 * 2. Assert 200 (proves terms_accepted is also an allowed state, per
 *    handleChooseSca's state allow-list).
 */
test('CSC hosted enrollment — choose_sca is also allowed once terms are accepted', function () {
    $session = csc_hosted_enrollment_session([
        'state'       => ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED,
        'sca_methods' => 'sms,totp',
    ]);
    [$c] = csc_hosted_enrollment_controller($session, 'choose_sca');
    $c->setPostPayload(['sca_method' => 'sms']);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data'])->toBe(['sca_method' => 'sms']);
});

/**
 * Prerequisites:
 * - Session already past the acceptance window (state=sms_verified).
 *
 * Steps:
 * 1. POST accept_terms.
 * 2. Assert 422 invalid_enrollment_state and markTermsAccepted() is never
 *    called.
 */
test('CSC hosted enrollment — accept_terms in wrong state returns 422 invalid_enrollment_state', function () {
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_SMS_VERIFIED]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'accept_terms');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['invalid_enrollment_state'])
        ->and($enrollment->markTermsAcceptedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session in state=created; the stubbed legal/document model (default
 *   CscLegalDocumentModelStub) returns no active documents for the CSC
 *   enrollment flow, so LegalDocumentAcceptance::validateSubmission's
 *   "no active documents" branch fires before any UUID matching.
 *
 * Steps:
 * 1. POST accept_terms with no accepted_legal_document_uuids.
 * 2. Characterize the actual behavior: with zero configured documents the
 *    submission is rejected as no_documents_configured (400), and the
 *    session is left untouched (markTermsAccepted not called). This is the
 *    real behavior of an integrator whose company has no DPA/legal flow
 *    configured yet — it is not a false "success".
 */
test('CSC hosted enrollment — accept_terms with no legal documents configured returns 400 no_documents_configured', function () {
    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    [$c, $enrollment] = csc_hosted_enrollment_controller($session, 'accept_terms');
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['success'])->toBe(0)
        ->and($c->json['error'])->toBe(['no_documents_configured'])
        ->and($enrollment->markTermsAcceptedCalls)->toBe(0);
});

/**
 * Prerequisites:
 * - Session in state=created; a single active legal document is configured
 *   for the CSC enrollment flow via a custom CscLegalDocumentModelStub.
 *
 * Steps:
 * 1. POST accept_terms with that document's uuid accepted.
 * 2. Assert 200, markTermsAccepted() called once with the submitted uuid,
 *    and the session transitions to terms_accepted (observed via a
 *    follow-up choose_sca-style refetch through the mutated byToken).
 */
test('CSC hosted enrollment — accept_terms with a matching accepted document succeeds', function () {
    $uuid = '11111111-1111-1111-1111-111111111111';
    $legalDocument = new CscLegalDocumentModelStub();
    $legalDocument->activeForFlowRows = [
        ['uuid' => $uuid, 'version' => '1.0', 'name' => 'DPA', 'upload_code' => ''],
    ];

    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byToken = $session;
    [$registry] = csc_integrator_registry($enrollment, null, null, null, null, null, null, [], $legalDocument);
    $registry->get('request')->get = ['enrollment_token' => 'token-abc', 'action' => 'accept_terms'];
    $c = new TestableControllerPublicAPIV1CscEnroll($registry);
    $c->setPostPayload(['accepted_legal_document_uuids' => [$uuid]]);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($enrollment->markTermsAcceptedCalls)->toBe(1)
        ->and($enrollment->lastAcceptedUuids)->toBe([$uuid])
        ->and($c->json['data']['status'])->toBe('terms_accepted');
});

/**
 * Prerequisites:
 * - Same single-document configuration as above, but the request omits the
 *   acceptance.
 *
 * Steps:
 * 1. POST accept_terms with an empty body.
 * 2. Assert 400 legal_document_acceptance_incomplete (characterizes the
 *    "must accept every configured document" behavior).
 */
test('CSC hosted enrollment — accept_terms without accepting a configured document returns 400 legal_document_acceptance_incomplete', function () {
    $uuid = '22222222-2222-2222-2222-222222222222';
    $legalDocument = new CscLegalDocumentModelStub();
    $legalDocument->activeForFlowRows = [
        ['uuid' => $uuid, 'version' => '1.0', 'name' => 'DPA', 'upload_code' => ''],
    ];

    $session = csc_hosted_enrollment_session(['state' => ModelCscEnrollmentSession::STATE_CREATED]);
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byToken = $session;
    [$registry] = csc_integrator_registry($enrollment, null, null, null, null, null, null, [], $legalDocument);
    $registry->get('request')->get = ['enrollment_token' => 'token-abc', 'action' => 'accept_terms'];
    $c = new TestableControllerPublicAPIV1CscEnroll($registry);
    $c->setPostPayload([]);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['legal_document_acceptance_incomplete'])
        ->and($enrollment->markTermsAcceptedCalls)->toBe(0);
});
