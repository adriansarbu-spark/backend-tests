<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscAccess::evidence() upload/delete.
 */

beforeEach(function () {
    $this->hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $this->savedRequestMethod = $this->hadRequestMethod ? $_SERVER['REQUEST_METHOD'] : null;
    $this->tmpDir = sys_get_temp_dir() . '/csc_evidence_unit_' . uniqid('', true);
    mkdir($this->tmpDir, 0700, true);
});

afterEach(function () {
    if (! $this->hadRequestMethod) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
    }
    unset($_GET['evidence_id']);

    if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
    }
});

function csc_make_tmp_file(string $dir, string $contents = '%PDF-1.4 unit'): string
{
    $path = $dir . '/upload_' . uniqid('', true) . '.bin';
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Prerequisites:
 * - 25 evidence files already stored.
 *
 * Steps:
 * 1. POST evidence with a valid PDF tmp file.
 * 2. Assert 422 too_many_files.
 */
test('CSC access evidence — rejects upload when 25-file cap reached', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    for ($i = 0; $i < 25; $i++) {
        $access->evidence[] = [
            'csc_access_evidence_id' => $i + 1,
            'original_filename'      => "f{$i}.pdf",
            'size_bytes'             => 10,
            'created_at'             => '2026-01-01 00:00:00',
            'stored_path'            => "csc_access_evidence/10/f{$i}.pdf",
        ];
    }
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $tmp = csc_make_tmp_file($this->tmpDir);
    $c->setFilesPayload([
        'file' => [
            'name'     => 'extra.pdf',
            'tmp_name' => $tmp,
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['too_many_files']);
});

/**
 * Prerequisites:
 * - Admin; no file in multipart.
 *
 * Steps:
 * 1. POST evidence without file.
 * 2. Assert 422 file_required.
 */
test('CSC access evidence — missing file returns 422 file_required', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setFilesPayload([]);
    $c->evidence();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['file_required']);
});

/**
 * Prerequisites:
 * - Upload error flag set.
 *
 * Steps:
 * 1. POST with UPLOAD_ERR_PARTIAL.
 * 2. Assert 422 upload_failed.
 */
test('CSC access evidence — upload error returns 422 upload_failed', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->setFilesPayload([
        'file' => [
            'name'     => 'x.pdf',
            'tmp_name' => '/tmp/missing',
            'size'     => 10,
            'error'    => UPLOAD_ERR_PARTIAL,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['upload_failed']);
});

/**
 * Prerequisites:
 * - Zero-byte file.
 *
 * Steps:
 * 1. POST empty file.
 * 2. Assert 422 file_too_large (controller treats size <= 0 the same).
 */
test('CSC access evidence — zero-byte file returns 422 file_too_large', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $tmp = csc_make_tmp_file($this->tmpDir, '');
    $c->setFilesPayload([
        'file' => [
            'name'     => 'empty.pdf',
            'tmp_name' => $tmp,
            'size'     => 0,
            'error'    => UPLOAD_ERR_OK,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['file_too_large']);
});

/**
 * Prerequisites:
 * - Unsupported extension.
 *
 * Steps:
 * 1. POST .exe file.
 * 2. Assert 422 unsupported_file_type.
 */
test('CSC access evidence — unsupported extension returns 422', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $tmp = csc_make_tmp_file($this->tmpDir, 'MZ');
    $c->setFilesPayload([
        'file' => [
            'name'     => 'malware.exe',
            'tmp_name' => $tmp,
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(422)
        ->and($c->json['error'])->toBe(['unsupported_file_type']);
});

/**
 * Prerequisites:
 * - Writable DIR_STORAGE; valid PDF; uppercase extension.
 *
 * Steps:
 * 1. Skip when storage is not writable in this environment.
 * 2. POST evidence.
 * 3. Assert 201; evidence_id; public list hides stored_path; file exists under storage.
 */
test('CSC access evidence — successful upload persists metadata and hides path', function () {
    $probeDir = DIR_STORAGE . 'csc_access_evidence/_unit_probe';
    if ((! is_dir(DIR_STORAGE) && ! @mkdir(DIR_STORAGE, 0770, true)) || (! is_dir($probeDir) && ! @mkdir($probeDir, 0770, true))) {
        test()->markTestSkipped('DIR_STORAGE is not writable in this environment');
    }
    @rmdir($probeDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $tmp = csc_make_tmp_file($this->tmpDir, '%PDF-1.4 unit-test');
    $c->setFilesPayload([
        'file' => [
            'name'     => 'ISO_CERT.PDF',
            'tmp_name' => $tmp,
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(201)
        ->and($c->json['data']['evidence_id'])->toBeInt()
        ->and($c->json['data']['original_filename'])->toBe('ISO_CERT.PDF')
        ->and($c->json['data']['evidence'][0])->not->toHaveKey('stored_path')
        ->and(json_encode($c->json))->not->toContain('stored_path');

    $stored = (string) ($access->evidence[0]['stored_path'] ?? '');
    expect($stored)->toStartWith('csc_access_evidence/10/')
        ->and(is_file(DIR_STORAGE . $stored))->toBeTrue();

    @unlink(DIR_STORAGE . $stored);
    @rmdir(DIR_STORAGE . 'csc_access_evidence/10');
    @rmdir(DIR_STORAGE . 'csc_access_evidence');
});

/**
 * Prerequisites:
 * - MIME mismatch currently accepted by production (regression characterization).
 *
 * Steps:
 * 1. Skip when storage is not writable.
 * 2. POST .pdf extension with PNG bytes.
 * 3. Assert current behavior: 201 (MIME mismatch not rejected).
 */
test('CSC access evidence — MIME mismatch is currently accepted (security characterization)', function () {
    $probeDir = DIR_STORAGE . 'csc_access_evidence/_unit_probe';
    if ((! is_dir(DIR_STORAGE) && ! @mkdir(DIR_STORAGE, 0770, true)) || (! is_dir($probeDir) && ! @mkdir($probeDir, 0770, true))) {
        test()->markTestSkipped('DIR_STORAGE is not writable in this environment');
    }
    @rmdir($probeDir);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $access = new CscCompanyAccessModelStub();
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    // Minimal PNG header
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $tmp = csc_make_tmp_file($this->tmpDir, (string) $png);
    $c->setFilesPayload([
        'file' => [
            'name'     => 'fake.pdf',
            'tmp_name' => $tmp,
            'size'     => filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ],
    ]);
    $c->evidence();

    expect($c->statusCode)->toBe(201);

    $stored = (string) ($access->evidence[0]['stored_path'] ?? '');
    if ($stored !== '' && is_file(DIR_STORAGE . $stored)) {
        @unlink(DIR_STORAGE . $stored);
    }
    @rmdir(DIR_STORAGE . 'csc_access_evidence/10');
    @rmdir(DIR_STORAGE . 'csc_access_evidence');
});

/**
 * Prerequisites:
 * - Approved (locked) state; evidence exists.
 *
 * Steps:
 * 1. DELETE evidence.
 * 2. Assert 409 evidence_locked; row retained.
 */
test('CSC access evidence — delete blocked when access approved', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = ['access_state' => ModelCscCompanyAccess::STATE_APPROVED];
    $access->evidence = [
        [
            'csc_access_evidence_id' => 5,
            'original_filename'      => 'a.pdf',
            'size_bytes'             => 10,
            'created_at'             => '2026-01-01 00:00:00',
            'stored_path'            => 'csc_access_evidence/10/a.pdf',
        ],
    ];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $_GET['evidence_id'] = 5;
    $c->evidence();

    expect($c->statusCode)->toBe(409)
        ->and($c->json['error'])->toBe(['evidence_locked'])
        ->and($access->evidence)->toHaveCount(1);
});

/**
 * Prerequisites:
 * - Open state; missing evidence id for company.
 *
 * Steps:
 * 1. DELETE unknown id.
 * 2. Assert 404 evidence_not_found.
 */
test('CSC access evidence — delete missing id returns 404', function () {
    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = ['access_state' => ModelCscCompanyAccess::STATE_REQUESTED];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $_GET['evidence_id'] = 999;
    $c->evidence();

    expect($c->statusCode)->toBe(404)
        ->and($c->json['error'])->toBe(['evidence_not_found']);
});

/**
 * Prerequisites:
 * - Requested state; owned evidence row with real file under DIR_STORAGE.
 *
 * Steps:
 * 1. Skip when storage is not writable.
 * 2. DELETE evidence_id.
 * 3. Assert deleted true; file removed; model row gone.
 */
test('CSC access evidence — delete removes owned file in open state', function () {
    $probeDir = DIR_STORAGE . 'csc_access_evidence/_unit_probe';
    if ((! is_dir(DIR_STORAGE) && ! @mkdir(DIR_STORAGE, 0770, true)) || (! is_dir($probeDir) && ! @mkdir($probeDir, 0770, true))) {
        test()->markTestSkipped('DIR_STORAGE is not writable in this environment');
    }
    @rmdir($probeDir);

    $_SERVER['REQUEST_METHOD'] = 'DELETE';
    $rel = 'csc_access_evidence/10/unit_delete_' . uniqid('', true) . '.pdf';
    $abs = DIR_STORAGE . $rel;
    @mkdir(dirname($abs), 0770, true);
    file_put_contents($abs, '%PDF-1.4');

    $access = new CscCompanyAccessModelStub();
    $access->companyAccess = ['access_state' => ModelCscCompanyAccess::STATE_REQUESTED];
    $access->evidence = [
        [
            'csc_access_evidence_id' => 5,
            'original_filename'      => 'a.pdf',
            'size_bytes'             => 10,
            'created_at'             => '2026-01-01 00:00:00',
            'stored_path'            => $rel,
        ],
    ];
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5), $access);
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $_GET['evidence_id'] = 5;
    $c->evidence();

    expect($c->statusCode)->toBe(200)
        ->and($c->json['data']['deleted'])->toBeTrue()
        ->and($access->evidence)->toBe([])
        ->and(is_file($abs))->toBeFalse();

    @rmdir(DIR_STORAGE . 'csc_access_evidence/10');
    @rmdir(DIR_STORAGE . 'csc_access_evidence');
});
