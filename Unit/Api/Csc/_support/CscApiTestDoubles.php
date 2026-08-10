<?php

declare(strict_types=1);

/**
 * Shared stubs / harness for CSC public API unit tests.
 *
 * Pattern mirrors tests/Unit/Api/Company/_support/CompanyApiTestDoubles.php:
 * Registry + Load stub + TestableController (checkPlugin/sendResponse/getPost overrides).
 */

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

if (! class_exists(Model::class, false)) {
    require_once DIR_SYSTEM . 'engine/model.php';
}

require_once DIR_APPLICATION . 'model/csc/company_access.php';
require_once PUBLIC_API . 'csc/access.php';
// ControllerPublicAPIV1CscApiClient is loaded later via csc_require_api_client_controller_with_keycloak_double()
// after CscKeycloakProvisionerFake exists (see below).

if (! class_exists(ModelLegalDocument::class, false)) {
    /** Minimal stub so LegalDocumentAcceptance can validate UUIDs without DB models. */
    class ModelLegalDocument
    {
        public static function isValidUuid($uuid): bool
        {
            $v = trim((string) $uuid);

            return $v !== '' && (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $v,
            );
        }

        public static function acceptanceContextForFlow(string $flowCode): array
        {
            return ['flow' => $flowCode];
        }
    }
}

// ---------------------------------------------------------------------------
// Customer / config / cache / response
// ---------------------------------------------------------------------------

if (! class_exists(CscApiCustomerStub::class)) {
    final class CscApiCustomerStub
    {
        public function __construct(
            private readonly int $customerId,
            private readonly int $companyId,
            private readonly int $roleId,
            private readonly ?string $totpSecret = null,
            private readonly string $email = 'admin@example.com',
            private readonly string $firstName = 'Ada',
            private readonly string $lastName = 'Admin',
            private readonly string $companyName = 'Acme CSC Co',
        ) {
        }

        public function getId(): int
        {
            return $this->customerId;
        }

        public function getCompanyId(): int
        {
            return $this->companyId;
        }

        public function getRoleId(): int
        {
            return $this->roleId;
        }

        public function getTotpSecret(): ?string
        {
            return $this->totpSecret;
        }

        public function getEmail(): string
        {
            return $this->email;
        }

        public function getFirstName(): string
        {
            return $this->firstName;
        }

        public function getLastName(): string
        {
            return $this->lastName;
        }

        public function getCompanyName(): string
        {
            return $this->companyName;
        }
    }
}

if (! class_exists(CscApiConfigStub::class)) {
    final class CscApiConfigStub
    {
        /** @param array<string, mixed> $values */
        public function __construct(private array $values = [])
        {
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }
    }
}

if (! class_exists(CscApiCacheStub::class)) {
    final class CscApiCacheStub
    {
        /** @var array<string, mixed> */
        public array $store = [];

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, mixed $value, int $ttl = 0): void
        {
            $this->store[$key] = $value;
        }

        public function delete(string $key): void
        {
            unset($this->store[$key]);
        }
    }
}

if (! class_exists(CscApiNoopResponse::class)) {
    final class CscApiNoopResponse
    {
        /** @var list<string> */
        public array $headers = [];

        public function addHeader(string $header): void
        {
            $this->headers[] = $header;
        }

        public function setOutput($output): void
        {
        }

        public function output(): void
        {
        }
    }
}

// ---------------------------------------------------------------------------
// Billing / legal stubs
// ---------------------------------------------------------------------------

if (! class_exists(CscEntitlementAssignmentStub::class)) {
    final class CscEntitlementAssignmentStub
    {
        public bool $isAdmin = true;

        public function isRoleAdminForCompany(int $customerRoleId, int $companyId): bool
        {
            return $this->isAdmin;
        }
    }
}

if (! class_exists(CscEntitlementGrantStub::class)) {
    final class CscEntitlementGrantStub
    {
        /** @var array<string, int> entitlement_code => qty */
        public array $activeTotals = [];

        /** @var array<string, list<array{expires_at?: string}>> */
        public array $bucketsByCode = [];

        /** @var array<string, int> */
        public array $remainingByCode = [];

        public function getActiveGrantTotalsByCompany(int $companyId): array
        {
            return $this->activeTotals;
        }

        public function getActiveGrantBucketsWithConsumption(int $companyId, string $code): array
        {
            return $this->bucketsByCode[$code] ?? [];
        }

        public function computeFifoAvailableRemaining(int $companyId, string $code): int
        {
            return (int) ($this->remainingByCode[$code] ?? 0);
        }
    }
}

if (! class_exists(CscLegalDocumentModelStub::class)) {
    final class CscLegalDocumentModelStub
    {
        /** @var array<int, array<string, mixed>> */
        public array $activeForFlowRows = [];

        public function getActiveForFlow(string $flow_code, int $language_id): array
        {
            return $this->activeForFlowRows;
        }
    }
}

if (! class_exists(CscCustomerLegalAcceptanceStub::class)) {
    final class CscCustomerLegalAcceptanceStub
    {
        /** @var list<array{customer_id: int, flow_code: string, documents: array}> */
        public array $addFlowAcceptancesCalls = [];

        public function addFlowAcceptances(int $customer_id, string $flow_code, array $documents, array $opts = []): void
        {
            $this->addFlowAcceptancesCalls[] = [
                'customer_id' => $customer_id,
                'flow_code'   => $flow_code,
                'documents'   => $documents,
            ];
        }
    }
}

if (! class_exists(CscAccountCompanyStub::class)) {
    final class CscAccountCompanyStub
    {
        public string $companyName = 'Acme CSC Co';

        public function getCompanyName(int $companyId): string
        {
            return $this->companyName;
        }
    }
}

if (! class_exists(CscToolUploadStub::class)) {
    final class CscToolUploadStub
    {
        public function getShortLivedUrl(string $fileCode, int $lifetimeSeconds, string $mode): string
        {
            return 'https://signed.example/' . rawurlencode($fileCode);
        }
    }
}

if (! defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (! class_exists(CscDbStub::class)) {
    /** Minimal DB stub so static helpers (service principal) do not fatal in unit tests. */
    final class CscDbStub
    {
        public function query(string $sql): object
        {
            // Pretend a service principal already exists so ensureForCompany short-circuits.
            if (stripos($sql, 'api_service') !== false && stripos($sql, 'SELECT') !== false) {
                return (object) [
                    'num_rows' => 1,
                    'row'      => [
                        'customer_id'      => 9001,
                        'customer_role_id' => 9002,
                    ],
                    'rows'     => [],
                ];
            }

            return (object) [
                'num_rows' => 0,
                'row'      => [],
                'rows'     => [],
            ];
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function getLastId(): int
        {
            return 0;
        }

        public function countAffected(): int
        {
            return 0;
        }
    }
}

// ---------------------------------------------------------------------------
// Company access model stub
// ---------------------------------------------------------------------------

if (! class_exists(CscCompanyAccessModelStub::class)) {
    final class CscCompanyAccessModelStub
    {
        public bool $masterEnabled = true;

        /** @var array<string, mixed>|null */
        public ?array $companyAccess = null;

        /** @var array<string, mixed>|null */
        public ?array $latestQuestionnaire = null;

        /** @var list<array<string, mixed>> */
        public array $evidence = [];

        public int $nextEvidenceId = 101;

        public int $submitRequestReturnId = 55;

        /** @var array<string, mixed>|null last submitRequest args */
        public ?array $lastSubmitRequest = null;

        public int $submitRequestCalls = 0;

        /** @var array<string, string> */
        public array $settings = [];

        public function statesAllowingRequest(): array
        {
            return [
                ModelCscCompanyAccess::STATE_NONE,
                ModelCscCompanyAccess::STATE_REJECTED,
                ModelCscCompanyAccess::STATE_NEEDS_MORE_INFO,
            ];
        }

        public function isMasterEnabled(): bool
        {
            return $this->masterEnabled;
        }

        public function getSettingValue(string $key, string $default = ''): string
        {
            return array_key_exists($key, $this->settings)
                ? (string) $this->settings[$key]
                : (string) $default;
        }

        public function computeEffectiveEnabled(array $row): bool
        {
            if (! $this->isMasterEnabled()) {
                return false;
            }
            $override = isset($row['admin_override'])
                ? (string) $row['admin_override']
                : ModelCscCompanyAccess::OVERRIDE_DEFAULT;
            if ($override === ModelCscCompanyAccess::OVERRIDE_FORCE_DISABLED) {
                return false;
            }
            if ($override === ModelCscCompanyAccess::OVERRIDE_FORCE_ENABLED) {
                return true;
            }

            return isset($row['access_state'])
                && (string) $row['access_state'] === ModelCscCompanyAccess::STATE_APPROVED;
        }

        public function getCompanyAccess(int $company_id): ?array
        {
            return $this->companyAccess;
        }

        public function getLatestQuestionnaire(int $company_id): ?array
        {
            return $this->latestQuestionnaire;
        }

        public function getEvidence(int $company_id): array
        {
            return $this->evidence;
        }

        public function getEvidenceCount(int $company_id): int
        {
            return count($this->evidence);
        }

        public function getEvidenceById(int $company_id, int $evidence_id): ?array
        {
            foreach ($this->evidence as $row) {
                if ((int) ($row['csc_access_evidence_id'] ?? 0) === $evidence_id) {
                    return $row;
                }
            }

            return null;
        }

        public function deleteEvidence(int $company_id, int $evidence_id): void
        {
            $this->evidence = array_values(array_filter(
                $this->evidence,
                static fn (array $row): bool => (int) ($row['csc_access_evidence_id'] ?? 0) !== $evidence_id,
            ));
        }

        public function addEvidence(
            int $company_id,
            int $questionnaire_id,
            string $original_filename,
            string $stored_path,
            string $mime_type,
            int $size_bytes,
            string $sha256,
            int $customer_id,
        ): int {
            $id = $this->nextEvidenceId++;
            $this->evidence[] = [
                'csc_access_evidence_id' => $id,
                'company_id'             => $company_id,
                'questionnaire_id'       => $questionnaire_id,
                'original_filename'      => $original_filename,
                'stored_path'            => $stored_path,
                'mime_type'              => $mime_type,
                'size_bytes'             => $size_bytes,
                'sha256'                 => $sha256,
                'uploaded_by_customer_id'=> $customer_id,
                'created_at'             => '2026-01-15 10:00:00',
            ];

            return $id;
        }

        public function submitRequest(int $company_id, int $customer_id, array $answers, array $meta): int
        {
            ++$this->submitRequestCalls;
            $this->lastSubmitRequest = [
                'company_id'  => $company_id,
                'customer_id' => $customer_id,
                'answers'     => $answers,
                'meta'        => $meta,
            ];
            $this->companyAccess = array_merge([
                'metering_mode' => ModelCscCompanyAccess::METER_ENFORCE,
            ], $this->companyAccess ?? [], [
                'access_state' => ModelCscCompanyAccess::STATE_REQUESTED,
                'requested_at' => '2026-01-15 12:00:00',
                'dpa_version'  => (string) ($meta['dpa_version'] ?? ''),
            ]);
            $this->latestQuestionnaire = [
                'csc_security_questionnaire_id' => $this->submitRequestReturnId,
                'schema_version'                => (string) ($meta['schema_version'] ?? ''),
                'answers_decoded'               => $answers,
                'created_at'                    => '2026-01-15 12:00:00',
            ];

            return $this->submitRequestReturnId;
        }
    }
}

// ---------------------------------------------------------------------------
// API client model stub
// ---------------------------------------------------------------------------

if (! class_exists(CscApiClientModelStub::class)) {
    final class CscApiClientModelStub
    {
        /** @var array<string, mixed>|null */
        public ?array $byCompany = null;

        /** @var array<string, mixed>|null used by hosted-enrollment status/redirect-ack (getByInternalId) */
        public ?array $byInternalId = null;

        /** @var array<string, mixed> */
        public array $publicOverview = ['provisioned' => false];

        /** @var array<string, mixed>|null */
        public ?array $lastInsert = null;

        public bool $insertThrows = false;

        public int $updateNameCalls = 0;

        public int $updateStatusCalls = 0;

        public ?string $lastUpdatedName = null;

        public ?string $lastUpdatedStatus = null;

        public function getByCompanyId(int $company_id): ?array
        {
            return $this->byCompany;
        }

        public function getByInternalId(int $csc_api_client_id): ?array
        {
            return $this->byInternalId;
        }

        public function getPublicOverviewForCompany(int $company_id): array
        {
            return $this->publicOverview;
        }

        public function insertClient(array $data): void
        {
            if ($this->insertThrows) {
                throw new Exception('persist failed');
            }
            $this->lastInsert = $data;
            $this->byCompany = array_merge($data, [
                'status'               => 'active',
                'keycloak_client_uuid' => (string) ($data['keycloak_client_uuid'] ?? 'kc-uuid'),
                'keycloak_client_id'   => (string) ($data['keycloak_client_id'] ?? 'sim_api_x'),
                'api_client_uuid'      => (string) ($data['api_client_uuid'] ?? ''),
                'name'                 => (string) ($data['name'] ?? ''),
            ]);
            $this->publicOverview = [
                'provisioned'     => true,
                'api_client_uuid' => $this->byCompany['api_client_uuid'],
                'oauth_client_id' => $this->byCompany['keycloak_client_id'],
                'name'            => $this->byCompany['name'],
                'status'          => 'active',
            ];
        }

        public function updateName(int $company_id, string $name): void
        {
            ++$this->updateNameCalls;
            $this->lastUpdatedName = $name;
            if ($this->byCompany !== null) {
                $this->byCompany['name'] = $name;
            }
            $this->publicOverview['name'] = $name;
            $this->publicOverview['provisioned'] = true;
        }

        public function updateStatus(int $company_id, string $status): void
        {
            ++$this->updateStatusCalls;
            $this->lastUpdatedStatus = $status;
            if ($this->byCompany !== null) {
                $this->byCompany['status'] = $status;
            }
            $this->publicOverview['status'] = $status;
            $this->publicOverview['provisioned'] = true;
        }
    }
}

if (! class_exists(CscKeycloakProvisionerFake::class)) {
    final class CscKeycloakProvisionerFake
    {
        public bool $configured = true;

        /** @var array{keycloak_client_uuid: string, oauth_client_id: string, client_secret: string}|null */
        public ?array $createResult = [
            'keycloak_client_uuid' => 'kc-uuid-1',
            'oauth_client_id'      => 'sim_api_testclient',
            'client_secret'        => 'secret-once',
        ];

        public bool $rollbackCalled = false;

        public ?string $rollbackUuid = null;

        /** @var list<array{uuid: string, enabled: bool}> */
        public array $setEnabledCalls = [];

        public bool $credentialsCapable = true;

        public ?string $rotateSecretResult = 'rotated-secret';

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function createIntegratorClient($api_client_uuid, $company_id, $display_name): ?array
        {
            return $this->createResult;
        }

        public function rollbackClient(string $keycloak_client_uuid): void
        {
            $this->rollbackCalled = true;
            $this->rollbackUuid = $keycloak_client_uuid;
        }

        public function setClientEnabled(string $keycloak_client_uuid, bool $enabled): bool
        {
            $this->setEnabledCalls[] = ['uuid' => $keycloak_client_uuid, 'enabled' => $enabled];

            return true;
        }

        public function ensureIntegratorClientCredentialsCapable(string $keycloak_client_uuid): bool
        {
            return $this->credentialsCapable;
        }

        public function rotateClientSecret(string $keycloak_client_uuid): ?string
        {
            return $this->rotateSecretResult;
        }
    }
}

/**
 * Load production api_client.php with its Keycloak require stripped so the test-only
 * {@see CscKeycloakProvisioner} double is used. Production files are never modified.
 */
function csc_require_api_client_controller_with_keycloak_double(): void
{
    if (class_exists(ControllerPublicAPIV1CscApiClient::class, false)) {
        return;
    }

    if (! class_exists(CscKeycloakProvisionerFake::class, false)) {
        throw new RuntimeException('CscKeycloakProvisionerFake must be defined before loading api_client');
    }

    if (! class_exists(CscKeycloakProvisioner::class, false)) {
        require_once __DIR__ . '/CscKeycloakProvisioner.php';
    }

    $path = PUBLIC_API . 'csc/api_client.php';
    $code = file_get_contents($path);
    if ($code === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    $code = str_replace(
        "require_once(DIR_SYSTEM . 'library/csc_keycloak_provisioner.php');",
        '// skipped in unit tests — CscKeycloakProvisioner test double already loaded',
        $code,
    );

    $tmp = tempnam(sys_get_temp_dir(), 'csc_api_client_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file for api_client unit load');
    }
    file_put_contents($tmp, $code);
    require_once $tmp;
    @unlink($tmp);
}

csc_require_api_client_controller_with_keycloak_double();

// ---------------------------------------------------------------------------
// Load stubs + registry helpers
// ---------------------------------------------------------------------------

if (! class_exists(CscAccessLoadStub::class)) {
    final class CscAccessLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly CscCompanyAccessModelStub $companyAccess,
            private readonly CscEntitlementAssignmentStub $entitlementAssignment,
            private readonly CscEntitlementGrantStub $entitlementGrant,
            private readonly ?CscLegalDocumentModelStub $legalDocument = null,
            private readonly ?CscCustomerLegalAcceptanceStub $legalAcceptance = null,
            private readonly ?CscToolUploadStub $upload = null,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'csc/company_access') {
                $this->registry->set('model_csc_company_access', $this->companyAccess);
            } elseif ($route === 'billing/entitlement_assignment') {
                $this->registry->set('model_billing_entitlement_assignment', $this->entitlementAssignment);
            } elseif ($route === 'billing/entitlement_grant') {
                $this->registry->set('model_billing_entitlement_grant', $this->entitlementGrant);
            } elseif ($route === 'legal/document') {
                $this->registry->set(
                    'model_legal_document',
                    $this->legalDocument ?? new CscLegalDocumentModelStub(),
                );
            } elseif ($route === 'account/customer_legal_document_acceptance') {
                $this->registry->set(
                    'model_account_customer_legal_document_acceptance',
                    $this->legalAcceptance ?? new CscCustomerLegalAcceptanceStub(),
                );
            } elseif ($route === 'tool/upload') {
                $this->registry->set('model_tool_upload', $this->upload ?? new CscToolUploadStub());
            }
        }
    }
}

if (! class_exists(CscApiClientLoadStub::class)) {
    final class CscApiClientLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly CscApiClientModelStub $apiClient,
            private readonly CscEntitlementAssignmentStub $entitlementAssignment,
            private readonly ?CscAccountCompanyStub $company = null,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'csc/api_client') {
                $this->registry->set('model_csc_api_client', $this->apiClient);
            } elseif ($route === 'billing/entitlement_assignment') {
                $this->registry->set('model_billing_entitlement_assignment', $this->entitlementAssignment);
            } elseif ($route === 'account/company') {
                $this->registry->set('model_account_company', $this->company ?? new CscAccountCompanyStub());
            }
        }
    }
}

/**
 * @return array{0: Registry, 1: CscAccessLoadStub, 2: CscCompanyAccessModelStub, 3: CscEntitlementGrantStub}
 */
function csc_access_registry(
    CscApiCustomerStub $customer,
    ?CscCompanyAccessModelStub $companyAccess = null,
    ?CscEntitlementAssignmentStub $entitlementAssignment = null,
    ?CscEntitlementGrantStub $entitlementGrant = null,
    ?CscLegalDocumentModelStub $legalDocument = null,
    ?CscCustomerLegalAcceptanceStub $legalAcceptance = null,
    ?CscApiCacheStub $cache = null,
): array {
    $companyAccess ??= new CscCompanyAccessModelStub();
    $entitlementAssignment ??= new CscEntitlementAssignmentStub();
    $entitlementGrant ??= new CscEntitlementGrantStub();

    $registry = new Registry();
    $load = new CscAccessLoadStub(
        $registry,
        $companyAccess,
        $entitlementAssignment,
        $entitlementGrant,
        $legalDocument,
        $legalAcceptance,
    );
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', new CscApiConfigStub([
        'config_language'    => 'en',
        'config_language_id' => 2,
        'config_email'       => 'ops@example.com',
    ]));
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);
    $registry->set('response', new CscApiNoopResponse());
    if ($cache !== null) {
        $registry->set('cache', $cache);
    }

    return [$registry, $load, $companyAccess, $entitlementGrant];
}

/**
 * @return array{0: Registry, 1: CscApiClientLoadStub, 2: CscApiClientModelStub}
 */
function csc_api_client_registry(
    CscApiCustomerStub $customer,
    ?CscApiClientModelStub $apiClient = null,
    ?CscEntitlementAssignmentStub $entitlementAssignment = null,
    ?CscAccountCompanyStub $company = null,
    ?CscApiCacheStub $cache = null,
): array {
    $apiClient ??= new CscApiClientModelStub();
    $entitlementAssignment ??= new CscEntitlementAssignmentStub();

    $registry = new Registry();
    $load = new CscApiClientLoadStub($registry, $apiClient, $entitlementAssignment, $company);
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', new CscApiConfigStub([
        'config_language'    => 'en',
        'config_language_id' => 2,
    ]));
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);
    $registry->set('response', new CscApiNoopResponse());
    if ($cache !== null) {
        $registry->set('cache', $cache);
    }
    $registry->set('db', new CscDbStub());

    return [$registry, $load, $apiClient];
}

/**
 * Default route permissions for CSC access controller endpoints.
 *
 * @return object{get: list<string>, post: list<string>, delete: list<string>, patch: list<string>}
 */
function csc_access_permissions(): object
{
    return (object) [
        'get'    => [
            ControllerPublicAPIV1CscAccess::PATH_STATUS,
            ControllerPublicAPIV1CscAccess::PATH_QUESTIONNAIRE,
        ],
        'post'   => [
            ControllerPublicAPIV1CscAccess::PATH_REQUEST,
            ControllerPublicAPIV1CscAccess::PATH_EVIDENCE,
        ],
        'delete' => [
            ControllerPublicAPIV1CscAccess::PATH_EVIDENCE,
        ],
        'patch'  => [],
    ];
}

/**
 * @return object{get: list<string>, post: list<string>, patch: list<string>, delete: list<string>}
 */
function csc_api_client_permissions(bool $includeRotate = true): object
{
    $post = [ControllerPublicAPIV1CscApiClient::PERMISSION_PATH];
    if ($includeRotate) {
        $post[] = ControllerPublicAPIV1CscApiClient::PERMISSION_ROTATE;
    }

    return (object) [
        'get'    => [ControllerPublicAPIV1CscApiClient::PERMISSION_PATH],
        'post'   => $post,
        'patch'  => [ControllerPublicAPIV1CscApiClient::PERMISSION_PATH],
        'delete' => [],
    ];
}

/**
 * Minimal questionnaire answers that pass CscSecurityQuestionnaire::validate (ISO shortcut).
 *
 * @return array<string, mixed>
 */
function csc_minimal_valid_answers(): array
{
    return [
        'has_iso27001'           => true,
        'has_soc2'               => false,
        'end_user_auth_binding'  => 'App authenticates users via SSO and binds enrollments by subject id.',
        'data_residency'         => 'eu_eea',
    ];
}

/**
 * Sample DPA legal document rows for acceptance tests.
 *
 * @return array<int, array<string, mixed>>
 */
function csc_dpa_legal_documents(): array
{
    return [
        [
            'uuid'        => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
            'version'     => '1.0',
            'name'        => 'CSC API DPA',
            'upload_code' => 'dpa1',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Testable controllers
// ---------------------------------------------------------------------------

if (! class_exists(TestableControllerPublicAPIV1CscAccess::class)) {
    final class TestableControllerPublicAPIV1CscAccess extends ControllerPublicAPIV1CscAccess
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        /** @var array<string, mixed> */
        private array $filesPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        /** @param array<string, mixed> $payload */
        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        /** @param array<string, mixed> $files */
        public function setFilesPayload(array $files): void
        {
            $this->filesPayload = $files;
        }

        public function checkPlugin(): void
        {
            ++$this->checkPluginCalls;
        }

        /** @return null */
        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        /** @return array<string, mixed> */
        public function getPost(): array
        {
            return $this->postPayload;
        }

        public function getFiles(): void
        {
            $this->files = $this->filesPayload;
        }
    }
}

if (! class_exists(TestableControllerPublicAPIV1CscApiClient::class)) {
    final class TestableControllerPublicAPIV1CscApiClient extends ControllerPublicAPIV1CscApiClient
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public ?CscKeycloakProvisionerFake $keycloakFake = null;

        /** @var string|null empty string = pass; non-empty = error code; null = use parent */
        public ?string $totpOverride = null;

        /** @param array<string, mixed> $payload */
        public function setPostPayload(array $payload): void
        {
            $this->postPayload = $payload;
        }

        public function checkPlugin(): void
        {
            ++$this->checkPluginCalls;
        }

        /** @return null */
        public function sendResponse()
        {
            ++$this->sendResponseCalls;

            return null;
        }

        /** @return array<string, mixed> */
        public function getPost(): array
        {
            return $this->postPayload;
        }

        /**
         * Install the Keycloak test double for the duration of the real controller logic.
         * Production still constructs `new CscKeycloakProvisioner($registry)` — the class
         * name resolves to the test double loaded by csc_require_api_client_controller_with_keycloak_double().
         */
        public function index()
        {
            $previous = CscKeycloakProvisioner::$testDouble;
            CscKeycloakProvisioner::$testDouble = $this->keycloakFake;
            try {
                return parent::index();
            } finally {
                CscKeycloakProvisioner::$testDouble = $previous;
            }
        }

        /** Override TOTP when tests need success without RobThree. */
        protected function requireTotp(array $post, $context, array $audit_extra = [])
        {
            if (is_string($this->totpOverride)) {
                return $this->totpOverride === '' ? null : $this->totpOverride;
            }

            return parent::requireTotp($post, $context, $audit_extra);
        }
    }
}
