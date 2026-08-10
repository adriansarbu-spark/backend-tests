<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollmentsCredentials::index().
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
});

function csc_credentials_controller(?CscCredentialIssuanceFake $issuer = null): TestableControllerPublicAPIV1CscEnrollmentsCredentials
{
    [$registry] = csc_integrator_registry();
    $c = new TestableControllerPublicAPIV1CscEnrollmentsCredentials($registry);
    $c->authFake = csc_install_integrator_auth();
    $c->issuerFake = $issuer ?? csc_install_credential_issuance();
    $c->request->get = ['enrollment_uuid' => 'e1'];

    return $c;
}

/**
 * Prerequisites:
 * - Valid auth/gate.
 *
 * Steps:
 * 1. Call index() as GET.
 * 2. Assert 405 method_not_allowed.
 */
test('CSC enrollment credentials — non-POST method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $c = csc_credentials_controller();
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Auth fake configured to reject the bearer token.
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 401 integrator_unauthenticated.
 */
test('CSC enrollment credentials — auth failure returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $c = csc_credentials_controller();
    $c->authFake->valid = false;
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['integrator_unauthenticated']);
});

/**
 * Prerequisites:
 * - Valid auth/gate; no enrollment_uuid supplied.
 *
 * Steps:
 * 1. POST index() with an empty enrollment_uuid.
 * 2. Assert 400 enrollment_uuid_required.
 */
test('CSC enrollment credentials — missing enrollment_uuid returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $c = csc_credentials_controller();
    $c->request->get = [];
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['enrollment_uuid_required']);
});

/**
 * Prerequisites:
 * - Valid auth/gate/uuid; malformed email in the POST body.
 *
 * Steps:
 * 1. POST index() with an invalid email.
 * 2. Assert 400 invalid_email (issuer never invoked).
 */
test('CSC enrollment credentials — invalid email returns 400', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $issuer = new CscCredentialIssuanceFake();
    $c = csc_credentials_controller($issuer);
    $c->setPostPayload(['email' => 'not-an-email']);
    $c->index();

    expect($c->statusCode)->toBe(400)
        ->and($c->json['error'])->toBe(['invalid_email'])
        ->and($issuer->calls)->toBe([]);
});

/**
 * Prerequisites:
 * - Issuer returns ok=false for each documented error code (plus an unmapped
 *   code and an empty error string).
 *
 * Steps:
 * 1. POST index().
 * 2. Assert the status code mapped for that error (default 400 when unmapped),
 *    and the reported error code (or the empty-error fallback).
 */
test('CSC enrollment credentials — issuance errors map to expected status', function (string $error, int $status) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $issuer = new CscCredentialIssuanceFake();
    $issuer->result = ['ok' => false, 'error' => $error];
    $c = csc_credentials_controller($issuer);
    $c->index();

    expect($c->statusCode)->toBe($status)
        ->and($c->json['error'])->toBe([$error !== '' ? $error : 'credential_issue_failed']);
})->with([
    'enrollment_not_found'             => ['enrollment_not_found', 404],
    'enrollment_not_verified'          => ['enrollment_not_verified', 422],
    'email_required'                   => ['email_required', 422],
    'identity_incomplete'               => ['identity_incomplete', 422],
    'credential_revoked'                => ['credential_revoked', 409],
    'credential_superseded'             => ['credential_superseded', 409],
    'credential_not_issuable'           => ['credential_not_issuable', 409],
    'ejbca_failed'                       => ['ejbca_failed', 502],
    'persistence_failed'                => ['persistence_failed', 500],
    'unmapped error code falls back to 400' => ['sam_keypair_invalid', 400],
    'empty error falls back to credential_issue_failed' => ['', 400],
]);

/**
 * Prerequisites:
 * - Issuer succeeds and returns only public credential data (no private key
 *   material).
 *
 * Steps:
 * 1. POST index().
 * 2. Assert 200, enrollment_uuid echoed back, public fields present, and no
 *    private-key-shaped keys leak into the response.
 */
test('CSC enrollment credentials — success returns public credential without private material', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $issuer = new CscCredentialIssuanceFake();
    $issuer->result = [
        'ok'     => true,
        'public' => [
            'credential_id' => 'cred-1',
            'status'        => 'valid',
        ],
    ];
    $c = csc_credentials_controller($issuer);
    $c->index();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['enrollment_uuid'])->toBe('e1')
        ->and($c->json['data']['credential_id'])->toBe('cred-1')
        ->and($c->json['data']['status'])->toBe('valid')
        ->and(array_key_exists('private_key', $c->json['data']))->toBeFalse()
        ->and(array_key_exists('private_key_pem', $c->json['data']))->toBeFalse()
        ->and(array_key_exists('privateKey', $c->json['data']))->toBeFalse()
        ->and($issuer->calls)->toHaveCount(1)
        ->and($issuer->calls[0]['enrollment_uuid'])->toBe('e1');
});
