<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollmentPhotos::index() guard paths.
 *
 * The successful streaming path calls readfile()+exit and terminates the PHP
 * process; it is intentionally NOT exercised here (see EnrollmentPhotosStreamingTest.php
 * for pre-exit failure paths only).
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

/**
 * @return array{0: TestableControllerPublicAPIV1CscEnrollmentPhotos, 1: Registry, 2: CscCompanyAccessModelStub}
 */
function csc_photos_controller(?CscEnrollmentSessionModelStub $enrollment = null, ?CscCheckinModelStub $checkin = null, ?CscUploadSigningStub $upload = null): array
{
    [$registry, , $access] = csc_integrator_registry($enrollment, null, null, null, $checkin, $upload);
    $c = new TestableControllerPublicAPIV1CscEnrollmentPhotos($registry);
    $c->authFake = csc_install_integrator_auth();

    return [$c, $registry, $access];
}

/**
 * Prerequisites:
 * - Valid auth/gate.
 *
 * Steps:
 * 1. Call index() as POST.
 * 2. Assert 405 method_not_allowed.
 */
test('CSC enrollment photos — non-GET method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$c] = csc_photos_controller();
    $c->index();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});

/**
 * Prerequisites:
 * - Auth fake configured to reject the bearer token.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 401 integrator_unauthenticated.
 */
test('CSC enrollment photos — auth failure returns 401', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c] = csc_photos_controller();
    $c->authFake->valid = false;
    $c->index();

    expect($c->statusCode)->toBe(401)
        ->and($c->json['error'])->toBe(['integrator_unauthenticated']);
});

/**
 * Prerequisites:
 * - Auth passes but the resolved client row has no usable client id.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 500 internal_error.
 */
test('CSC enrollment photos — zero client id returns 500', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c] = csc_photos_controller();
    $c->authFake->clientRow['csc_api_client_id'] = 0;
    $c->index();

    expect($c->statusCode)->toBe(500)
        ->and($c->json['error'])->toBe(['internal_error']);
});

/**
 * Prerequisites:
 * - Company access master switch disabled, so CscApiMetering::gate() fails.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 403 csc_api_disabled (gate error mapped through).
 */
test('CSC enrollment photos — metering gate failure returns mapped status', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c, , $access] = csc_photos_controller();
    $access->masterEnabled = false;
    $c->index();

    expect($c->statusCode)->toBe(403)
        ->and($c->json['error'])->toBe(['csc_api_disabled']);
});

/**
 * Prerequisites:
 * - Valid auth/gate; malformed identifiers.
 *
 * Steps:
 * 1. GET index() with an empty enrollment_uuid or an unsupported photo_kind.
 * 2. Assert 404 not_found before any model lookup.
 */
test('CSC enrollment photos — empty uuid or unsupported kind returns 404 not_found', function (string $uuid, string $kind) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    [$c] = csc_photos_controller();
    $c->request->get = ['enrollment_uuid' => $uuid, 'photo_kind' => $kind];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
})->with([
    'empty uuid'             => ['', 'front'],
    'empty kind'             => ['e1', ''],
    'unsupported kind value' => ['e1', 'side'],
]);

/**
 * Prerequisites:
 * - Enrollment session model has no matching row for this client/uuid.
 *
 * Steps:
 * 1. GET index() with a valid uuid/kind pair.
 * 2. Assert 404 enrollment_not_found.
 */
test('CSC enrollment photos — missing enrollment returns 404 enrollment_not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = null;
    [$c] = csc_photos_controller($enrollment);
    $c->request->get = ['enrollment_uuid' => 'e1', 'photo_kind' => 'front'];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['enrollment_not_found']);
});

/**
 * Prerequisites:
 * - Enrollment exists but is not in the verified state.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 404 not_found (does not disclose enrollment existence).
 */
test('CSC enrollment photos — non-verified session returns 404 not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid' => 'e1',
        'state'           => ModelCscEnrollmentSession::STATE_CREATED,
    ];
    [$c] = csc_photos_controller($enrollment);
    $c->request->get = ['enrollment_uuid' => 'e1', 'photo_kind' => 'front'];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
});

/**
 * Prerequisites:
 * - Enrollment is verified but has no verification_id recorded.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 404 not_found.
 */
test('CSC enrollment photos — verified without verification id returns 404 not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid' => 'e1',
        'state'           => ModelCscEnrollmentSession::STATE_VERIFIED,
        'verification_id' => '',
    ];
    [$c] = csc_photos_controller($enrollment);
    $c->request->get = ['enrollment_uuid' => 'e1', 'photo_kind' => 'front'];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
});

/**
 * Prerequisites:
 * - Enrollment verified with a verification_id; Checkin verification row found,
 *   but the requested document-code column decodes to no usable upload code.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 404 not_found.
 */
test('CSC enrollment photos — verified verification with empty document codes returns 404 not_found', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid' => 'e1',
        'state'           => ModelCscEnrollmentSession::STATE_VERIFIED,
        'verification_id' => 'ver-1',
    ];
    $checkin = new CscCheckinModelStub();
    $checkin->verification = [
        'document_code'      => '[]',
        'document_back_code' => '[]',
    ];
    [$c] = csc_photos_controller($enrollment, $checkin);
    $c->request->get = ['enrollment_uuid' => 'e1', 'photo_kind' => 'front'];
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
});
