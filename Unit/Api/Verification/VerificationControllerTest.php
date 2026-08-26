<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests_config.php';

if (! class_exists(ModelSigningSigner::class, false)) {
    class ModelSigningSigner
    {
        public const KIND_SIMPLE = 'simple';

        public static function isSimpleSignatureKind(array $row): bool
        {
            return trim((string) ($row['signature_kind'] ?? '')) === self::KIND_SIMPLE;
        }
    }
}

if (! class_exists(ModelCscSigningSession::class, false)) {
    class ModelCscSigningSession
    {
        public const STATUS_COMPLETED = 'completed';
    }
}

require_once PUBLIC_API . 'verify.php';

final class VerificationLoadStub
{
    /** @param array<string, object> $models */
    public function __construct(private readonly Registry $registry, private readonly array $models)
    {
    }

    public function model(string $route): void
    {
        if (! isset($this->models[$route])) {
            throw new RuntimeException('Unexpected verification model: ' . $route);
        }

        $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
    }
}

final class VerificationDocumentStub
{
    /** @var array<string, mixed>|null */
    public ?array $document = null;

    public function getDocumentByAuditCertificateUuid(string $uuid): ?array
    {
        return $this->document;
    }
}

final class VerificationSignerStub
{
    /** @var list<array<string, mixed>> */
    public array $signers = [];

    /** @return list<array<string, mixed>> */
    public function getSignersByDocumentId(int $documentId): array
    {
        return $this->signers;
    }
}

final class VerificationCscSessionStub
{
    /** @var array<string, mixed>|null */
    public ?array $session = null;

    /** @var list<array<string, mixed>> */
    public array $documents = [];

    public function getByAuditCertificateUuid(string $uuid): ?array
    {
        return $this->session;
    }

    /** @return list<array<string, mixed>> */
    public function getDocumentsBySessionUuid(string $uuid): array
    {
        return $this->documents;
    }
}

final class TestableVerificationController extends ControllerPublicAPIV1Verify
{
    public bool $rateLimited = false;

    /** @var array<string, string|null> */
    public array $uploadHashes = [];

    /** @var array<string, string|null> */
    public array $cscHashes = [];

    public function checkPlugin(): void
    {
    }

    public function sendResponse()
    {
        return null;
    }

    protected function isVerificationRateLimited($certificate_uuid)
    {
        return $this->rateLimited;
    }

    protected function uploadSha256($file_code)
    {
        return $this->uploadHashes[(string) $file_code] ?? null;
    }

    protected function cscSignedFileSha256($rel_path)
    {
        return $this->cscHashes[(string) $rel_path] ?? null;
    }

    public function digestToHex(string $digest): ?string
    {
        return $this->digestBase64ToHex($digest);
    }

    public function missingCscFileHash(string $path): ?string
    {
        return parent::cscSignedFileSha256($path);
    }
}

/** @return array{TestableVerificationController, VerificationDocumentStub, VerificationSignerStub, VerificationCscSessionStub} */
function verification_controller(): array
{
    $registry = new Registry();
    $document = new VerificationDocumentStub();
    $signer = new VerificationSignerStub();
    $csc = new VerificationCscSessionStub();
    $registry->set('request', (object) ['get' => []]);
    $registry->set('load', new VerificationLoadStub($registry, [
        'signing/document' => $document,
        'signing/signer' => $signer,
        'csc/signing_session' => $csc,
    ]));

    $controller = new TestableVerificationController($registry);
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];

    return [$controller, $document, $signer, $csc];
}

beforeEach(function () {
    $this->savedVerificationMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'GET';
});

afterEach(function () {
    if ($this->savedVerificationMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedVerificationMethod;
    }
});

/**
 * Prerequisites:
 * - Verification is anonymous and unknown identifiers must be indistinguishable.
 *
 * Steps:
 * 1. Submit malformed and unknown certificate UUIDs.
 * 2. Trigger the rate limiter for a syntactically valid UUID.
 * 3. Assert negative responses contain no internal file or database details.
 */
test('Verification API — unknown and rate-limited lookups disclose no file details', function () {
    [$malformed] = verification_controller();
    $malformed->request->get = ['certificate_uuid' => '../storage/private.pdf'];
    $malformed->index();

    [$unknown] = verification_controller();
    $unknown->request->get = ['certificate_uuid' => '11111111-1111-4111-8111-111111111111'];
    $unknown->index();

    [$limited] = verification_controller();
    $limited->rateLimited = true;
    $limited->request->get = ['certificate_uuid' => '22222222-2222-4222-8222-222222222222'];
    $limited->index();

    expect($malformed->statusCode)->toBe(404)
        ->and($malformed->json['data'])->toBe(['valid' => false])
        ->and($unknown->statusCode)->toBe(404)
        ->and($unknown->json['data'])->toBe(['valid' => false])
        ->and($limited->statusCode)->toBe(429)
        ->and($limited->json['error'])->toBe(['rate_limited'])
        ->and(json_encode([$malformed->json, $unknown->json, $limited->json]))
        ->not->toContain('storage')
        ->not->toContain('document_id')
        ->not->toContain('private.pdf');
});

/**
 * Prerequisites:
 * - A completed platform document has a generated audit certificate.
 * - File hashing is replaced with deterministic digest doubles.
 *
 * Steps:
 * 1. Resolve the certificate UUID.
 * 2. Count qualified/simple signers while ignoring notification-only rows.
 * 3. Assert normalized timestamps and non-PII digest output.
 */
test('Verification API — completed document returns deterministic non-PII evidence', function () {
    [$controller, $document, $signer] = verification_controller();
    $uuid = '33333333-3333-4333-8333-333333333333';
    $controller->request->get = ['certificate_uuid' => strtoupper($uuid)];
    $controller->uploadHashes = ['final-code' => str_repeat('a', 64), 'original-code' => str_repeat('b', 64)];
    $document->document = [
        'document_id' => 12,
        'uuid' => 'safe-document-uuid',
        'lifecycle_status_code' => 'COMPLETED',
        'audit_certificate_file_code' => 'audit-code',
        'audit_certificate_generated_at' => '2026-08-12 10:11:12',
        'completed_at' => '2026-08-12 10:10:10',
        'final_file_code' => 'final-code',
        'current_file_code' => 'current-code',
        'original_file_code' => 'original-code',
    ];
    $signer->signers = [
        ['signing_type' => 'SIGNATURE', 'signature_kind' => 'qualified', 'email' => 'hidden@example.com'],
        ['signing_type' => 'SEAL', 'signature_kind' => 'simple', 'email' => 'also-hidden@example.com'],
        ['signing_type' => 'NONE', 'signature_kind' => 'qualified'],
    ];

    $controller->index();

    expect($controller->statusCode)->not->toBe(404)
        ->and($controller->json['data']['valid'])->toBeTrue()
        ->and($controller->json['data']['kind'])->toBe('document')
        ->and($controller->json['data']['signer_count'])->toBe(2)
        ->and($controller->json['data']['signature_kinds'])->toBe(['qualified' => 1, 'simple' => 1])
        ->and($controller->json['data']['certificate_generated_at'])->toBe('2026-08-12T10:11:12Z')
        ->and($controller->json['data']['executed_file_sha256'])->toBe(str_repeat('a', 64))
        ->and(json_encode($controller->json))->not->toContain('hidden@example.com');
});

/**
 * Prerequisites:
 * - A completed CSC session certificate contains reviewed and signed-document evidence.
 *
 * Steps:
 * 1. Fall through the platform-document lookup to the CSC session lookup.
 * 2. Convert a valid reviewed digest and reject malformed digest/path values.
 * 3. Assert missing files fail closed without leaking their relative paths.
 */
test('Verification API — CSC digests verify deterministically and unsafe files fail closed', function () {
    [$controller, , , $csc] = verification_controller();
    $uuid = '44444444-4444-4444-8444-444444444444';
    $digest = random_bytes(32);
    $controller->request->get = ['certificate_uuid' => $uuid];
    $controller->cscHashes = ['signed/one.pdf' => str_repeat('c', 64)];
    $csc->session = [
        'status' => ModelCscSigningSession::STATUS_COMPLETED,
        'audit_certificate_file' => 'certificate.pdf',
        'session_uuid' => 'session-internal-uuid',
        'audit_certificate_generated_at' => '2026-08-12 11:00:00',
        'completed_at' => '2026-08-12 10:59:00',
    ];
    $csc->documents = [
        ['digest_base64' => base64_encode($digest), 'result_signed_pdf_path' => 'signed/one.pdf'],
        ['digest_base64' => 'not-base64!', 'result_signed_pdf_path' => '../outside.pdf'],
    ];

    $controller->index();

    expect($controller->json['data']['kind'])->toBe('csc_session')
        ->and($controller->json['data']['document_count'])->toBe(2)
        ->and($controller->json['data']['documents'][0])->toBe([
            'reviewed_content_sha256' => bin2hex($digest),
            'signed_file_sha256' => str_repeat('c', 64),
        ])
        ->and($controller->json['data']['documents'][1])->toBe([
            'reviewed_content_sha256' => null,
            'signed_file_sha256' => null,
        ])
        ->and($controller->digestToHex(base64_encode('short')))->toBeNull()
        ->and($controller->missingCscFileHash('../outside.pdf'))->toBeNull()
        ->and($controller->missingCscFileHash('definitely/missing.pdf'))->toBeNull()
        ->and(json_encode($controller->json))->not->toContain('../outside.pdf')
        ->not->toContain('session-internal-uuid');
});
