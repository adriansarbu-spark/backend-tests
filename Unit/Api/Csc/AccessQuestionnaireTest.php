<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests_config.php';
require_once __DIR__ . '/_support/CscApiTestDoubles.php';

/**
 * Unit tests for ControllerPublicAPIV1CscAccess::questionnaire().
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
 * Prerequisites:
 * - Admin context; no saved questionnaire or evidence; no DPA documents.
 *
 * Steps:
 * 1. GET questionnaire().
 * 2. Assert schema version, empty saved_answers as object, null timestamps, empty evidence/legal lists.
 */
test('CSC access questionnaire — empty state returns schema and empty object answers', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $legal = new CscLegalDocumentModelStub();
    [$registry] = csc_access_registry(
        new CscApiCustomerStub(1, 10, 5),
        null,
        null,
        null,
        $legal,
    );
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->questionnaire();

    $data = $c->json['data'];
    expect($c->statusCode)->toBe(200)
        ->and($data['schema']['version'])->toBe(CscSecurityQuestionnaire::SCHEMA_VERSION)
        ->and($data['saved_answers'])->toBeInstanceOf(stdClass::class)
        ->and($data['saved_answers_version'])->toBeNull()
        ->and($data['submitted_at'])->toBeNull()
        ->and($data['evidence'])->toBe([])
        ->and($data['legal_documents'])->toBe([]);
});

/**
 * Prerequisites:
 * - Saved answers against an older schema version; one evidence row; one DPA document.
 *
 * Steps:
 * 1. GET questionnaire().
 * 2. Assert answers, version mismatch vs current schema, Zulu submitted_at, public evidence fields only.
 */
test('CSC access questionnaire — returns saved answers evidence and dpa without stored paths', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $access = new CscCompanyAccessModelStub();
    $access->latestQuestionnaire = [
        'csc_security_questionnaire_id' => 3,
        'schema_version'                => '2025-01-01',
        'answers_decoded'               => csc_minimal_valid_answers(),
        'created_at'                    => '2026-02-01 11:22:33',
    ];
    $access->evidence = [
        [
            'csc_access_evidence_id' => 7,
            'original_filename'      => 'soc2.pdf',
            'size_bytes'             => 2048,
            'created_at'             => '2026-02-01 12:00:00',
            'stored_path'            => 'csc_access_evidence/10/secret.pdf',
            'sha256'                 => 'abc',
        ],
    ];
    $legal = new CscLegalDocumentModelStub();
    $legal->activeForFlowRows = csc_dpa_legal_documents();

    [$registry] = csc_access_registry(
        new CscApiCustomerStub(1, 10, 5),
        $access,
        null,
        null,
        $legal,
    );
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->questionnaire();

    $data = $c->json['data'];
    expect($data['schema']['version'])->toBe(CscSecurityQuestionnaire::SCHEMA_VERSION)
        ->and($data['saved_answers_version'])->toBe('2025-01-01')
        ->and($data['saved_answers']['has_iso27001'])->toBeTrue()
        ->and($data['submitted_at'])->toEndWith('Z')
        ->and($data['evidence'])->toHaveCount(1)
        ->and($data['evidence'][0])->toHaveKeys(['evidence_id', 'original_filename', 'size_bytes', 'uploaded_at'])
        ->and($data['evidence'][0])->not->toHaveKey('stored_path')
        ->and($data['legal_documents'])->not->toBeEmpty()
        ->and(json_encode($data))->not->toContain('stored_path')
        ->and(json_encode($data))->not->toContain('sha256');
});

/**
 * Prerequisites:
 * - Admin; wrong HTTP method.
 *
 * Steps:
 * 1. POST questionnaire().
 * 2. Assert 405.
 */
test('CSC access questionnaire — unsupported method returns 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    [$registry] = csc_access_registry(new CscApiCustomerStub(1, 10, 5));
    $c = new TestableControllerPublicAPIV1CscAccess($registry);
    $c->permission = csc_access_permissions();
    $c->questionnaire();

    expect($c->statusCode)->toBe(405)
        ->and($c->json['error'])->toBe(['method_not_allowed']);
});
