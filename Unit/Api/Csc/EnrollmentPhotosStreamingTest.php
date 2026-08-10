<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscIntegratorTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscEnrollmentPhotos::index() failure paths that
 * occur AFTER the document code is resolved but BEFORE the readfile()+exit streaming
 * call. The successful streaming branch is never exercised here (it would terminate
 * the PHP process).
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
 * Builds a controller for a verified enrollment with a resolvable document code,
 * so tests can focus solely on the upload/path resolution guards.
 */
function csc_photos_verified_controller(CscCheckinModelStub $checkin, CscUploadSigningStub $upload): TestableControllerPublicAPIV1CscEnrollmentPhotos
{
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byUuidForClient = [
        'enrollment_uuid' => 'e1',
        'state'           => ModelCscEnrollmentSession::STATE_VERIFIED,
        'verification_id' => 'ver-1',
    ];
    [$registry] = csc_integrator_registry($enrollment, null, null, null, $checkin, $upload);
    $c = new TestableControllerPublicAPIV1CscEnrollmentPhotos($registry);
    $c->authFake = csc_install_integrator_auth();
    $c->request->get = ['enrollment_uuid' => 'e1', 'photo_kind' => 'front'];

    return $c;
}

/**
 * Prerequisites:
 * - Verified enrollment with a resolvable document code, but tool/upload has no
 *   matching row for that code.
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 404 not_found (never reaches file resolution).
 */
test('CSC enrollment photos streaming — missing upload row returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $checkin = new CscCheckinModelStub();
    $checkin->verification = ['document_code' => '["code1"]'];
    $upload = new CscUploadSigningStub();
    $upload->upload = null;

    $c = csc_photos_verified_controller($checkin, $upload);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
});

/**
 * Prerequisites:
 * - Verified enrollment with a resolvable document code and an upload row whose
 *   relative_path/filename does not resolve to a real file under DIR_UPLOAD
 *   (covers both a non-existent file and a path-traversal attempt: in both cases
 *   is_file()/realpath() fail before the containment check, or the containment
 *   check itself rejects a resolved path outside the upload root).
 *
 * Steps:
 * 1. GET index().
 * 2. Assert 404 not_found — the guard trips before readfile()+exit.
 */
test('CSC enrollment photos streaming — unresolved or escaping file path returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $checkin = new CscCheckinModelStub();
    $checkin->verification = ['document_code' => '["code1"]'];
    $upload = new CscUploadSigningStub();
    $upload->upload = [
        'relative_path' => '../../../does-not-exist-container-escape',
        'filename'      => 'nope-' . uniqid('', true) . '.jpg',
    ];

    $c = csc_photos_verified_controller($checkin, $upload);
    $c->index();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['not_found']);
});
