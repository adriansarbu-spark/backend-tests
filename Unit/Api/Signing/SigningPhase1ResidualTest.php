<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests_config.php';
require_once DIR_SYSTEM . 'library/config.php';
require_once PUBLIC_API . 'signing.php';
require_once __DIR__ . '/_support/SigningTestDoubles.php';

if (! class_exists(ModelSigningSignerClaim::class, false)) {
    class ModelSigningSignerClaim
    {
        public const STATUS_SUCCEEDED = 'succeeded';
        public const STATUS_FAILED = 'failed';
        public const PROOF_CNP_MATCH = 'cnp_match';
        public const PROOF_EMAIL_OTP = 'email_otp';

        public static function normalizeIdentityString($value): string
        {
            return preg_replace('/\s+/', '', trim((string) $value));
        }
    }
}

final class Phase1SigningCustomerStub
{
    public function __construct(
        private readonly int $id = 51,
        private readonly int $roleId = 9,
        private readonly string $cnp = '',
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function getCnp(): string
    {
        return $this->cnp;
    }

    public function getCompanyId(): int
    {
        return 7;
    }

    public function getTotpSecret(): ?string
    {
        return null;
    }

    public function getFirstName(): string
    {
        return 'Principal';
    }

    public function getLastName(): string
    {
        return 'Signer';
    }
}

final class Phase1SigningLoadStub
{
    /** @param array<string, object> $models */
    public function __construct(private readonly Registry $registry, private readonly array $models)
    {
    }

    public function model(string $route): void
    {
        if (! isset($this->models[$route])) {
            throw new RuntimeException('Unexpected signing model: ' . $route);
        }

        $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
    }

    public function library(string $route): void
    {
    }
}

final class Phase1SigningSignerStub
{
    /** @var array<string, array<string, mixed>> */
    public array $byCode = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $byDocument = [];

    /** @var array<int, array<string, mixed>|null> */
    public array $nextByDocument = [];

    public function getSignerBySignCode(string $signCode): ?array
    {
        return $this->byCode[$signCode] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getSignersByDocumentId(int $documentId): array
    {
        return $this->byDocument[$documentId] ?? [];
    }

    public function getNextSigner(int $documentId): ?array
    {
        return $this->nextByDocument[$documentId] ?? null;
    }
}

final class Phase1SigningDocumentStub
{
    /** @var array<int, array<string, mixed>> */
    public array $byId = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $groups = [];

    public bool $owner = false;

    public function getDocumentById(int $documentId): ?array
    {
        return $this->byId[$documentId] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getDocumentsByGroupUuid(string $uuid): array
    {
        return $this->groups[$uuid] ?? [];
    }

    public function isPackComparableSignerRow(array $row): bool
    {
        return in_array(strtoupper(trim((string) ($row['signing_type'] ?? ''))), ['SIGNATURE', 'SEAL'], true);
    }

    public function canActAsDocumentOwner(int $roleId, array $document): bool
    {
        return $this->owner;
    }
}

final class Phase1SigningVisibilityStub
{
    public bool $accessible = true;

    public bool $hideResult = true;

    /** @var list<array<string, mixed>> */
    public array $writes = [];

    public function isDocumentAccessible(array $document, int $roleId, ?string $email): bool
    {
        return $this->accessible;
    }

    public function hideVisibilityForPrincipal(int $documentId, int $roleId, ?string $email): bool
    {
        $this->writes[] = compact('documentId', 'roleId', 'email');

        return $this->hideResult;
    }

    public function createVisibility(int $documentId, string $email, ?int $roleId): void
    {
        $this->writes[] = compact('documentId', 'email', 'roleId') + ['method' => 'createVisibility'];
    }
}

final class Phase1SigningClaimStub
{
    /** @var array<int, array<string, mixed>> */
    public array $succeededBySigner = [];

    /** @var list<array<string, mixed>> */
    public array $inserted = [];

    public function getSucceededClaimForSignerAndEmail(int $signerId, string $email): ?array
    {
        $row = $this->succeededBySigner[$signerId] ?? null;

        return $row !== null && strtolower((string) ($row['claimed_email'] ?? '')) === strtolower($email) ? $row : null;
    }

    public function getAnySucceededClaimForSigner(int $signerId): ?array
    {
        return $this->succeededBySigner[$signerId] ?? null;
    }

    public function getGuestIdentityIdNumberForSigner(int $signerId): ?string
    {
        return null;
    }

    public function insertClaim(array $row): int
    {
        $this->inserted[] = $row;
        $this->succeededBySigner[(int) $row['document_signer_id']] = $row;

        return count($this->inserted);
    }
}

final class Phase1SigningValidationStub
{
    public int $recent = 0;

    public function countRecentSignerClaimSends(int $customerId, string $email, int $window): int
    {
        return $this->recent;
    }

    public function countRecentSignerClaimSendsByRecipient(string $email, int $window): int
    {
        return 0;
    }

    public function getLatestSendTimestamp(int $customerId, string $email, string $type): ?int
    {
        return null;
    }
}

final class Phase1SigningEntitlementStub
{
    public bool $owes = false;

    public int $remaining = 10;

    public int $workflowBoundaryCalls = 0;

    public function signerOwesOneSidedDebit(array $document, array $signer): bool
    {
        return $this->owes;
    }

    public function getBillingCompanyIdForSigningAct(array $document, bool $isOwner, int $roleId): int
    {
        return 7;
    }

    public function getOneSidedRemainingForCustomerRole(int $roleId): int
    {
        return $this->remaining;
    }
}

final class Phase1SigningCertificateStub
{
    /** @var array<string, mixed>|null */
    public ?array $certificate = null;

    public function getCustomerCertificateByUsage(int $customerId, string $usage): ?array
    {
        return $this->certificate;
    }
}

final class Phase1SigningAuditStub
{
    public function isCertificateAvailable(array $document): bool
    {
        return ! empty($document['audit_certificate_available']);
    }
}

final class Phase1SigningGuestSessionStub
{
    public function getByDocumentSignerId(int $signerId): ?array
    {
        return null;
    }
}

final class Phase1SigningUploadStub
{
    public function getUploadByCodeForSigning(string $code): ?array
    {
        return null;
    }
}

final class Phase1SigningApiRequestStub
{
    public object $request;
    public object $headers;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload = [])
    {
        $this->request = new class($payload) {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload)
            {
            }

            public function all(): array
            {
                return $this->payload;
            }
        };
        $this->headers = new class {
            public function get(string $key): string
            {
                return '';
            }
        };
    }

    public function getContent(): string
    {
        return '';
    }
}

final class Phase1SigningController extends TestableControllerPublicAPIV1Signing
{
    /** @var array<string, mixed> */
    public array $testPost = [];

    public function signerInfo(string $signCode): void
    {
        $this->getSignerInfo($signCode);
    }

    public function deleteSigner(string $signCode): void
    {
        $this->deleteForSigner($signCode);
    }

    public function auditFile(string $signCode): void
    {
        $this->getAuditCertificateFile($signCode);
    }

    public function signPackForTest(string $groupUuid): void
    {
        $this->signPack($groupUuid);
    }

    public function sendResponse()
    {
        return null;
    }

    protected function getPost()
    {
        return $this->testPost;
    }
}

/**
 * @param mixed ...$args
 * @return mixed
 */
function signing_phase1_private(object $controller, string $method, ...$args): mixed
{
    $reflection = new ReflectionMethod(ControllerPublicAPIV1Signing::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$args);
}

/**
 * @param array<string, mixed> $payload
 * @return array{Phase1SigningController, Phase1SigningSignerStub, Phase1SigningDocumentStub, Phase1SigningVisibilityStub, Phase1SigningClaimStub, Phase1SigningValidationStub, Phase1SigningEntitlementStub, Phase1SigningCertificateStub}
 */
function signing_phase1_controller(array $payload = []): array
{
    $registry = new Registry();
    $signer = new Phase1SigningSignerStub();
    $document = new Phase1SigningDocumentStub();
    $visibility = new Phase1SigningVisibilityStub();
    $claim = new Phase1SigningClaimStub();
    $validation = new Phase1SigningValidationStub();
    $entitlement = new Phase1SigningEntitlementStub();
    $certificate = new Phase1SigningCertificateStub();
    $config = new Config();
    $config->set('config_language_id', 1);
    $config->set('signer_claim_send_limit_per_actor', 3);
    $config->set('signer_claim_send_limit_per_recipient', 5);
    $config->set('signer_claim_send_window_mins', 15);
    $config->set('signer_claim_send_min_interval_secs', 60);
    $registry->set('config', $config);
    $registry->set('customer', new Phase1SigningCustomerStub());
    $registry->set('request', (object) ['get' => [], 'server' => []]);
    $registry->set('load', new Phase1SigningLoadStub($registry, [
        'signing/signer' => $signer,
        'signing/document' => $document,
        'signing/visibility' => $visibility,
        'signing/signer_claim' => $claim,
        'signing/guest_session' => new Phase1SigningGuestSessionStub(),
        'signing/audit_event' => new Phase1SigningAuditStub(),
        'tool/upload' => new Phase1SigningUploadStub(),
        'tool/validation' => $validation,
        'billing/entitlement_signing' => $entitlement,
        'certificate/certificate' => $certificate,
    ]));
    $controller = new Phase1SigningController($registry);
    $controller->useremail = 'principal@example.com';
    $controller->apiRequest = new Phase1SigningApiRequestStub($payload);
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];

    return [$controller, $signer, $document, $visibility, $claim, $validation, $entitlement, $certificate];
}

/** @return array<string, mixed> */
function signing_phase1_signer(array $overrides = []): array
{
    return array_replace([
        'document_signer_id' => 101,
        'document_id' => 1,
        'sign_code' => 'primary-sign-code',
        'email' => 'principal@example.com',
        'customer_role_id' => 0,
        'customer_id' => 0,
        'signing_type' => 'SIGNATURE',
        'signature_kind' => ModelSigningSigner::KIND_QUALIFIED,
        'invitation_type' => ModelSigningSigner::INVITATION_STANDARD,
        'status_code' => 'INVITED',
        'signing_order' => 1,
        'parallel_group' => 0,
    ], $overrides);
}

/** @return array<string, mixed> */
function signing_phase1_document(array $overrides = []): array
{
    return array_replace([
        'document_id' => 1,
        'uuid' => 'primary-document-uuid',
        'name' => 'Primary agreement',
        'lifecycle_status_code' => 'PENDING',
        'document_group_uuid' => 'pack-uuid',
        'signature_level' => 'QUALIFIED',
        'signature_request_annotations' => json_encode([
            ['email' => 'principal@example.com', 'type' => 'SIGNATURE'],
            ['email' => 'foreign@example.com', 'type' => 'SEAL'],
        ]),
        'expires_at' => null,
        'completed_at' => null,
        'cancelled_at' => null,
        'rejected_at' => null,
        'date_added' => '2026-08-12 10:00:00',
        'audit_certificate_file_code' => 'missing-audit-code',
        'audit_certificate_available' => true,
    ], $overrides);
}

/**
 * Prerequisites:
 * - An authenticated principal has signer rows on two members of a mixed pack.
 *
 * Steps:
 * 1. Open the primary sign code and enumerate same-principal pack rows.
 * 2. Include a same-email sponsored row, a draft row, and a foreign-only row.
 * 3. Assert draft/foreign members and foreign annotations/sign codes are absent.
 */
test('Signing API — signer envelope contains only eligible same-principal pack members', function () {
    [$controller, $signer, $document] = signing_phase1_controller();
    $primary = signing_phase1_signer();
    $sibling = signing_phase1_signer([
        'document_signer_id' => 102,
        'document_id' => 2,
        'sign_code' => 'sibling-sign-code',
        'invitation_type' => ModelSigningSigner::INVITATION_SPONSORED,
        'status_code' => 'SIGNED',
    ]);
    $foreign = signing_phase1_signer([
        'document_signer_id' => 103,
        'document_id' => 3,
        'sign_code' => 'foreign-secret-code',
        'email' => 'foreign@example.com',
    ]);
    $draftSibling = signing_phase1_signer([
        'document_signer_id' => 104,
        'document_id' => 4,
        'sign_code' => 'draft-secret-code',
    ]);
    $signer->byCode['primary-sign-code'] = $primary;
    $signer->byDocument = [1 => [$primary, $foreign], 2 => [$sibling], 3 => [$foreign], 4 => [$draftSibling]];
    $document->byId = [
        1 => signing_phase1_document(),
        2 => signing_phase1_document(['document_id' => 2, 'uuid' => 'sibling-uuid', 'name' => 'Sibling']),
        3 => signing_phase1_document(['document_id' => 3, 'uuid' => 'foreign-uuid']),
        4 => signing_phase1_document(['document_id' => 4, 'uuid' => 'draft-uuid', 'lifecycle_status_code' => 'DRAFT']),
    ];
    $document->groups['pack-uuid'] = [
        ['document_id' => 1],
        ['document_id' => 2],
        ['document_id' => 3],
        ['document_id' => 4],
    ];

    $controller->signerInfo('primary-sign-code');

    $encoded = json_encode($controller->json['data']);
    expect($controller->statusCode)->toBe(200)
        ->and($controller->json['data']['member_count'])->toBe(2)
        ->and(array_column($controller->json['data']['documents'], 'sign_code'))->toBe(['primary-sign-code', 'sibling-sign-code'])
        ->and($controller->json['data']['documents'][0]['signature_request_annotations'])->toHaveCount(1)
        ->and($controller->json['data']['documents'][0]['signature_request_annotations'][0]['email'])->toBe('principal@example.com')
        ->and($encoded)->not->toContain('foreign-secret-code')
        ->not->toContain('draft-secret-code')
        ->not->toContain('foreign-uuid');
});

/**
 * Prerequisites:
 * - The authenticated account email differs from an unclaimed invited email.
 *
 * Steps:
 * 1. Open the invite's sign code without a succeeded claim.
 * 2. Assert the action is denied with a masked claim hint.
 * 3. Assert the raw sign code and full invited email are not disclosed in response data.
 */
test('Signing API — mismatched identity requires a scoped signer claim', function () {
    [$controller, $signer, $document] = signing_phase1_controller();
    $row = signing_phase1_signer(['email' => 'invited-person@example.com']);
    $signer->byCode['primary-sign-code'] = $row;
    $signer->byDocument[1] = [$row];
    $document->byId[1] = signing_phase1_document();

    $controller->signerInfo('primary-sign-code');

    expect($controller->statusCode)->toBe(403)
        ->and($controller->json['error'])->toContain('claim_required')
        ->and($controller->json['data']['claim_required'])->toBe(1)
        ->and($controller->json['data']['invited_email_hint'])->toBe('in***@example.com')
        ->and(json_encode($controller->json['data']))->not->toContain('invited-person@example.com')
        ->not->toContain('primary-sign-code');
});

/**
 * Prerequisites:
 * - Signing payloads must match the declared annotation count, type, and visibility.
 *
 * Steps:
 * 1. Normalize supported/unsupported payload types and default branding.
 * 2. Validate count, type, invisible-seal, and coordinate constraints.
 * 3. Assert only structurally matching payload reaches later signing work.
 */
test('Signing API — pack payload validation rejects partial or mismatched writes', function () {
    [$controller] = signing_phase1_controller();
    $normalize = signing_phase1_private($controller, 'normalizeSigningPayloadTypes', [
        ['type' => 'signature', 'page' => 1, 'x' => 1, 'y' => 1],
        ['type' => 'seal'],
    ]);

    expect($normalize[0]['type'])->toBe('SIGNATURE')
        ->and($normalize[0]['stamp_branding'])->toBe('simplifi')
        ->and(signing_phase1_private($controller, 'normalizeSigningPayloadTypes', [['type' => 'TEXT']]))->toBeNull()
        ->and(signing_phase1_private($controller, 'validateSigningPayloadAgainstSignerAnnotations', [], [['type' => 'SIGNATURE']]))
        ->toBe('signature_payload_annotation_count_mismatch')
        ->and(signing_phase1_private($controller, 'validateSigningPayloadAgainstSignerAnnotations', [['type' => 'SEAL']], [['type' => 'SIGNATURE']]))
        ->toBe('signature_payload_type_mismatch')
        ->and(signing_phase1_private($controller, 'validateSigningPayloadAgainstSignerAnnotations', [['type' => 'SEAL', 'page' => 1, 'x' => 1, 'y' => 1]], [['type' => 'SEAL', 'invisible' => true]]))
        ->toBe('seal_invisible_must_not_include_placement')
        ->and(signing_phase1_private($controller, 'validateSigningPayloadAgainstSignerAnnotations', [['type' => 'SIGNATURE']], [['type' => 'SIGNATURE']]))
        ->toBe('invalid_signature_format')
        ->and(signing_phase1_private($controller, 'validateSigningPayloadAgainstSignerAnnotations', [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1]], [['type' => 'SIGNATURE']]))
        ->toBeNull();
});

/**
 * Prerequisites:
 * - A qualified pack member has a valid payload and is currently eligible to sign.
 *
 * Steps:
 * 1. Exercise missing certificate, aggregate balance shortfall, and missing pack TOTP.
 * 2. Keep signing workflow/provider doubles beyond the preflight boundary unavailable.
 * 3. Assert hard failures occur before any member result can be marked signed.
 */
test('Signing API — pack preflight stops missing certificate balance and TOTP before partial writes', function (string $case, int $status, string $error) {
    [$controller, $signer, $document, , , , $entitlement, $certificate] = signing_phase1_controller([
        'documents' => [[
            'sign_code' => 'primary-sign-code',
            'signature_payload' => [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1]],
        ]],
    ]);
    $row = signing_phase1_signer();
    $signer->byCode['primary-sign-code'] = $row;
    $signer->byDocument[1] = [$row];
    $signer->nextByDocument[1] = $row;
    $doc = signing_phase1_document();
    $document->byId[1] = $doc;
    $document->groups['pack-uuid'] = [$doc];
    if ($case !== 'certificate') {
        $certificate->certificate = [
            'cert_status' => 'valid',
            'sam_last_keypair' => 'keypair',
            'sam_signer_jws' => 'jws',
            'sam_signing_key_id' => 'key-id',
            'sam_subject' => 'CN=Principal Signer',
            'cert_pem' => 'certificate-pem',
            'cert_chain_pem' => null,
        ];
    }
    if ($case === 'balance') {
        $entitlement->owes = true;
        $entitlement->remaining = 0;
    }

    $controller->signPackForTest('pack-uuid');

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toContain($error)
        ->and(json_encode($controller->json))->not->toContain('"status":"signed"');
})->with([
    'missing certificate' => ['certificate', 422, 'Valid certificate required for signing'],
    'aggregate balance shortfall' => ['balance', 409, 'insufficient_one_sided_document_balance'],
    'qualified pack missing TOTP' => ['totp', 422, 'totp_code_required'],
]);

/**
 * Prerequisites:
 * - Pack results have signed, skipped, and failed member outcomes.
 *
 * Steps:
 * 1. Build the public summary from mixed deterministic results.
 * 2. Count each classification once.
 * 3. Assert document result order and stable reason fields are preserved.
 */
test('Signing API — pack result classifies signed skipped and failed members', function () {
    [$controller] = signing_phase1_controller();
    $results = [
        ['uuid' => 'signed-uuid', 'status' => 'signed', 'signed_at' => '2026-08-12T10:00:00Z'],
        ['uuid' => 'skipped-uuid', 'status' => 'skipped', 'reason' => 'already_signed'],
        ['uuid' => 'failed-uuid', 'status' => 'failed', 'reason' => 'workflow_failed'],
    ];

    $summary = signing_phase1_private($controller, 'buildPackSignSummary', 'pack-uuid', $results);

    expect($summary['summary'])->toBe(['requested' => 3, 'signed' => 1, 'skipped' => 1, 'failed' => 1])
        ->and(array_column($summary['documents'], 'uuid'))->toBe(['signed-uuid', 'skipped-uuid', 'failed-uuid'])
        ->and($summary['documents'][1]['reason'])->toBe('already_signed')
        ->and($summary['documents'][2]['reason'])->toBe('workflow_failed');
});

/**
 * Prerequisites:
 * - Claim email is unnecessary for exact email matches and rate-limited for mismatched accounts.
 *
 * Steps:
 * 1. Exercise exact-match, prior-claim, and send-rate-limit branches.
 * 2. Assert idempotent/stable application codes.
 * 3. Assert no claim row or visibility grant is created by denied sends.
 */
test('Signing API — claim email is idempotent and rate-limited before queueing', function () {
    [$exact, $exactSigner, $exactDocument, $exactVisibility, $exactClaim] = signing_phase1_controller();
    $exactRow = signing_phase1_signer();
    $exactSigner->byCode['primary-sign-code'] = $exactRow;
    $exactDocument->byId[1] = signing_phase1_document();
    signing_phase1_private($exact, 'claimEmail', 'primary-sign-code');

    [$claimed, $claimedSigner, $claimedDocument, $claimedVisibility, $claimedClaim] = signing_phase1_controller();
    $claimedRow = signing_phase1_signer(['email' => 'invitee@example.com']);
    $claimedSigner->byCode['primary-sign-code'] = $claimedRow;
    $claimedDocument->byId[1] = signing_phase1_document();
    $claimedClaim->succeededBySigner[101] = ['claimed_email' => 'principal@example.com'];
    signing_phase1_private($claimed, 'claimEmail', 'primary-sign-code');

    [$limited, $limitedSigner, $limitedDocument, $limitedVisibility, $limitedClaim, $validation] = signing_phase1_controller();
    $limitedSigner->byCode['primary-sign-code'] = $claimedRow;
    $limitedDocument->byId[1] = signing_phase1_document();
    $validation->recent = 3;
    signing_phase1_private($limited, 'claimEmail', 'primary-sign-code');

    expect($exact->statusCode)->toBe(422)
        ->and($exact->json['error'])->toContain('claim_not_required')
        ->and($claimed->statusCode)->toBe(422)
        ->and($claimed->json['error'])->toContain('already_claimed')
        ->and($limited->statusCode)->toBe(429)
        ->and($limited->json['error'])->toContain('claim_email_rate_limited')
        ->and($exactClaim->inserted)->toBe([])
        ->and($limitedClaim->inserted)->toBe([])
        ->and($exactVisibility->writes)->toBe([])
        ->and($limitedVisibility->writes)->toBe([]);
});

/**
 * Prerequisites:
 * - An email OTP claim applies to same-email signer rows in the same pack only.
 *
 * Steps:
 * 1. Propagate one proof across a same-email sibling and a foreign sibling.
 * 2. Record the eligible claim and visibility grant.
 * 3. Assert the foreign signer receives neither mutation.
 */
test('Signing API — successful claim propagates only to eligible pack siblings', function () {
    [$controller, $signer, $document, $visibility, $claim] = signing_phase1_controller();
    $primary = signing_phase1_signer(['email' => 'invitee@example.com']);
    $same = signing_phase1_signer([
        'document_signer_id' => 102,
        'document_id' => 2,
        'sign_code' => 'same-email-code',
        'email' => 'invitee@example.com',
    ]);
    $foreign = signing_phase1_signer([
        'document_signer_id' => 103,
        'document_id' => 2,
        'sign_code' => 'foreign-code',
        'email' => 'foreign@example.com',
    ]);
    $document->groups['pack-uuid'] = [['document_id' => 1], ['document_id' => 2]];
    $document->byId[2] = signing_phase1_document(['document_id' => 2, 'uuid' => 'sibling-uuid']);
    $signer->byDocument[2] = [$same, $foreign];

    signing_phase1_private($controller, 'propagateEmailOtpClaimToPackSiblings', $primary, signing_phase1_document(), 'validation-7');

    expect($claim->inserted)->toHaveCount(1)
        ->and($claim->inserted[0]['document_signer_id'])->toBe(102)
        ->and($claim->inserted[0]['proof_type'])->toBe(ModelSigningSignerClaim::PROOF_EMAIL_OTP)
        ->and($claim->inserted[0]['proof_ref'])->toBe('validation-7')
        ->and(array_column($claim->inserted, 'document_signer_id'))->not->toContain(103)
        ->and($visibility->writes)->toHaveCount(1)
        ->and($visibility->writes[0]['email'])->toBe('principal@example.com');
});

/**
 * Prerequisites:
 * - Signer deletion requires matching identity and cannot hide an actionable invite.
 *
 * Steps:
 * 1. Exercise mismatched caller, pending invite, completed signer, and visibility failure.
 * 2. Assert access/lifecycle/idempotent failure codes.
 * 3. Assert only an eligible completed signer receives a visibility write.
 */
test('Signing API — signer deletion cannot abandon an actionable invite', function () {
    [$unauthorized, $unauthorizedSigner, $unauthorizedDocument, $unauthorizedVisibility] = signing_phase1_controller();
    $foreignRow = signing_phase1_signer(['email' => 'foreign@example.com']);
    $unauthorizedSigner->byCode['primary-sign-code'] = $foreignRow;
    $unauthorizedDocument->byId[1] = signing_phase1_document();
    $unauthorized->deleteSigner('primary-sign-code');

    [$pending, $pendingSigner, $pendingDocument, $pendingVisibility] = signing_phase1_controller();
    $row = signing_phase1_signer();
    $pendingSigner->byCode['primary-sign-code'] = $row;
    $pendingDocument->byId[1] = signing_phase1_document();
    $pending->deleteSigner('primary-sign-code');

    [$completed, $completedSigner, $completedDocument, $completedVisibility] = signing_phase1_controller();
    $completedRow = signing_phase1_signer(['status_code' => 'SIGNED']);
    $completedSigner->byCode['primary-sign-code'] = $completedRow;
    $completedDocument->byId[1] = signing_phase1_document(['lifecycle_status_code' => 'COMPLETED']);
    $completed->deleteSigner('primary-sign-code');

    expect($unauthorized->statusCode)->toBe(403)
        ->and($unauthorized->json['error'])->toContain('access_denied')
        ->and($pending->statusCode)->toBe(409)
        ->and($pending->json['error'])->toContain('reject_required_before_delete')
        ->and($completed->statusCode)->toBe(200)
        ->and($completedVisibility->writes)->toHaveCount(1)
        ->and($unauthorizedVisibility->writes)->toBe([])
        ->and($pendingVisibility->writes)->toBe([]);
});

/**
 * Prerequisites:
 * - Signer completion evidence uses the same identity/visibility gates as document files.
 *
 * Steps:
 * 1. Request evidence before completion, with a foreign identity, and with missing stored evidence.
 * 2. Assert lifecycle, existence-hiding, and missing-evidence codes.
 * 3. Assert response JSON exposes no upload code or path.
 */
test('Signing API — signer audit evidence fails closed across access and storage guards', function () {
    [$pending, $pendingSigner, $pendingDocument] = signing_phase1_controller();
    $row = signing_phase1_signer();
    $pendingSigner->byCode['primary-sign-code'] = $row;
    $pendingDocument->byId[1] = signing_phase1_document();
    $pending->auditFile('primary-sign-code');

    [$foreign, $foreignSigner, $foreignDocument] = signing_phase1_controller();
    $foreignSigner->byCode['primary-sign-code'] = signing_phase1_signer(['email' => 'foreign@example.com']);
    $foreignDocument->byId[1] = signing_phase1_document(['lifecycle_status_code' => 'COMPLETED']);
    $foreign->auditFile('primary-sign-code');

    [$missing, $missingSigner, $missingDocument] = signing_phase1_controller();
    $missingSigner->byCode['primary-sign-code'] = $row;
    $missingDocument->byId[1] = signing_phase1_document(['lifecycle_status_code' => 'COMPLETED']);
    $missing->auditFile('primary-sign-code');

    expect($pending->statusCode)->toBe(422)
        ->and($pending->json['error'])->toContain('document_not_completed')
        ->and($foreign->statusCode)->toBe(404)
        ->and($foreign->json['error'])->toContain('Document not found')
        ->and($missing->statusCode)->toBe(404)
        ->and($missing->json['error'])->toContain('audit_certificate_not_available')
        ->and(json_encode([$pending->json, $foreign->json, $missing->json]))
        ->not->toContain('missing-audit-code')
        ->not->toContain(DIR_UPLOAD);
});
