<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests_config.php';

if (! class_exists(ModelSigningSigner::class, false)) {
    class ModelSigningSigner
    {
        public const INVITATION_STANDARD = 'standard_invitation';
        public const INVITATION_GUEST = 'guest_invitation';
        public const INVITATION_SPONSORED = 'sponsored_invitation';
        public const KIND_QUALIFIED = 'qualified';
        public const KIND_SIMPLE = 'simple';

        public static function invitationTypeFromRow(array $row): string
        {
            return trim((string) ($row['invitation_type'] ?? self::INVITATION_STANDARD));
        }

        public static function isGuestInvitation(array $row): bool
        {
            return self::invitationTypeFromRow($row) === self::INVITATION_GUEST;
        }

        public static function isSponsoredInvitation(array $row): bool
        {
            return self::invitationTypeFromRow($row) === self::INVITATION_SPONSORED;
        }

        public static function isSimpleSignatureKind(array $row): bool
        {
            return trim((string) ($row['signature_kind'] ?? '')) === self::KIND_SIMPLE;
        }
    }
}

require_once PUBLIC_API . 'signing_guest.php';

final class GuestSigningConfigStub
{
    public function load(string $key): void
    {
    }

    public function get(string $key): mixed
    {
        return match ($key) {
            'signing_guest_access_policy' => ['completed_access_days' => 3],
            'config_language' => 'en-gb',
            'config_language_id' => 1,
            default => null,
        };
    }
}

final class GuestSigningLoadStub
{
    /** @param array<string, object> $models */
    public function __construct(private readonly Registry $registry, private readonly array $models)
    {
    }

    public function model(string $route): void
    {
        if (! isset($this->models[$route])) {
            throw new RuntimeException('Unexpected guest signing model: ' . $route);
        }

        $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
    }
}

final class GuestSigningSignerStub
{
    /** @var array<string, array<string, mixed>> */
    public array $byCode = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $byDocument = [];

    public function getSignerBySignCode(string $signCode): ?array
    {
        return $this->byCode[$signCode] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getSignersByDocumentId(int $documentId): array
    {
        return $this->byDocument[$documentId] ?? [];
    }
}

final class GuestSigningDocumentStub
{
    /** @var array<int, array<string, mixed>> */
    public array $byId = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $groups = [];

    public function getDocumentById(int $documentId): ?array
    {
        return $this->byId[$documentId] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getDocumentsByGroupUuid(string $uuid): array
    {
        return $this->groups[$uuid] ?? [];
    }
}

final class GuestSigningVisibilityStub
{
    public bool $visible = true;

    public function isGuestSignerVisible(int $documentId, ?string $email): bool
    {
        return $this->visible;
    }
}

final class GuestSigningSessionStub
{
    /** @var array<string, mixed>|null */
    public ?array $session = null;

    public int $createCalls = 0;

    public function createOrGet(int $documentId, int $signerId): void
    {
        ++$this->createCalls;
    }

    public function getByDocumentSignerId(int $signerId): ?array
    {
        return $this->session;
    }
}

final class GuestSigningCheckinStub
{
    /** @var array<string, mixed>|null */
    public ?array $verification = null;

    public function getVerificationByVerificationId(string $verificationId): ?array
    {
        return $this->verification;
    }
}

final class GuestSigningDbStub
{
    /** @var list<array<string, mixed>> */
    public array $catalogRows = [];

    public function query(string $sql): object
    {
        return (object) ['rows' => $this->catalogRows, 'row' => [], 'num_rows' => count($this->catalogRows)];
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

final class GuestSigningApiRequestStub
{
    public object $request;
    public object $headers;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload)
    {
        $this->request = new class($payload) {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload)
            {
            }

            /** @return array<string, mixed> */
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

final class TestableGuestSigningController extends ControllerPublicAPIV1SigningGuest
{
    public function checkPlugin(): void
    {
    }

    public function sendResponse()
    {
        return null;
    }
}

/**
 * @param mixed ...$args
 * @return mixed
 */
function guest_signing_private(object $controller, string $method, ...$args): mixed
{
    $reflection = new ReflectionMethod(ControllerPublicAPIV1SigningGuest::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$args);
}

/** @return array{TestableGuestSigningController, GuestSigningSignerStub, GuestSigningDocumentStub, GuestSigningVisibilityStub, GuestSigningSessionStub, GuestSigningCheckinStub, GuestSigningDbStub} */
function guest_signing_controller(array $payload = []): array
{
    $registry = new Registry();
    $signer = new GuestSigningSignerStub();
    $document = new GuestSigningDocumentStub();
    $visibility = new GuestSigningVisibilityStub();
    $session = new GuestSigningSessionStub();
    $checkin = new GuestSigningCheckinStub();
    $db = new GuestSigningDbStub();
    $registry->set('config', new GuestSigningConfigStub());
    $registry->set('db', $db);
    $registry->set('request', (object) ['get' => [], 'server' => []]);
    $registry->set('load', new GuestSigningLoadStub($registry, [
        'signing/signer' => $signer,
        'signing/document' => $document,
        'signing/visibility' => $visibility,
        'signing/guest_session' => $session,
        'signing/guest_identity' => new stdClass(),
        'checkin/checkin' => $checkin,
    ]));
    $controller = new TestableGuestSigningController($registry);
    $controller->apiRequest = new GuestSigningApiRequestStub($payload);
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];

    return [$controller, $signer, $document, $visibility, $session, $checkin, $db];
}

/** @return array<string, mixed> */
function guest_signer(array $overrides = []): array
{
    return array_replace([
        'document_signer_id' => 10,
        'document_id' => 1,
        'sign_code' => 'guest-code',
        'email' => 'guest@example.com',
        'signing_type' => 'SIGNATURE',
        'signature_kind' => ModelSigningSigner::KIND_QUALIFIED,
        'invitation_type' => ModelSigningSigner::INVITATION_GUEST,
        'status_code' => 'INVITED',
    ], $overrides);
}

/** @return array<string, mixed> */
function guest_document(array $overrides = []): array
{
    return array_replace([
        'document_id' => 1,
        'uuid' => 'guest-document',
        'name' => 'Guest agreement',
        'lifecycle_status_code' => 'PENDING',
        'document_group_uuid' => '',
        'signature_request_annotations' => '[]',
        'signature_level' => 'QUALIFIED',
        'expires_at' => null,
        'completed_at' => null,
        'cancelled_at' => null,
        'rejected_at' => null,
        'date_added' => '2026-08-12 10:00:00',
    ], $overrides);
}

/**
 * Prerequisites:
 * - Guest entry URLs are bearer-like and only guest invitations may open the entry envelope.
 *
 * Steps:
 * 1. Resolve unknown, standard qualified, and valid guest invitation codes.
 * 2. Require a guest-primary row for entry access.
 * 3. Assert denials reveal no sibling signer code.
 */
test('Guest signing API — entry access is restricted to guest invitation links', function (string $kind, int $expectedStatus, bool $expectedOk) {
    [$controller, $signer, $document] = guest_signing_controller();
    if ($kind !== 'unknown') {
        $row = guest_signer([
            'invitation_type' => $kind === 'guest'
                ? ModelSigningSigner::INVITATION_GUEST
                : ModelSigningSigner::INVITATION_STANDARD,
        ]);
        $signer->byCode['requested-code'] = $row;
        $document->byId[1] = guest_document();
    }

    $result = guest_signing_private($controller, 'resolveGuestSigningAccess', 'requested-code', true);

    expect($result['ok'])->toBe($expectedOk)
        ->and((int) ($controller->statusCode ?? 0))->toBe($expectedStatus)
        ->and(json_encode($controller->json))->not->toContain('foreign-sibling-code');
})->with([
    'unknown link' => ['unknown', 404, false],
    'qualified standard link' => ['standard', 403, false],
    'guest link' => ['guest', 200, true],
]);

/**
 * Prerequisites:
 * - A sponsored signer belongs to a group containing a guest-primary signer with the same email.
 *
 * Steps:
 * 1. Resolve the sponsored member for commit/status access.
 * 2. Compare only signing rows with the same normalized email.
 * 3. Assert the guest-primary session owner is selected and foreign siblings are ignored.
 */
test('Guest signing API — sponsored pack sibling requires same-email guest primary', function () {
    [$controller, $signer, $document] = guest_signing_controller();
    $sponsored = guest_signer([
        'document_signer_id' => 20,
        'document_id' => 2,
        'sign_code' => 'sponsored-code',
        'invitation_type' => ModelSigningSigner::INVITATION_SPONSORED,
    ]);
    $primary = guest_signer(['document_signer_id' => 10, 'document_id' => 1, 'sign_code' => 'primary-code']);
    $foreign = guest_signer([
        'document_signer_id' => 30,
        'document_id' => 1,
        'sign_code' => 'foreign-code',
        'email' => 'other@example.com',
    ]);
    $signer->byCode['sponsored-code'] = $sponsored;
    $signer->byDocument = [1 => [$foreign, $primary], 2 => [$sponsored]];
    $document->byId = [1 => guest_document(), 2 => guest_document(['document_id' => 2, 'document_group_uuid' => 'pack-one'])];
    $document->groups['pack-one'] = [['document_id' => 1], ['document_id' => 2]];

    $result = guest_signing_private($controller, 'resolveGuestSigningAccess', 'sponsored-code', false);

    expect($result['ok'])->toBeTrue()
        ->and($result['guest_primary_signer']['document_signer_id'])->toBe(10)
        ->and($result['guest_primary_signer']['sign_code'])->toBe('primary-code')
        ->and($result['guest_primary_signer']['sign_code'])->not->toBe('foreign-code');
});

/**
 * Prerequisites:
 * - Completed guest links remain readable for three days, while cancelled/rejected links are closed.
 *
 * Steps:
 * 1. Evaluate lifecycle and completed-at fixtures through the read policy.
 * 2. Check active and expired completion windows.
 * 3. Assert stable access status without mutating signer state.
 */
test('Guest signing API — read policy closes unsafe lifecycle and expired completion links', function (array $document, bool $allowed, int $status) {
    [$controller] = guest_signing_controller();

    $result = guest_signing_private($controller, 'enforceGuestReadAccessPolicy', $document);

    expect($result)->toBe($allowed)
        ->and((int) ($controller->statusCode ?? 0))->toBe($status);
})->with([
    'pending remains readable' => [guest_document(), true, 200],
    'cancelled is denied' => [guest_document(['lifecycle_status_code' => 'CANCELLED']), false, 403],
    'rejected is denied' => [guest_document(['lifecycle_status_code' => 'REJECTED']), false, 403],
    'completed without timestamp is denied' => [guest_document(['lifecycle_status_code' => 'COMPLETED']), false, 403],
    'completed with invalid timestamp is denied' => [guest_document(['lifecycle_status_code' => 'COMPLETED', 'completed_at' => 'not-a-date']), false, 403],
    'recent completion remains readable' => [guest_document(['lifecycle_status_code' => 'COMPLETED', 'completed_at' => gmdate('Y-m-d H:i:s', time() - 3600)]), true, 200],
    'expired completion is gone' => [guest_document(['lifecycle_status_code' => 'COMPLETED', 'completed_at' => gmdate('Y-m-d H:i:s', time() - 5 * 86400)]), false, 410],
]);

/**
 * Prerequisites:
 * - A simple guest may submit one signature placement per declared annotation.
 *
 * Steps:
 * 1. Filter annotations to the guest's normalized email.
 * 2. Validate counts, supported type, and coordinates.
 * 3. Assert seal and foreign-email placements cannot cross the signing boundary.
 */
test('Guest signing API — simple payload is bound to declared annotations before signing', function () {
    [$controller] = guest_signing_controller();
    $document = guest_document([
        'signature_request_annotations' => json_encode([
            ['email' => 'Guest@Example.com', 'type' => 'SIGNATURE'],
            ['email' => 'foreign@example.com', 'type' => 'SIGNATURE'],
        ]),
    ]);
    $signer = guest_signer(['signature_kind' => ModelSigningSigner::KIND_SIMPLE]);

    expect(guest_signing_private($controller, 'validateSimpleGuestPayloadAgainstAnnotations', [], $document, $signer))
        ->toBe('signature_payload_annotation_count_mismatch')
        ->and(guest_signing_private($controller, 'validateSimpleGuestPayloadAgainstAnnotations', [['type' => 'SEAL', 'page' => 1, 'x' => 1, 'y' => 1]], $document, $signer))
        ->toBe('seal_not_allowed_for_simple_signer')
        ->and(guest_signing_private($controller, 'validateSimpleGuestPayloadAgainstAnnotations', [['type' => 'TEXT', 'page' => 1, 'x' => 1, 'y' => 1]], $document, $signer))
        ->toBe('invalid_signature_format')
        ->and(guest_signing_private($controller, 'validateSimpleGuestPayloadAgainstAnnotations', [['type' => 'SIGNATURE']], $document, $signer))
        ->toBe('invalid_signature_format')
        ->and(guest_signing_private($controller, 'validateSimpleGuestPayloadAgainstAnnotations', [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1]], $document, $signer))
        ->toBeNull();
});

/**
 * Prerequisites:
 * - A guest pack stores one payload per document signer and may reuse stored members across commits.
 *
 * Steps:
 * 1. Merge payloads for two pack members while preserving the shared timezone.
 * 2. Parse the stored envelope and compute required/missing member ids.
 * 3. Assert each signer receives only its own placement list and the pack becomes ready once complete.
 */
test('Guest signing API — commit payload store merges exactly one entry per pack member', function () {
    $first = GuestPackSignaturePayload::mergeMember(
        '',
        10,
        10,
        'primary-code',
        1,
        [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1]],
        'Europe/Bucharest',
    );
    $second = GuestPackSignaturePayload::mergeMember(
        $first,
        10,
        20,
        'sibling-code',
        2,
        [['type' => 'SIGNATURE', 'page' => 2, 'x' => 2, 'y' => 2]],
        null,
    );
    $parsed = GuestPackSignaturePayload::parseStored($second, 10);

    expect(GuestPackSignaturePayload::getPayloadForSigner($parsed, 10)[0]['page'])->toBe(1)
        ->and(GuestPackSignaturePayload::getPayloadForSigner($parsed, 20)[0]['page'])->toBe(2)
        ->and(GuestPackSignaturePayload::getPayloadForSigner($parsed, 30))->toBeNull()
        ->and(GuestPackSignaturePayload::getTimezone($parsed))->toBe('Europe/Bucharest')
        ->and(GuestPackSignaturePayload::getMissingSignerIds($parsed, [10, 20, 30]))->toBe([30])
        ->and(GuestPackSignaturePayload::allMembersHavePayload($parsed, [10, 20]))->toBeTrue();
});

/**
 * Prerequisites:
 * - Simple guest signing depends on an explicitly configured operator seal.
 *
 * Steps:
 * 1. Invoke the simple-signing boundary without an operator seal configuration.
 * 2. Assert the stable configuration error.
 * 3. Assert no session-completion or signing workflow model is reached.
 */
test('Guest signing API — simple commit stops before workflow when operator seal is unavailable', function () {
    [$controller] = guest_signing_controller();

    guest_signing_private(
        $controller,
        'commitSimpleGuestSignature',
        guest_signer(['signature_kind' => ModelSigningSigner::KIND_SIMPLE]),
        guest_document(),
        guest_signer(),
        '{}',
        null,
        ['signer_name' => 'Guest Signer'],
        [],
    );

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toContain('simple_signature_seal_not_configured')
        ->and($controller->json['data'])->toBe([]);
});

/**
 * Prerequisites:
 * - Guest commit must reject unsafe state and payload before identity or signing providers run.
 *
 * Steps:
 * 1. Arrange a visible guest signer and one early-invalid condition.
 * 2. Invoke the real commit controller branch.
 * 3. Assert a stable failure and no provider-capable model is called.
 */
test('Guest signing API — commit rejects state and malformed payloads before providers', function (array $documentPatch, array $signerPatch, ?array $session, array $payload, int $status, string $errorFragment) {
    [$controller, $signer, $document, , $guestSession] = guest_signing_controller($payload);
    $row = guest_signer($signerPatch);
    $signer->byCode['guest-code'] = $row;
    $signer->byDocument[1] = [$row];
    $document->byId[1] = guest_document($documentPatch);
    $guestSession->session = $session;

    guest_signing_private($controller, 'guestCommit', 'guest-code');

    expect($controller->statusCode)->toBe($status)
        ->and(implode(' ', $controller->json['error']))->toContain($errorFragment);
})->with([
    'wrong document lifecycle' => [['lifecycle_status_code' => 'DRAFT'], [], null, [], 422, 'PENDING'],
    'wrong signer status' => [[], ['status_code' => 'SIGNED'], null, [], 422, 'status'],
    'identification replay' => [[], [], ['state' => 'checkin_submitted'], [], 409, 'pending'],
    'invalid JSON string' => [[], [], null, ['signature_payload' => '{broken'], 422, 'JSON'],
    'seal placement' => [[], [], null, ['signature_payload' => [['type' => 'SEAL', 'page' => 1, 'x' => 1, 'y' => 1]]], 422, 'seal_not_allowed'],
    'invalid placement' => [[], [], null, ['signature_payload' => [['type' => 'SIGNATURE']]], 422, 'Invalid signature format'],
    'invalid branding' => [[], [], null, ['signature_payload' => [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1, 'stamp_branding' => 'untrusted']]], 400, 'invalid_stamp_branding'],
    'invalid timezone' => [[], [], null, ['signature_payload' => [['type' => 'SIGNATURE', 'page' => 1, 'x' => 1, 'y' => 1]], 'timezone' => 'Mars/Olympus'], 400, 'invalid_timezone'],
]);

/**
 * Prerequisites:
 * - A declined verification contains one curated user-safe concern and one hidden fraud concern.
 *
 * Steps:
 * 1. Resolve the stored decline payload through the production concern catalog.
 * 2. Append the public decline status fields.
 * 3. Assert curated copy/retryability is returned and raw vendor/fraud prose is absent.
 */
test('Guest signing API — declined status exposes only curated safe reasons', function () {
    [$controller, , , , , $checkin, $db] = guest_signing_controller();
    $checkin->verification = [
        'verification_decline_json' => json_encode(['concerns' => [
            ['id' => 'VISIBLE-1', 'service' => 'document', 'message' => 'raw visible vendor prose'],
            ['id' => 'FRAUD-1', 'service' => 'fraud', 'message' => 'hidden fraud score 98'],
        ]]),
    ];
    $db->catalogRows = [
        [
            'code' => 'VISIBLE-1',
            'active' => 1,
            'show_user' => 1,
            'user_message_en' => 'Please retake the document photo.',
            'user_message_ro' => 'Refaceți fotografia documentului.',
            'retryable' => 1,
        ],
        [
            'code' => 'FRAUD-1',
            'active' => 1,
            'show_user' => 0,
            'user_message_en' => 'Fraud score is high.',
            'user_message_ro' => 'Scor fraudă ridicat.',
            'retryable' => 0,
        ],
    ];
    $controller->json['data'] = [];

    guest_signing_private($controller, 'appendGuestDeclineReasons', [
        'checkin_verification_id' => 'verification-1',
    ]);

    expect($controller->json['data']['decline_reasons'])->toBe(['Please retake the document photo.'])
        ->and($controller->json['data']['decline_retryable'])->toBeTrue()
        ->and(json_encode($controller->json['data']))->not->toContain('raw visible vendor prose')
        ->not->toContain('hidden fraud score')
        ->not->toContain('Fraud score is high')
        ->not->toContain('FRAUD-1');
});
