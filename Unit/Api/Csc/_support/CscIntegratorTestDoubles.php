<?php

declare(strict_types=1);

/**
 * Integrator / hosted-enrollment helpers for CSC unit tests (tests folder only).
 */

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

require_once __DIR__ . '/CscApiTestDoubles.php';

if (! defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (! class_exists(Model::class, false)) {
    require_once DIR_SYSTEM . 'engine/model.php';
}

if (! class_exists(ModelCscEnrollmentSession::class, false)) {
    require_once DIR_APPLICATION . 'model/csc/enrollment_session.php';
}

// ---------------------------------------------------------------------------
// Strip-load helper (no production edits)
// ---------------------------------------------------------------------------

/**
 * @param list<string> $stripRequireSubstrings substrings matched against require_once lines
 */
function csc_require_file_with_stripped_requires(string $fullPath, array $stripRequireSubstrings, string $definesClass): void
{
    if (class_exists($definesClass, false)) {
        return;
    }

    $code = file_get_contents($fullPath);
    if ($code === false) {
        throw new RuntimeException('Unable to read ' . $fullPath);
    }

    foreach ($stripRequireSubstrings as $needle) {
        // Matches both `require_once(...)` (controllers) and the
        // unparenthesized `require_once DIR_SYSTEM . '...'` style used by
        // system library files.
        $code = preg_replace(
            '/^\s*require_once\s*\(?[^;]*' . preg_quote($needle, '/') . '[^;]*\)?;\s*$/m',
            '// stripped for unit tests: ' . $needle,
            $code,
        ) ?? $code;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'csc_lib_');
    if ($tmp === false) {
        throw new RuntimeException('tempnam failed');
    }
    file_put_contents($tmp, $code);
    require_once $tmp;
    @unlink($tmp);
}

/**
 * @param list<string> $stripRequireSubstrings substrings matched against require_once lines
 */
function csc_require_controller_with_stripped_libs(string $controllerRelativePath, array $stripRequireSubstrings, string $controllerClass): void
{
    csc_require_file_with_stripped_requires(PUBLIC_API . $controllerRelativePath, $stripRequireSubstrings, $controllerClass);
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

if (! class_exists(CscIntegratorAuthFake::class)) {
    final class CscIntegratorAuthFake
    {
        public bool $valid = true;

        public string $lastError = '';

        /** @var array<string, mixed> */
        public array $clientRow = [
            'csc_api_client_id'        => 11,
            'company_id'               => 88,
            'keycloak_client_id'       => 'sim_api_test',
            'default_redirect_timing'  => 'on_verified',
            'status'                   => 'active',
        ];

        public function getLastError(): string
        {
            return $this->lastError;
        }

        public function getClientRow(): ?array
        {
            return $this->valid ? $this->clientRow : null;
        }

        public function validateBearer($authHeader): bool
        {
            if (! $this->valid) {
                return false;
            }
            if (! is_string($authHeader) || ! str_starts_with($authHeader, 'Bearer ')) {
                $this->lastError = 'missing_bearer';

                return false;
            }

            return true;
        }
    }
}

if (! class_exists(CscCredentialIssuanceFake::class)) {
    final class CscCredentialIssuanceFake
    {
        /** @var array{ok: bool, error?: string, public?: array}|null */
        public ?array $result = [
            'ok'     => true,
            'public' => [
                'credential_id' => 'cred-1',
                'status'        => 'valid',
            ],
        ];

        /** @var list<array{client_id:int,enrollment_uuid:string,email:string}> */
        public array $calls = [];

        public function issueForEnrollment(int $client_id, string $enrollment_uuid, string $email = ''): array
        {
            $this->calls[] = compact('client_id', 'enrollment_uuid', 'email');

            return $this->result ?? ['ok' => false, 'error' => 'credential_issue_failed'];
        }
    }
}

if (! class_exists(CscEnrollmentEvents::class, false)) {
    /**
     * Test double replacing system/library/csc_enrollment_events.php.
     */
    final class CscEnrollmentEvents
    {
        /** @var list<array{event:string,session:array,extra:array}> */
        public static array $dispatches = [];

        public static function reset(): void
        {
            self::$dispatches = [];
        }

        public static function dispatch($registry, array $session, $event_type, array $extra = []): void
        {
            self::$dispatches[] = [
                'event'   => (string) $event_type,
                'session' => $session,
                'extra'   => $extra,
            ];
        }
    }
}

if (! class_exists(CscEnrollmentSessionModelStub::class)) {
    final class CscEnrollmentSessionModelStub
    {
        /** @var array<string, mixed>|null */
        public ?array $byToken = null;

        /** @var array<string, mixed>|null */
        public ?array $byUuidForClient = null;

        /** @var array<string, mixed>|null */
        public ?array $byUuid = null;

        /** @var array<string, mixed>|null */
        public ?array $inProgressForSigner = null;

        /** @var array<string, mixed>|null */
        public ?array $createdSession = null;

        public bool $createThrows = false;

        public int $createCalls = 0;

        public int $updateResumeCalls = 0;

        public int $updateScaCalls = 0;

        /** @var array<string, mixed>|null */
        public ?array $lastCreateData = null;

        public function getByEnrollmentToken(string $token): ?array
        {
            return $this->byToken;
        }

        public function getByToken(string $token): ?array
        {
            return $this->byToken;
        }

        public function isExpired(array $session): bool
        {
            if (! empty($session['__force_expired'])) {
                return true;
            }
            $exp = isset($session['expires_at']) ? strtotime((string) $session['expires_at']) : false;

            return $exp !== false && $exp < time();
        }

        public function getByUuidForClient(int $client_id, string $uuid): ?array
        {
            return $this->byUuidForClient;
        }

        public function getByUuid(string $uuid): ?array
        {
            return $this->byUuid ?? $this->createdSession ?? $this->inProgressForSigner;
        }

        public function getInProgressForSigner(int $signer_id): ?array
        {
            return $this->inProgressForSigner;
        }

        public function createSession(array $data)
        {
            ++$this->createCalls;
            $this->lastCreateData = $data;
            if ($this->createThrows) {
                return null;
            }
            $this->createdSession = array_merge([
                'csc_enrollment_session_id' => 501,
                'enrollment_uuid'           => 'enroll-uuid-1',
                'enrollment_token'          => 'token-abc',
                'state'                     => ModelCscEnrollmentSession::STATE_CREATED,
                'csc_api_client_id'         => (int) ($data['csc_api_client_id'] ?? 0),
                'expires_at'                => gmdate('Y-m-d H:i:s', time() + 3600),
                'created_at'                => gmdate('Y-m-d H:i:s'),
                'sca_methods_json'          => json_encode($data['sca_methods'] ?? ['sms']),
                'signer_email'              => (string) ($data['signer_email'] ?? ''),
                'redirect_uri'              => (string) ($data['redirect_uri'] ?? ''),
                'redirect_timing'           => (string) ($data['redirect_timing'] ?? 'on_verified'),
            ], $data);
            // ModelCscEnrollmentSession::scaMethodsFromRow() expects a comma string (as
            // stored in the DB), but $data['sca_methods'] arrives from the controller as
            // a list; normalize it here so callers reading the created row back don't
            // trip an "Array to string conversion" warning.
            if (isset($this->createdSession['sca_methods']) && is_array($this->createdSession['sca_methods'])) {
                $this->createdSession['sca_methods'] = implode(',', $this->createdSession['sca_methods']);
            }

            return $this->createdSession;
        }

        public function updateResumeParams(int $id, string $redirect_uri, string $timing, string $email, $id_flow): void
        {
            ++$this->updateResumeCalls;
            if ($this->inProgressForSigner !== null) {
                $this->inProgressForSigner['redirect_uri'] = $redirect_uri;
                $this->inProgressForSigner['redirect_timing'] = $timing;
                if ($email !== '') {
                    $this->inProgressForSigner['signer_email'] = $email;
                }
            }
        }

        public function updateScaMethodsOnResume(int $id, array $methods): void
        {
            ++$this->updateScaCalls;
        }

        public int $updateSignerPhoneCalls = 0;

        /** @var array{0: int, 1: string|null, 2: int|null}|null */
        public ?array $lastUpdateSignerPhoneArgs = null;

        public function updateSignerPhoneOnResume(int $id, ?string $signerPhone, ?int $locked): bool
        {
            ++$this->updateSignerPhoneCalls;
            $this->lastUpdateSignerPhoneArgs = [$id, $signerPhone, $locked];
            if ($this->inProgressForSigner !== null) {
                if ($signerPhone !== null && $signerPhone !== '') {
                    $this->inProgressForSigner['signer_phone'] = $signerPhone;
                }
                if ($locked !== null) {
                    $this->inProgressForSigner['signer_phone_locked'] = $locked;
                }
            }

            return true;
        }

        // -------------------------------------------------------------------
        // Hosted enrollment flow (enroll.php) mutators. Each mutates $byToken
        // in place (when set) so a subsequent getByToken() call in the
        // controller observes the update, mirroring the real DB round-trip.
        // -------------------------------------------------------------------

        public int $setScaMethodChosenCalls = 0;

        public ?string $lastScaMethodChosen = null;

        public function setScaMethodChosen($enrollment_session_id, $method): void
        {
            ++$this->setScaMethodChosenCalls;
            $this->lastScaMethodChosen = $method;
            if ($this->byToken !== null) {
                $this->byToken['sca_method_chosen'] = $method;
            }
        }

        public int $markTermsAcceptedCalls = 0;

        /** @var list<string> */
        public array $lastAcceptedUuids = [];

        public function markTermsAccepted($enrollment_session_id, array $accepted_uuids): void
        {
            ++$this->markTermsAcceptedCalls;
            $this->lastAcceptedUuids = $accepted_uuids;
            if ($this->byToken !== null) {
                $this->byToken['state'] = ModelCscEnrollmentSession::STATE_TERMS_ACCEPTED;
                $this->byToken['terms_accepted_at'] = gmdate('Y-m-d H:i:s');
                $this->byToken['accepted_legal_document_uuids_json'] = json_encode(array_values($accepted_uuids));
            }
        }

        public int $setVerificationIdCalls = 0;

        public ?string $lastVerificationId = null;

        public function setVerificationId($enrollment_session_id, $verification_id): void
        {
            ++$this->setVerificationIdCalls;
            $this->lastVerificationId = (string) $verification_id;
            if ($this->byToken !== null) {
                $this->byToken['verification_id'] = (string) $verification_id;
            }
        }

        public int $setSmsPendingCalls = 0;

        /** @var array{phone_e164?: string, code_hash?: string, expires_at?: string} */
        public array $lastSmsPending = [];

        public function setSmsPending($enrollment_session_id, $phone_e164, $code_hash, $expires_at): void
        {
            ++$this->setSmsPendingCalls;
            $this->lastSmsPending = compact('phone_e164', 'code_hash', 'expires_at');
            if ($this->byToken !== null) {
                $this->byToken['phone_e164'] = $phone_e164;
                $this->byToken['sms_code_hash'] = $code_hash;
                $this->byToken['sms_code_expires_at'] = $expires_at;
            }
        }

        public int $markSmsVerifiedCalls = 0;

        public function markSmsVerified($enrollment_session_id): void
        {
            ++$this->markSmsVerifiedCalls;
            if ($this->byToken !== null) {
                $this->byToken['state'] = ModelCscEnrollmentSession::STATE_SMS_VERIFIED;
                $this->byToken['phone_verified_at'] = gmdate('Y-m-d H:i:s');
                $this->byToken['sms_code_hash'] = null;
                $this->byToken['sms_code_expires_at'] = null;
            }
        }

        public int $setEmailPinPendingCalls = 0;

        /** @var array{pin_hash?: string, expires_at?: string} */
        public array $lastEmailPinPending = [];

        public function setEmailPinPending($enrollment_session_id, $pin_hash, $expires_at): void
        {
            ++$this->setEmailPinPendingCalls;
            $this->lastEmailPinPending = compact('pin_hash', 'expires_at');
            if ($this->byToken !== null) {
                $this->byToken['email_pin_hash'] = $pin_hash;
                $this->byToken['email_pin_expires_at'] = $expires_at;
            }
        }

        public bool $reserveEmailPinSendSlotResult = true;

        public int $reserveEmailPinSendSlotCalls = 0;

        public function reserveEmailPinSendSlot($enrollment_session_id, $rate_limit): bool
        {
            ++$this->reserveEmailPinSendSlotCalls;

            return $this->reserveEmailPinSendSlotResult;
        }

        public int $revertEmailPinSendSlotCalls = 0;

        public function revertEmailPinSendSlot($enrollment_session_id): void
        {
            ++$this->revertEmailPinSendSlotCalls;
        }

        public int $markEmailVerifiedCalls = 0;

        public function markEmailVerified($enrollment_session_id): void
        {
            ++$this->markEmailVerifiedCalls;
            if ($this->byToken !== null) {
                $this->byToken['email_verified_at'] = gmdate('Y-m-d H:i:s');
                $this->byToken['email_pin_hash'] = null;
                $this->byToken['email_pin_expires_at'] = null;
            }
        }

        public int $setTotpSecretPendingCalls = 0;

        public ?string $lastTotpSecretPending = null;

        public function setTotpSecretPending($enrollment_session_id, $secret): void
        {
            ++$this->setTotpSecretPendingCalls;
            $this->lastTotpSecretPending = $secret;
            if ($this->byToken !== null) {
                $this->byToken['totp_secret_pending'] = $secret;
            }
        }

        public int $markScaEstablishedCalls = 0;

        public function markScaEstablished($enrollment_session_id): void
        {
            ++$this->markScaEstablishedCalls;
            if ($this->byToken !== null) {
                $this->byToken['state'] = ModelCscEnrollmentSession::STATE_SMS_VERIFIED;
                $this->byToken['totp_secret_pending'] = null;
            }
        }

        public int $markRedirectedCalls = 0;

        public function markRedirected($enrollment_session_id): void
        {
            ++$this->markRedirectedCalls;
            if ($this->byToken !== null) {
                $this->byToken['redirected_at'] = gmdate('Y-m-d H:i:s');
            }
        }
    }
}

if (! class_exists(CscSignerModelStub::class)) {
    final class CscSignerModelStub
    {
        public int $signerId = 77;

        public int $activateSmsCalls = 0;

        public ?int $lastActivateSmsSignerId = null;

        public int $activateTotpCalls = 0;

        public ?int $lastActivateTotpSignerId = null;

        public ?string $lastActivateTotpSecret = null;

        public function upsertSigner(int $client_id, string $external_signer_id): array
        {
            return [$this->signerId, ['external_signer_id' => $external_signer_id]];
        }

        public function activateSms($csc_signer_id): void
        {
            ++$this->activateSmsCalls;
            $this->lastActivateSmsSignerId = (int) $csc_signer_id;
        }

        public function activateTotp($csc_signer_id, $secret): void
        {
            ++$this->activateTotpCalls;
            $this->lastActivateTotpSignerId = (int) $csc_signer_id;
            $this->lastActivateTotpSecret = (string) $secret;
        }
    }
}

if (! class_exists(CscRedirectUriModelStub::class)) {
    final class CscRedirectUriModelStub
    {
        public bool $uriExists = true;

        /** @var list<array<string, mixed>> */
        public array $uris = [];

        public function uriExistsForClient(int $client_id, string $uri): bool
        {
            return $this->uriExists;
        }

        public function getByCompanyId(int $company_id): array
        {
            return $this->uris;
        }

        public function listForClient(int $client_id): array
        {
            return $this->uris;
        }
    }
}

if (! class_exists(CscCredentialModelStub::class)) {
    final class CscCredentialModelStub
    {
        /** @var array<string, mixed>|null */
        public ?array $forEnrollment = null;

        public function getIssuedForEnrollment(int $session_id): ?array
        {
            return $this->forEnrollment;
        }

        public function getByEnrollmentSessionId(int $session_id): ?array
        {
            return $this->forEnrollment;
        }
    }
}

if (! class_exists(CscCheckinModelStub::class)) {
    final class CscCheckinModelStub
    {
        /** @var array<string, mixed>|null */
        public ?array $verification = null;

        public int $addVerificationCalls = 0;

        /** @var array<string, mixed>|null */
        public ?array $lastAddVerificationData = null;

        public string $nextVerificationId = 'verification-uuid-1';

        public function getVerificationByVerificationId(string $id): ?array
        {
            return $this->verification;
        }

        /**
         * @param array<string, mixed> $data
         * @return string generated verification_id
         */
        public function addVerification(array $data): string
        {
            ++$this->addVerificationCalls;
            $this->lastAddVerificationData = $data;

            return $this->nextVerificationId;
        }
    }
}

if (! class_exists(CscUploadSigningStub::class)) {
    final class CscUploadSigningStub
    {
        /** @var array<string, mixed>|null */
        public ?array $upload = null;

        public function getUploadByCodeForSigning(string $code): ?array
        {
            return $this->upload;
        }

        public function getShortLivedUrl(string $fileCode, int $lifetimeSeconds, string $mode): string
        {
            return 'https://signed.example/' . rawurlencode($fileCode);
        }
    }
}

/**
 * DB stub that resolves company_id for CscApiMetering::companyIdForClient.
 */
if (! class_exists(CscIntegratorDbStub::class)) {
    final class CscIntegratorDbStub
    {
        public int $companyIdForClient = 88;

        public function query(string $sql): object
        {
            if (stripos($sql, 'GET_LOCK') !== false) {
                return (object) [
                    'num_rows' => 1,
                    'row'      => ['l' => 1],
                    'rows'     => [],
                ];
            }
            if (stripos($sql, 'RELEASE_LOCK') !== false) {
                return (object) ['num_rows' => 1, 'row' => [], 'rows' => []];
            }
            if (stripos($sql, 'csc_api_client') !== false && stripos($sql, 'SELECT') !== false) {
                return (object) [
                    'num_rows' => 1,
                    'row'      => ['company_id' => $this->companyIdForClient],
                    'rows'     => [],
                ];
            }

            return (object) ['num_rows' => 0, 'row' => [], 'rows' => []];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function getLastId(): int
        {
            return 0;
        }
    }
}

// ---------------------------------------------------------------------------
// SMS attempt model stub (hosted enrollment send-sms cap checks)
// ---------------------------------------------------------------------------

if (! class_exists(CscSmsAttemptModelStub::class)) {
    final class CscSmsAttemptModelStub
    {
        public function countSentForContext($context, $context_id): int
        {
            return 0;
        }
    }
}

// ---------------------------------------------------------------------------
// Load stubs
// ---------------------------------------------------------------------------

if (! class_exists(CscIntegratorLoadStub::class)) {
    final class CscIntegratorLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly CscCompanyAccessModelStub $companyAccess,
            private readonly CscEntitlementGrantStub $grants,
            private readonly ?CscEnrollmentSessionModelStub $enrollment = null,
            private readonly ?CscSignerModelStub $signer = null,
            private readonly ?CscRedirectUriModelStub $redirect = null,
            private readonly ?CscCredentialModelStub $credential = null,
            private readonly ?CscCheckinModelStub $checkin = null,
            private readonly ?CscUploadSigningStub $upload = null,
            private readonly ?CscApiClientModelStub $apiClient = null,
            private readonly ?CscLegalDocumentModelStub $legalDocument = null,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            match ($route) {
                'csc/company_access' => $this->registry->set('model_csc_company_access', $this->companyAccess),
                'billing/entitlement_grant' => $this->registry->set('model_billing_entitlement_grant', $this->grants),
                'billing/entitlement_assignment' => $this->registry->set(
                    'model_billing_entitlement_assignment',
                    new CscEntitlementAssignmentStub(),
                ),
                'csc/enrollment_session' => $this->registry->set(
                    'model_csc_enrollment_session',
                    $this->enrollment ?? new CscEnrollmentSessionModelStub(),
                ),
                'csc/signer' => $this->registry->set('model_csc_signer', $this->signer ?? new CscSignerModelStub()),
                'csc/redirect_uri' => $this->registry->set(
                    'model_csc_redirect_uri',
                    $this->redirect ?? new CscRedirectUriModelStub(),
                ),
                'csc/credential' => $this->registry->set(
                    'model_csc_credential',
                    $this->credential ?? new CscCredentialModelStub(),
                ),
                'checkin/checkin' => $this->registry->set(
                    'model_checkin_checkin',
                    $this->checkin ?? new CscCheckinModelStub(),
                ),
                'tool/upload' => $this->registry->set(
                    'model_tool_upload',
                    $this->upload ?? new CscUploadSigningStub(),
                ),
                'csc/api_client' => $this->registry->set(
                    'model_csc_api_client',
                    $this->apiClient ?? new CscApiClientModelStub(),
                ),
                // Needed by LegalDocuments::forFlowInternal/forFlowPublic
                // (hosted enroll load + accept-terms). Default stub returns
                // no active documents for the flow.
                'legal/document' => $this->registry->set(
                    'model_legal_document',
                    $this->legalDocument ?? new CscLegalDocumentModelStub(),
                ),
                'csc/sms_attempt' => $this->registry->set(
                    'model_csc_sms_attempt',
                    new CscSmsAttemptModelStub(),
                ),
                default => null,
            };
        }
    }
}

/**
 * @return array{0: Registry, 1: CscIntegratorLoadStub, 2: CscCompanyAccessModelStub, 3: CscEntitlementGrantStub}
 */
function csc_integrator_registry(
    ?CscEnrollmentSessionModelStub $enrollment = null,
    ?CscSignerModelStub $signer = null,
    ?CscRedirectUriModelStub $redirect = null,
    ?CscCredentialModelStub $credential = null,
    ?CscCheckinModelStub $checkin = null,
    ?CscUploadSigningStub $upload = null,
    ?CscApiClientModelStub $apiClient = null,
    array $config = [],
    ?CscLegalDocumentModelStub $legalDocument = null,
): array {
    $access = new CscCompanyAccessModelStub();
    $access->masterEnabled = true;
    $access->companyAccess = [
        'access_state'  => ModelCscCompanyAccess::STATE_APPROVED,
        'metering_mode' => ModelCscCompanyAccess::METER_UNMETERED,
    ];
    $grants = new CscEntitlementGrantStub();
    $grants->activeTotals = [CscApiMetering::CODE_ACCESS => 1];
    $grants->remainingByCode = [
        CscApiMetering::CODE_ENROLLMENTS => 10,
        CscApiMetering::CODE_SIGNATURES  => 10,
        CscApiMetering::CODE_PACKS       => 10,
    ];

    $registry = new Registry();
    $load = new CscIntegratorLoadStub(
        $registry,
        $access,
        $grants,
        $enrollment,
        $signer,
        $redirect,
        $credential,
        $checkin,
        $upload,
        $apiClient,
        $legalDocument,
    );
    $registry->set('load', $load);
    $registry->set('config', new CscApiConfigStub(array_merge([
        'config_language'                 => 'en',
        'config_language_id'              => 2,
        'csc_enrollment_hosted_base_url'  => 'https://enroll.example.com/hosted/',
        'csc_redirect_uri_allow_localhost'=> false,
        'checkin_enroll_flows'            => ['ro-id-card-only', 'default'],
    ], $config)));
    $registry->set('request', (object) ['get' => [], 'server' => ['HTTP_AUTHORIZATION' => 'Bearer test.jwt']]);
    $registry->set('response', new CscApiNoopResponse());
    $registry->set('db', new CscIntegratorDbStub());
    $registry->set('customer', new CscApiCustomerStub(0, 0, 0));

    return [$registry, $load, $access, $grants];
}

function csc_install_integrator_auth(?CscIntegratorAuthFake $fake = null): CscIntegratorAuthFake
{
    if (! class_exists(CscIntegratorAuth::class, false)) {
        require_once __DIR__ . '/CscIntegratorAuth.php';
    }
    $fake ??= new CscIntegratorAuthFake();
    CscIntegratorAuth::$testDouble = $fake;

    return $fake;
}

function csc_install_credential_issuance(?CscCredentialIssuanceFake $fake = null): CscCredentialIssuanceFake
{
    if (! class_exists(CscCredentialIssuance::class, false)) {
        require_once __DIR__ . '/CscCredentialIssuance.php';
    }
    $fake ??= new CscCredentialIssuanceFake();
    CscCredentialIssuance::$testDouble = $fake;

    return $fake;
}

// Load controllers with auth/issuance doubles (once).
csc_install_integrator_auth(new CscIntegratorAuthFake());
csc_require_controller_with_stripped_libs(
    'csc/enrollments.php',
    ['csc_integrator_auth.php', 'csc_enrollment_events.php'],
    ControllerPublicAPIV1CscEnrollments::class,
);
csc_require_controller_with_stripped_libs(
    'csc/enrollment_photos.php',
    ['csc_integrator_auth.php'],
    ControllerPublicAPIV1CscEnrollmentPhotos::class,
);
csc_install_credential_issuance(new CscCredentialIssuanceFake());
csc_require_controller_with_stripped_libs(
    'csc/enrollments/credentials.php',
    ['csc_integrator_auth.php', 'csc_credential_issuance.php'],
    ControllerPublicAPIV1CscEnrollmentsCredentials::class,
);
require_once PUBLIC_API . 'csc/dev_pack.php';

// Keycloak double already may be loaded; enroll.php needs it stripped too.
if (! class_exists(CscKeycloakProvisioner::class, false)) {
    require_once __DIR__ . '/CscKeycloakProvisioner.php';
}
// enroll.php pulls in csc_enrollment_redirect.php, which itself does an
// unconditional require of the real csc_keycloak_provisioner.php — strip that
// transitive require too, or it redeclares CscKeycloakProvisioner (fatal)
// once the fake above has already defined it.
csc_require_file_with_stripped_requires(
    DIR_SYSTEM . 'library/csc_enrollment_redirect.php',
    ['csc_keycloak_provisioner.php'],
    CscEnrollmentRedirect::class,
);
if (! class_exists('ModelCscSmsAttempt', false)) {
    class ModelCscSmsAttempt
    {
        public const CONTEXT_ENROLLMENT = 'enrollment';

        public const CONTEXT_SIGNING = 'signing';

        public const PROVIDER_SMSALERT = 'smsalert';

        public const PROVIDER_WEB2SMS = 'web2sms';

        public const SEND_SENT = 'sent';

        public const SEND_FAILED = 'send_failed';
    }
}
csc_require_controller_with_stripped_libs(
    'csc/enroll.php',
    ['csc_keycloak_provisioner.php', 'csc_enrollment_redirect.php'],
    ControllerPublicAPIV1CscEnroll::class,
);

// ---------------------------------------------------------------------------
// Testable controllers
// ---------------------------------------------------------------------------

if (! class_exists(TestableControllerPublicAPIV1CscDevPack::class)) {
    final class TestableControllerPublicAPIV1CscDevPack extends ControllerPublicAPIV1CscDevPack
    {
        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public function checkPlugin(): void
        {
            ++$this->checkPluginCalls;
        }

        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1CscEnrollments::class)) {
    final class TestableControllerPublicAPIV1CscEnrollments extends ControllerPublicAPIV1CscEnrollments
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public ?CscIntegratorAuthFake $authFake = null;

        public int $sendResponseCalls = 0;

        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        public function checkPlugin(): void
        {
        }

        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        public function getPost(): array
        {
            return $this->postPayload;
        }

        protected function resolveAuthorizationHeaderRaw()
        {
            return 'Bearer test.jwt';
        }

        public function index()
        {
            $prev = CscIntegratorAuth::$testDouble;
            if ($this->authFake !== null) {
                CscIntegratorAuth::$testDouble = $this->authFake;
            }
            try {
                return parent::index();
            } finally {
                CscIntegratorAuth::$testDouble = $prev;
            }
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1CscEnrollmentPhotos::class)) {
    final class TestableControllerPublicAPIV1CscEnrollmentPhotos extends ControllerPublicAPIV1CscEnrollmentPhotos
    {
        public ?CscIntegratorAuthFake $authFake = null;

        public int $sendResponseCalls = 0;

        public function checkPlugin(): void
        {
        }

        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        protected function resolveAuthorizationHeaderRaw()
        {
            return 'Bearer test.jwt';
        }

        public function index()
        {
            $prev = CscIntegratorAuth::$testDouble;
            if ($this->authFake !== null) {
                CscIntegratorAuth::$testDouble = $this->authFake;
            }
            try {
                return parent::index();
            } finally {
                CscIntegratorAuth::$testDouble = $prev;
            }
        }

        /** Expose protected helper for unit tests. */
        public function exposeFirstUploadCodeFromJson($raw): string
        {
            return $this->firstUploadCodeFromJson($raw);
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1CscEnrollmentsCredentials::class)) {
    final class TestableControllerPublicAPIV1CscEnrollmentsCredentials extends ControllerPublicAPIV1CscEnrollmentsCredentials
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public ?CscIntegratorAuthFake $authFake = null;

        public ?CscCredentialIssuanceFake $issuerFake = null;

        public int $sendResponseCalls = 0;

        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        public function checkPlugin(): void
        {
        }

        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        public function getPost(): array
        {
            return $this->postPayload;
        }

        protected function resolveAuthorizationHeaderRaw()
        {
            return 'Bearer test.jwt';
        }

        public function index()
        {
            $prevAuth = CscIntegratorAuth::$testDouble;
            $prevIss = CscCredentialIssuance::$testDouble;
            if ($this->authFake !== null) {
                CscIntegratorAuth::$testDouble = $this->authFake;
            }
            if ($this->issuerFake !== null) {
                CscCredentialIssuance::$testDouble = $this->issuerFake;
            }
            try {
                return parent::index();
            } finally {
                CscIntegratorAuth::$testDouble = $prevAuth;
                CscCredentialIssuance::$testDouble = $prevIss;
            }
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1CscEnroll::class)) {
    final class TestableControllerPublicAPIV1CscEnroll extends ControllerPublicAPIV1CscEnroll
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $sendResponseCalls = 0;

        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        public function checkPlugin(): void
        {
        }

        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        public function getPost(): array
        {
            return $this->postPayload;
        }
    }
}

// ---------------------------------------------------------------------------
// Hosted enrollment (enroll.php) fixtures
// ---------------------------------------------------------------------------

/**
 * Minimal-but-complete enrollment session row shared by hosted-enrollment
 * tests. Override any field (e.g. 'state', 'sca_methods') per scenario.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function csc_hosted_enrollment_session(array $overrides = []): array
{
    return array_merge([
        'csc_enrollment_session_id' => 501,
        'enrollment_uuid'           => 'enroll-uuid-1',
        'enrollment_token'          => 'token-abc',
        'csc_api_client_id'         => 11,
        'csc_signer_id'             => 77,
        'external_signer_id'       => 'ext-1',
        'csc_signer_uuid'           => 'signer-uuid-1',
        'state'                     => ModelCscEnrollmentSession::STATE_CREATED,
        'redirect_uri'              => 'https://partner.example.com/cb',
        'redirect_timing'           => 'on_verified',
        'signer_email'              => 'signer@example.com',
        'sca_methods'               => 'sms,totp',
        'sca_method_chosen'         => null,
        'expires_at'                => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at'                => gmdate('Y-m-d H:i:s'),
    ], $overrides);
}

/**
 * Build a ready-to-call TestableControllerPublicAPIV1CscEnroll wired to an
 * enrollment session stub, routed at ?enrollment_token=...&action=....
 *
 * @param array<string, mixed>|null $session null = unknown token (getByToken returns null)
 * @return array{0: TestableControllerPublicAPIV1CscEnroll, 1: CscEnrollmentSessionModelStub, 2: CscSignerModelStub, 3: Registry}
 */
function csc_hosted_enrollment_controller(
    ?array $session,
    string $action = '',
    string $token = 'token-abc',
    ?CscSignerModelStub $signer = null,
    ?CscApiClientModelStub $apiClient = null,
    array $config = [],
): array {
    $enrollment = new CscEnrollmentSessionModelStub();
    $enrollment->byToken = $session;
    $signer ??= new CscSignerModelStub();
    [$registry] = csc_integrator_registry($enrollment, $signer, null, null, null, null, $apiClient, $config);

    $get = ['enrollment_token' => $token];
    if ($action !== '') {
        $get['action'] = $action;
    }
    $registry->get('request')->get = $get;

    $controller = new TestableControllerPublicAPIV1CscEnroll($registry);

    return [$controller, $enrollment, $signer, $registry];
}
