<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests_config.php';
require_once DIR_SYSTEM . 'library/config.php';
require_once PUBLIC_API . 'signing.php';
require_once __DIR__ . '/_support/SigningTestDoubles.php';
require_once PUBLIC_API . 'signing/signhash.php';

use RobThree\Auth\TwoFactorAuth;

final class SignHashCustomerStub
{
    public function __construct(
        private readonly int $id = 51,
        private readonly int $roleId = 9,
        private readonly int $companyId = 7,
        private readonly ?string $totpSecret = 'JBSWY3DPEHPK3PXP',
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

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }
}

final class SignHashCertificateStub
{
    /** @var array<string, mixed>|null */
    public ?array $certificate = null;

    /** @var list<array{customer_id: int, usage: string}> */
    public array $lookups = [];

    public function getCustomerCertificateByUsage(int $customerId, string $usage): ?array
    {
        $this->lookups[] = ['customer_id' => $customerId, 'usage' => $usage];

        return $this->certificate;
    }
}

final class SignHashLoadStub
{
    /** @param array<string, object> $models */
    public function __construct(private readonly Registry $registry, private readonly array $models)
    {
    }

    public function model(string $route): void
    {
        if (! isset($this->models[$route])) {
            throw new RuntimeException('Unexpected sign-hash model: ' . $route);
        }

        $this->registry->set('model_' . str_replace('/', '_', $route), $this->models[$route]);
    }
}

final class TestableSignHashController extends ControllerPublicAPIV1SigningSignhash
{
    /** @var array<string, mixed> */
    public array $testPost = [];

    /** @var list<array{customer_id: int, success: bool}> */
    public array $totpAudits = [];

    public function checkPlugin(): void
    {
    }

    public function sendResponse()
    {
        return null;
    }

    protected function getPost()
    {
        return $this->testPost;
    }

    protected function auditTotpVerification($customer_id, $context, $success, array $extra = array())
    {
        $this->totpAudits[] = ['customer_id' => (int) $customer_id, 'success' => (bool) $success];
    }
}

/** @return array{TestableSignHashController, SignHashCertificateStub} */
function sign_hash_controller(?SignHashCustomerStub $customer = null): array
{
    $registry = new Registry();
    $certificate = new SignHashCertificateStub();
    $config = new Config();
    $config->set(EjbcaIssuanceProfiles::SETTING_DUAL_CERT_MODE, EjbcaIssuanceProfiles::MODE_MANDATORY);
    $config->set(SignhashCallerAllowlist::SETTING_ENFORCEMENT, SignhashCallerAllowlist::ENFORCEMENT_OFF);
    $registry->set('config', $config);
    $registry->set('customer', $customer ?? new SignHashCustomerStub());
    $registry->set('load', new SignHashLoadStub($registry, ['certificate/certificate' => $certificate]));

    $controller = new TestableSignHashController($registry);
    $controller->permission = (object) ['post' => [ControllerPublicAPIV1SigningSignhash::PERMISSION_PATH]];
    $controller->json = ['success' => 1, 'error' => [], 'data' => []];

    return [$controller, $certificate];
}

function sign_hash_totp(): string
{
    return (new TwoFactorAuth())->getCode('JBSWY3DPEHPK3PXP');
}

function sign_hash_run(TestableSignHashController $controller): void
{
    set_error_handler(static function (int $severity, string $message): bool {
        return str_contains($message, 'signhash_debug.log');
    });
    try {
        $controller->index();
    } finally {
        restore_error_handler();
    }
}

beforeEach(function () {
    $this->savedSignHashMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
});

afterEach(function () {
    if ($this->savedSignHashMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $this->savedSignHashMethod;
    }
});

/**
 * Prerequisites:
 * - Sign-hash is POST-only, permissioned, and requires an authenticated customer.
 *
 * Steps:
 * 1. Exercise method, permission, and authentication guards.
 * 2. Assert each request stops before certificate lookup.
 * 3. Assert only stable client codes are returned.
 */
test('Sign hash API — routing guards stop before certificate and SAM calls', function () {
    [$method, $methodCert] = sign_hash_controller();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    sign_hash_run($method);

    [$permission, $permissionCert] = sign_hash_controller();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $permission->permission = (object) ['post' => []];
    sign_hash_run($permission);

    [$anonymous, $anonymousCert] = sign_hash_controller(new SignHashCustomerStub(id: 0));
    sign_hash_run($anonymous);

    expect($method->statusCode)->toBe(405)
        ->and($method->json['error'])->toContain('method_not_allowed')
        ->and($permission->statusCode)->toBe(403)
        ->and($permission->json['error'])->toContain('access_denied')
        ->and($anonymous->statusCode)->toBe(401)
        ->and($anonymous->json['error'])->toContain('unauthenticated')
        ->and($methodCert->lookups)->toBe([])
        ->and($permissionCert->lookups)->toBe([])
        ->and($anonymousCert->lookups)->toBe([]);
});

/**
 * Prerequisites:
 * - Digest validation and TOTP enrollment are preconditions to certificate/SAM work.
 *
 * Steps:
 * 1. Submit missing hash, unsupported usage, missing TOTP, malformed base64, and wrong digest length.
 * 2. Use a valid TOTP only where digest decoding must be reached.
 * 3. Assert no certificate lookup occurs for malformed requests.
 */
test('Sign hash API — malformed digest requests are rejected before certificate and SAM', function (array $post, string $error) {
    [$controller, $certificate] = sign_hash_controller();
    $controller->testPost = $post;

    sign_hash_run($controller);

    expect($controller->statusCode)->toBe(422)
        ->and($controller->json['error'])->toContain($error)
        ->and($certificate->lookups)->toBe([]);
})->with([
    'missing hash' => [['usage' => 'document_signing'], 'hash_base64_required'],
    'unsupported usage' => [['usage' => 'sha1', 'hash_base64' => base64_encode(random_bytes(32))], 'usage_required'],
    'missing TOTP' => [['usage' => 'document_signing', 'hash_base64' => base64_encode(random_bytes(32))], 'totp_code_required'],
    'malformed base64' => [['usage' => 'document_signing', 'hash_base64' => 'not-base64!', 'totp_code' => sign_hash_totp()], 'hash_base64_invalid'],
    'wrong digest length' => [['usage' => 'document_signing', 'hash_base64' => base64_encode(random_bytes(31)), 'totp_code' => sign_hash_totp()], 'hash_digest_length_invalid'],
]);

/**
 * Prerequisites:
 * - A valid SHA-256 request reaches a customer-scoped certificate lookup.
 *
 * Steps:
 * 1. Return missing, revoked, future, and expired certificate fixtures.
 * 2. Assert lookup is scoped to the authenticated customer and requested usage.
 * 3. Assert every invalid certificate stops before provider signing.
 */
test('Sign hash API — certificate must be current and customer-scoped', function (?array $certificateRow, int $status, string $error) {
    [$controller, $certificate] = sign_hash_controller();
    $certificate->certificate = $certificateRow;
    $controller->testPost = [
        'usage' => ModelCertificateCertificate::USAGE_DOCUMENT_SIGNING,
        'hash_base64' => base64_encode(random_bytes(32)),
        'totp_code' => sign_hash_totp(),
    ];

    sign_hash_run($controller);

    expect($controller->statusCode)->toBe($status)
        ->and($controller->json['error'])->toContain($error)
        ->and($certificate->lookups)->toBe([[
            'customer_id' => 51,
            'usage' => ModelCertificateCertificate::USAGE_DOCUMENT_SIGNING,
        ]]);
})->with([
    'missing certificate' => [null, 404, 'certificate_not_found'],
    'revoked certificate' => [['cert_status' => CERT_REVOKED], 422, 'certificate_not_valid'],
    'not yet valid certificate' => [[
        'cert_status' => CERT_VALID,
        'not_before' => gmdate('Y-m-d H:i:s', time() + 86400),
    ], 422, 'certificate_not_yet_valid'],
    'expired certificate' => [[
        'cert_status' => CERT_VALID,
        'not_after' => gmdate('Y-m-d H:i:s', time() - 86400),
    ], 422, 'certificate_expired'],
]);

/**
 * Prerequisites:
 * - Caller telemetry and SAM provider prose are untrusted inputs.
 *
 * Steps:
 * 1. Sanitize an oversized caller claim containing an unknown key.
 * 2. Map known and unknown SAM messages to stable client codes.
 * 3. Assert unknown keys/prose are absent and retained fields are capped.
 */
test('Sign hash API — caller claims and SAM failures map to a sanitized contract', function () {
    [$controller] = sign_hash_controller();
    $sanitize = new ReflectionMethod(ControllerPublicAPIV1SigningSignhash::class, 'sanitizeCallerClaim');
    $map = new ReflectionMethod(ControllerPublicAPIV1SigningSignhash::class, 'signHashSamErrorToClientCode');
    $claim = $sanitize->invoke($controller, [
        'publisher_cn' => str_repeat('A', 700),
        'signature_status' => 'signed_valid',
        'raw_secret' => 'must-not-survive',
    ]);

    expect($claim)->toHaveKey('publisher_cn')
        ->and(strlen((string) $claim['publisher_cn']))->toBe(512)
        ->and($claim['signature_status'])->toBe('signed_valid')
        ->and($claim)->not->toHaveKey('raw_secret')
        ->and(json_encode($claim))->not->toContain('must-not-survive')
        ->and($map->invoke($controller, 'SAM signing configuration is incomplete: secret'))->toBe('sam_configuration_incomplete')
        ->and($map->invoke($controller, 'Unexpected SAM response: private provider body'))->toBe('sam_unexpected_response')
        ->and($map->invoke($controller, 'unknown raw provider prose'))->toBe('sam_signing_failed');
});
