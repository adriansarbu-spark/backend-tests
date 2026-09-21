<?php

declare(strict_types=1);

require_once __DIR__ . '/_support/PartnerTestDoubles.php';

/**
 * Unit tests for {@see ControllerPublicAPIV1PartnerOnboard} (preview, status, claim, consent).
 */

beforeEach(function () {
    $this->partnerHadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->partnerSavedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    partner_set_method('GET');
});

afterEach(function () {
    if ($this->partnerHadMethod) {
        $_SERVER['REQUEST_METHOD'] = $this->partnerSavedMethod;
    } else {
        unset($_SERVER['REQUEST_METHOD']);
    }
});

/**
 * Prerequisites:
 * - Unit harness only; no onboard_token in the query.
 *
 * Steps:
 * 1. GET onboard index without a token.
 * 2. Assert HTTP 400 `onboard_token_required`.
 * 3. Prove partner models were not loaded.
 */
test('Partner - onboard without token returns 400 before model work', function () {
    [$registry, $load] = partner_registry();
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->checkPluginCalls)->toBe(1)
        ->and($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['onboard_token_required'])
        ->and($c->sendResponseCalls)->toBe(1)
        ->and($load->loadedModels)->toBe([]);
});

/**
 * Prerequisites:
 * - Anonymous GET preview; link stub finds no row for the token.
 *
 * Steps:
 * 1. GET preview with an unknown token.
 * 2. Assert HTTP 404 `link_not_found`.
 */
test('Partner - onboard preview unknown token returns link_not_found', function () {
    $link = new SponsorshipsModelStub(['getLinkByToken' => null]);
    [$registry, $load] = partner_registry(
        query: ['onboard_token' => 'tok-unknown'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found'])
        ->and($load->loadedModels)->toContain('partner/customer_link')
        ->and($link->callsTo('getLinkByToken'))->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Token resolves to a partner-revoked invite.
 *
 * Steps:
 * 1. GET preview.
 * 2. Assert HTTP 410 `link_revoked` (no onboarding payload).
 */
test('Partner - onboard preview revoked link returns 410', function () {
    $row = partner_sample_link(['status' => ModelPartnerCustomerLink::STATUS_REVOKED_PARTNER]);
    $link = new SponsorshipsModelStub(['getLinkByToken' => $row]);
    [$registry] = partner_registry(
        query: ['onboard_token' => 'tok-revoked'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(410)
        ->and($c->json['error'])->toBe(['link_revoked'])
        ->and($c->json['data']['onboarding'] ?? null)->toBeNull();
});

/**
 * Prerequisites:
 * - Invited simplifi_email link whose token_expires_at is in the past.
 *
 * Steps:
 * 1. GET preview.
 * 2. Assert HTTP 410 `link_token_expired` with token_state expired and can_resend true.
 */
test('Partner - onboard preview expired invited token returns 410 with resend hint', function () {
    $row = partner_sample_link([
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'distribution' => 'simplifi_email',
    ]);
    $link = new SponsorshipsModelStub(['getLinkByToken' => $row]);
    [$registry] = partner_registry(
        query: ['onboard_token' => 'tok-expired', 'locale' => 'en-gb'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(410)
        ->and($c->json['error'])->toBe(['link_token_expired'])
        ->and($c->json['data']['token_state'])->toBe('expired')
        ->and($c->json['data']['can_resend'])->toBeTrue()
        ->and($c->json['data']['distribution'])->toBe('simplifi_email')
        ->and($c->json['data']['program'])->toBeArray();
});

/**
 * Prerequisites:
 * - Valid invited link; stepStateForLink stubbed; locale query avoids language model.
 *
 * Steps:
 * 1. GET preview.
 * 2. Assert HTTP 200 onboarding shape (status, steps, next_step claim, program).
 */
test('Partner - onboard preview success returns onboarding steps and next claim', function () {
    $row = partner_sample_link();
    $steps = partner_sample_steps();
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'stepStateForLink' => $steps,
    ]);
    [$registry] = partner_registry(
        query: ['onboard_token' => 'tok-ok', 'locale' => 'en-gb'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['onboarding']['status'])->toBe('invited')
        ->and($c->json['data']['onboarding']['token_state'])->toBe('valid')
        ->and($c->json['data']['onboarding']['invited_given_name'])->toBe('Alex')
        ->and($c->json['data']['onboarding']['steps'])->toBe($steps)
        ->and($c->json['data']['onboarding']['next_step'])->toBe('claim')
        ->and($c->json['data']['onboarding']['program'])->toHaveKey('partner_name')
        ->and($link->callsTo('stepStateForLink'))->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Status action; token unknown.
 *
 * Steps:
 * 1. GET status for missing token row.
 * 2. Assert HTTP 404 `link_not_found`.
 */
test('Partner - onboard status unknown token returns link_not_found', function () {
    $link = new SponsorshipsModelStub(['getLinkByToken' => null]);
    [$registry] = partner_registry(
        query: ['onboard_token' => 'tok-missing', 'action' => 'status'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['link_not_found']);
});

/**
 * Prerequisites:
 * - Claimed onboarding link; stubbed step flags with identity still pending.
 *
 * Steps:
 * 1. GET status.
 * 2. Assert status/steps/next_step mirror stepStateForLink (identity_verification).
 */
test('Partner - onboard status maps stepStateForLink into next_step', function () {
    $row = partner_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_ONBOARDING,
        'customer_id' => 10,
    ]);
    $steps = partner_sample_steps([
        'claimed' => true,
        'identity_verified' => false,
    ]);
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'stepStateForLink' => $steps,
    ]);
    [$registry] = partner_registry(
        query: ['onboard_token' => 'tok-status', 'action' => 'status'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['status'])->toBe('onboarding')
        ->and($c->json['data']['steps'])->toBe($steps)
        ->and($c->json['data']['next_step'])->toBe('identity_verification');
});

/**
 * Prerequisites:
 * - Customer id 0; claim action; models load before auth inside handleClaim.
 *
 * Steps:
 * 1. POST claim while unsigned.
 * 2. Assert HTTP 401 `unauthenticated` before claimLink runs.
 */
test('Partner - onboard claim unauthenticated returns 401 before claimLink', function () {
    partner_set_method('POST');
    $link = new SponsorshipsModelStub(['getLinkByToken' => partner_sample_link()]);
    [$registry, $load] = partner_registry(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
        query: ['onboard_token' => 'tok-claim', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($load->loadedModels)->toContain('partner/customer_link')
        ->and($link->callsTo('claimLink'))->toBe([])
        ->and($link->callsTo('getLinkByToken'))->toBe([]);
});

/**
 * Prerequisites:
 * - Signed-in customer without post permission on onboard.
 *
 * Steps:
 * 1. POST claim.
 * 2. Assert HTTP 403 `access_denied` before token lookup or claimLink.
 */
test('Partner - onboard claim missing route permission returns 403 before token work', function () {
    partner_set_method('POST');
    $link = new SponsorshipsModelStub(['getLinkByToken' => partner_sample_link()]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-claim', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        ['post' => []],
    );
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['access_denied'])
        ->and($link->callsTo('getLinkByToken'))->toBe([])
        ->and($link->callsTo('claimLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated claimant; invited token already expired.
 *
 * Steps:
 * 1. POST claim.
 * 2. Assert HTTP 410 `link_token_expired` with can_resend; claimLink not called.
 */
test('Partner - onboard claim expired invited token returns 410', function () {
    partner_set_method('POST');
    $row = partner_sample_link([
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        'distribution' => 'partner_delivered',
    ]);
    $link = new SponsorshipsModelStub(['getLinkByToken' => $row]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-expired', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(410)
        ->and($c->json['error'])->toBe(['link_token_expired'])
        ->and($c->json['data']['can_resend'])->toBeFalse()
        ->and($link->callsTo('claimLink'))->toBe([]);
});

/**
 * Prerequisites:
 * - Model reports link_already_claimed (another customer holds the link).
 *
 * Steps:
 * 1. POST claim.
 * 2. Assert HTTP 409 `link_already_claimed`.
 */
test('Partner - onboard claim already claimed by another user returns 409', function () {
    partner_set_method('POST');
    $row = partner_sample_link();
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'getPersonalRoleIdForCustomer' => 99,
        'claimLink' => ['ok' => false, 'error' => 'link_already_claimed'],
    ]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-taken', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['link_already_claimed'])
        ->and($link->callsTo('claimLink'))->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Model maps a revoked invite to claim error `link_revoked`.
 *
 * Steps:
 * 1. POST claim.
 * 2. Assert HTTP 409 `link_revoked`.
 */
test('Partner - onboard claim revoked link returns 409', function () {
    partner_set_method('POST');
    $row = partner_sample_link(['status' => ModelPartnerCustomerLink::STATUS_INVITED]);
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'getPersonalRoleIdForCustomer' => 99,
        'claimLink' => ['ok' => false, 'error' => 'link_revoked'],
    ]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-rev', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['link_revoked']);
});

/**
 * Prerequisites:
 * - Authenticated customer with personal role; claimLink succeeds.
 *
 * Steps:
 * 1. POST claim.
 * 2. Assert HTTP 200 onboarding status onboarding and next_step identity_verification.
 * 3. Prove claimLink received link_id, customer_id, personal role id.
 */
test('Partner - onboard claim success returns onboarding next step', function () {
    partner_set_method('POST');
    $invited = partner_sample_link();
    $fresh = partner_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_ONBOARDING,
        'customer_id' => 10,
    ]);
    $steps = partner_sample_steps(['claimed' => true]);
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $invited,
        'getPersonalRoleIdForCustomer' => 77,
        'claimLink' => ['ok' => true],
        'getLinkById' => $fresh,
        'stepStateForLink' => $steps,
    ]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-ok', 'action' => 'claim'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['onboarding']['status'])->toBe('onboarding')
        ->and($c->json['data']['onboarding']['steps'])->toBe($steps)
        ->and($c->json['data']['onboarding']['next_step'])->toBe('identity_verification')
        ->and($link->callsTo('claimLink')[0]['args'])->toBe([9, 10, 77]);
});

/**
 * Prerequisites:
 * - Customer id 0 on consent action.
 *
 * Steps:
 * 1. POST consent while unsigned.
 * 2. Assert HTTP 401 before consentLink.
 */
test('Partner - onboard consent unauthenticated returns 401 before consentLink', function () {
    partner_set_method('POST');
    $link = new SponsorshipsModelStub(['getLinkByToken' => partner_sample_link()]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(0, 0, 0),
        query: ['onboard_token' => 'tok-consent', 'action' => 'consent'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['unauthenticated'])
        ->and($link->callsTo('consentLink'))->toBe([])
        ->and($link->callsTo('getLinkByToken'))->toBe([]);
});

/**
 * Prerequisites:
 * - Authenticated user; model rejects consent because the link was never claimed by them.
 *
 * Steps:
 * 1. POST consent (consent-before-claim).
 * 2. Assert HTTP 409 `link_not_claimed_by_customer`.
 */
test('Partner - onboard consent before claim is rejected', function () {
    partner_set_method('POST');
    $row = partner_sample_link(['status' => ModelPartnerCustomerLink::STATUS_INVITED]);
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'consentLink' => ['ok' => false, 'error' => 'link_not_claimed_by_customer'],
    ]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-consent', 'action' => 'consent'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['link_not_claimed_by_customer'])
        ->and($link->callsTo('consentLink')[0]['args'])->toBe([9, 10]);
});

/**
 * Prerequisites:
 * - Claimed onboarding link; consent succeeds; customer has no signing certificate.
 *
 * Steps:
 * 1. POST consent.
 * 2. Assert HTTP 200 with activated_now false and next_step certificate.
 * 3. Prove activateConsentedLinksForCustomer was not needed (no cert path calls customerHasActiveSigningCertificate).
 */
test('Partner - onboard consent success without certificate leaves activated_now false', function () {
    partner_set_method('POST');
    $row = partner_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_ONBOARDING,
        'customer_id' => 10,
    ]);
    $fresh = partner_sample_link([
        'status' => ModelPartnerCustomerLink::STATUS_ONBOARDING,
        'customer_id' => 10,
        'consent_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $steps = partner_sample_steps([
        'claimed' => true,
        'identity_verified' => true,
        'certificate_issued' => false,
        'consent_given' => true,
    ]);
    $link = new SponsorshipsModelStub([
        'getLinkByToken' => $row,
        'consentLink' => ['ok' => true],
        'customerHasActiveSigningCertificate' => false,
        'getLinkById' => $fresh,
        'stepStateForLink' => $steps,
    ]);
    [$registry] = partner_registry(
        customer: new SponsorshipsCustomerStub(10, 0, 30),
        query: ['onboard_token' => 'tok-consent', 'action' => 'consent'],
        overrides: ['partner/customer_link' => $link],
    );
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['onboarding']['status'])->toBe('onboarding')
        ->and($c->json['data']['onboarding']['activated_now'])->toBeFalse()
        ->and($c->json['data']['onboarding']['next_step'])->toBe('certificate')
        ->and($c->json['data']['onboarding']['steps']['consent_given'])->toBeTrue()
        ->and($link->callsTo('activateConsentedLinksForCustomer'))->toBe([])
        ->and($link->callsTo('customerHasActiveSigningCertificate'))->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Unsupported verb/action combination after models load.
 *
 * Steps:
 * 1. DELETE with a token.
 * 2. Assert HTTP 405 `method_not_allowed`.
 */
test('Partner - onboard unsupported method returns 405', function () {
    partner_set_method('DELETE');
    [$registry, $load] = partner_registry(query: ['onboard_token' => 'tok-x']);
    $c = partner_controller(
        TestableControllerPublicAPIV1PartnerOnboard::class,
        $registry,
        partner_all_permissions(),
    );
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed'])
        ->and($c->allowedHeaders)->toBe(['GET', 'POST', 'OPTIONS'])
        ->and($load->loadedModels)->toContain('partner/program')
        ->and($load->loadedModels)->toContain('partner/customer_link');
});
