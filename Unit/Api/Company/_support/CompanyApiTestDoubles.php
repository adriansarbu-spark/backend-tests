<?php

declare(strict_types=1);

if (! defined('PUBLIC_API')) {
    require_once dirname(__DIR__, 4) . '/tests_config.php';
}

require_once PUBLIC_API . 'company/representatives.php';
require_once PUBLIC_API . 'company/representative_requests.php';

if (! class_exists(ModelCompanyRepresentative::class, false)) {
    /** Minimal stub for static helpers used by representative-requests controller unit tests. */
    class ModelCompanyRepresentative
    {
        public const REQUEST_PENDING = 'pending';
        public const REQUEST_APPROVED = 'approved';
        public const REQUEST_REJECTED = 'rejected';
        public const REQUEST_CANCELLED = 'cancelled';

        public static function normalizeRequestListStatusFilter($status)
        {
            if ($status === null || trim((string) $status) === '') {
                return null;
            }
            $status = strtolower(trim((string) $status));
            if ($status === 'in_review') {
                return self::REQUEST_PENDING;
            }
            $allowed = [
                self::REQUEST_PENDING,
                self::REQUEST_APPROVED,
                self::REQUEST_REJECTED,
                self::REQUEST_CANCELLED,
            ];

            return in_array($status, $allowed, true) ? $status : false;
        }
    }
}

if (! class_exists(ModelLegalDocument::class, false)) {
    /** Minimal stub so {@see LegalDocumentAcceptance} can validate UUIDs without DB models. */
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
    }
}

if (!class_exists(CompanyApiCustomerStub::class)) {
    final class CompanyApiCustomerStub
    {
        public function __construct(
            private readonly int $customerId,
            private readonly int $companyId,
            private readonly int $roleId,
            private readonly ?string $totpSecret = null,
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
    }
}

if (!class_exists(CompanyRepresentativeModelStub::class)) {
    /**
     * Shared stub for {@see ModelCompanyRepresentative} routes used by company public API controllers.
     */
    final class CompanyRepresentativeModelStub
    {
        public bool $roleBelongs = true;

        public bool $isAdmin = false;

        /** @var array<string, mixed>|null when non-null, caller is an active company representative */
        public ?array $activeRepresentative = null;

        /** @var array<string, mixed> */
        public array $eligibilityData = ['eligible' => true];

        /** @var array<string, mixed> */
        public array $lastListRepresentativesArgs = [];

        /** @var array<string, mixed> */
        public array $listRepresentativesResult = [
            'items'    => [],
            'page'     => 1,
            'per_page' => 20,
            'total'    => 0,
        ];

        /** @var array<string, mixed> */
        public array $revokeRepresentativeResult = [];

        /** @var array<string, mixed>|null */
        public $requestByUuidResult = null;

        public bool $cancelRequestResult = true;

        /** @var array<string, mixed>|null */
        public $roleContextByUuid = null;

        public bool $isActiveRepresentativeReturn = false;

        /** @var array<string, mixed>|null */
        public $createRequestResult = null;

        /** @var array<string, mixed> */
        public array $lastListRequestsArgs = [];

        /** @var array<string, mixed> */
        public array $listRequestsResult = [
            'items'    => [],
            'page'     => 1,
            'per_page' => 20,
            'total'    => 0,
        ];

        public function roleBelongsToCompany(int $customerRoleId, int $companyId): bool
        {
            return $this->roleBelongs;
        }

        public function isCompanyAdminRole(int $customerRoleId, int $companyId): bool
        {
            return $this->isAdmin;
        }

        public function getActiveRepresentative(int $companyId, int $customerRoleId): ?array
        {
            return $this->activeRepresentative;
        }

        public function getEligibility(int $companyId, int $customerId, int $customerRoleId): array
        {
            return $this->eligibilityData;
        }

        public function listRepresentativesPaged(array $args): array
        {
            $this->lastListRepresentativesArgs = $args;

            return $this->listRepresentativesResult;
        }

        public function revokeRepresentative(string $representativeUuid, int $companyId, int $customerId, string $reason): array
        {
            return $this->revokeRepresentativeResult;
        }

        public function getRequestByUuidForCompany(
            string $requestUuid,
            int $companyId,
            int $customerId,
            int $customerRoleId,
            bool $isAdmin,
        ) {
            return $this->requestByUuidResult;
        }

        public function cancelRequest(
            string $requestUuid,
            int $companyId,
            int $customerId,
            int $customerRoleId,
            bool $isAdmin,
        ): bool {
            return $this->cancelRequestResult;
        }

        public function listRequestsPaged(array $args): array
        {
            $this->lastListRequestsArgs = $args;

            return $this->listRequestsResult;
        }

        public function getRoleContextByUuidForCompany(string $roleUuid, int $companyId): ?array
        {
            return $this->roleContextByUuid;
        }

        public function isActiveRepresentative(int $companyId, int $candidateCustomerRoleId): bool
        {
            return $this->isActiveRepresentativeReturn;
        }

        public function createRequest(
            int $companyId,
            int $customerId,
            int $customerRoleId,
            array $candidate,
            string $title,
            string $justification,
            array $documents,
        ) {
            return $this->createRequestResult;
        }
    }
}

if (!class_exists(CompanySealModelStub::class)) {
    final class CompanySealModelStub
    {
        /** @var array<string, mixed> */
        public array $lastListSealsArgs = [];

        /** @var array<string, mixed> */
        public array $listSealsResult = [
            'items'    => [],
            'page'     => 1,
            'per_page' => 20,
            'total'    => 0,
        ];

        /** @var array<string, mixed>|null|false */
        public $getSealDetailResult = null;

        /** @var array<string, mixed> */
        public array $requestSealResult = [];

        /** @var array<string, mixed> */
        public array $revokeSealResult = [];

        public function listSealsPaged(array $args): array
        {
            $this->lastListSealsArgs = $args;

            return $this->listSealsResult;
        }

        public function getSealDetailForApi(
            string $sealUuid,
            int $companyId,
            int $customerId,
            int $customerRoleId,
            bool $isAdmin,
            bool $isActiveRepresentative,
        ) {
            return $this->getSealDetailResult;
        }

        public function requestSealForRepresentative(
            int $companyId,
            int $customerId,
            int $customerRoleId,
            array $activeRepresentative,
            array $post,
        ): array {
            return $this->requestSealResult;
        }

        public function revokeSeal(
            string $sealUuid,
            int $companyId,
            array $activeRepresentative,
            int $revocationReasonId,
            string $reason,
        ): array {
            return $this->revokeSealResult;
        }

        public function getSealCertificatePemForDownload(
            string $sealUuid,
            int $companyId,
            int $customerId,
            int $customerRoleId,
            bool $adminView,
            bool $activeRepresentative,
        ) {
            return null;
        }
    }
}

if (!class_exists(CompanyApiConfigStub::class)) {
    final class CompanyApiConfigStub
    {
        /** @param array<string, mixed> $values */
        public function __construct(private array $values = [])
        {
        }

        public function get(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }
    }
}

if (!class_exists(LegalDocumentModelStub::class)) {
    final class LegalDocumentModelStub
    {
        /** @var array<int, array<string, mixed>> */
        public array $activeForFlowRows = [];

        public function getActiveForFlow(string $flow_code, int $language_id): array
        {
            return $this->activeForFlowRows;
        }
    }
}

if (!class_exists(CustomerLegalDocumentAcceptanceModelStub::class)) {
    final class CustomerLegalDocumentAcceptanceModelStub
    {
        /** @var list<array{customer_id: int, flow_code: string, documents: array}> */
        public array $addFlowAcceptancesCalls = [];

        public function addFlowAcceptances(int $customer_id, string $flow_code, array $documents, array $opts = []): void
        {
            $this->addFlowAcceptancesCalls[] = [
                'customer_id' => $customer_id,
                'flow_code'     => $flow_code,
                'documents'   => $documents,
            ];
        }
    }
}

/**
 * Active seal-issuance legal rows for unit tests (UUID-based acceptance).
 *
 * @return array<int, array<string, mixed>>
 */
function company_repr_seal_legal_documents(): array
{
    return [
        [
            'uuid'        => '11111111-1111-4111-8111-111111111101',
            'version'     => '1.0',
            'name'        => 'Terms',
            'upload_code' => 't1',
        ],
        [
            'uuid'        => '11111111-1111-4111-8111-111111111102',
            'version'     => '2.0',
            'name'        => 'Privacy',
            'upload_code' => '',
        ],
        [
            'uuid'        => '11111111-1111-4111-8111-111111111103',
            'version'     => '3.0',
            'name'        => 'Notice',
            'upload_code' => '',
        ],
    ];
}

/**
 * @return list<string>
 */
function company_repr_seal_legal_document_uuids(): array
{
    return array_map(
        static fn (array $row): string => (string) $row['uuid'],
        company_repr_seal_legal_documents(),
    );
}

if (!class_exists(ToolUploadModelStub::class)) {
    final class ToolUploadModelStub
    {
        /** @var array<string, bool> file_code => exists */
        public array $knownFileCodes = [];

        public function getShortLivedUrl(string $fileCode, int $lifetimeSeconds, string $mode): string
        {
            return 'https://signed.example/' . rawurlencode($fileCode);
        }

        public function getUploadByCode(string $fileCode)
        {
            return !empty($this->knownFileCodes[$fileCode]);
        }

        public function addUpload(
            string $name,
            string $filename,
            string $p3,
            string $p4,
            string $p5,
            int $p6,
            int $p7,
            int $p8,
            string $folder,
            $fileSize = null,
        ): array {
            return ['code' => 'uploaded-code', 'name' => $name];
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1CompanyRepresentatives::class)) {
    final class TestableControllerPublicAPIV1CompanyRepresentatives extends ControllerPublicAPIV1CompanyRepresentatives
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public int $getPostCalls = 0;

        public int $downloadSealCertificateCalls = 0;

        /** @var list<array<int, mixed>> */
        public array $downloadSealCertificateArgs = [];

        /**
         * Deterministic legal rows for seal request + POST /seals validation (matches versioned POST fields in tests).
         *
         * @var list<array{document_id: string, version: string, file_code: string, name: string, language: string}>
         */
        public array $resolvedSealDocuments = [
            ['document_id' => 'terms_and_conditions', 'version' => '1.0', 'file_code' => 't1', 'name' => 'Terms', 'language' => 'en'],
            ['document_id' => 'privacy_policy', 'version' => '2.0', 'file_code' => '', 'name' => 'Privacy', 'language' => 'en'],
            ['document_id' => 'privacy_notice', 'version' => '3.0', 'file_code' => '', 'name' => 'Notice', 'language' => 'en'],
        ];

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
            ++$this->getPostCalls;

            return $this->postPayload;
        }

        protected function resolveSealRequestDocumentsForLanguage()
        {
            return $this->resolvedSealDocuments;
        }

        protected function downloadSealCertificate(
            $company_id,
            $customer_id,
            $customer_role_id,
            $admin_view,
            $active_representative,
            $seal_uuid,
        ) {
            ++$this->downloadSealCertificateCalls;
            $this->downloadSealCertificateArgs[] = func_get_args();
            $this->statusCode = 200;
            $this->json['data'] = ['seal_uuid' => $seal_uuid, 'stub_download' => true];
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1CompanyRepresentativeRequests::class)) {
    final class TestableControllerPublicAPIV1CompanyRepresentativeRequests extends ControllerPublicAPIV1CompanyRepresentativeRequests
    {
        /** @var array<string, mixed> */
        private array $postPayload = [];

        public int $checkPluginCalls = 0;

        public int $sendResponseCalls = 0;

        public int $getPostCalls = 0;

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
            ++$this->getPostCalls;

            return $this->postPayload;
        }
    }
}

if (!class_exists(CompanyRepresentativesLoadStub::class)) {
    final class CompanyRepresentativesLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly CompanyRepresentativeModelStub $representative,
            private readonly ?CompanySealModelStub $seal = null,
            private readonly ?ToolUploadModelStub $upload = null,
            private readonly ?LegalDocumentModelStub $legalDocument = null,
            private readonly ?CustomerLegalDocumentAcceptanceModelStub $legalAcceptance = null,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'company/representative') {
                $this->registry->set('model_company_representative', $this->representative);
            } elseif ($route === 'company/seal') {
                $this->registry->set('model_company_seal', $this->seal ?? new CompanySealModelStub());
            } elseif ($route === 'tool/upload') {
                $this->registry->set('model_tool_upload', $this->upload ?? new ToolUploadModelStub());
            } elseif ($route === 'legal/document') {
                $legal = $this->legalDocument ?? new LegalDocumentModelStub();
                if ($legal->activeForFlowRows === []) {
                    $legal->activeForFlowRows = company_repr_seal_legal_documents();
                }
                $this->registry->set('model_legal_document', $legal);
            } elseif ($route === 'account/customer_legal_document_acceptance') {
                $this->registry->set(
                    'model_account_customer_legal_document_acceptance',
                    $this->legalAcceptance ?? new CustomerLegalDocumentAcceptanceModelStub(),
                );
            }
        }
    }
}

if (!class_exists(CompanyRepresentativeRequestsLoadStub::class)) {
    final class CompanyRepresentativeRequestsLoadStub
    {
        /** @var list<string> */
        public array $loadedModels = [];

        public function __construct(
            private readonly Registry $registry,
            private readonly CompanyRepresentativeModelStub $representative,
            private readonly ?ToolUploadModelStub $upload = null,
        ) {
        }

        public function model(string $route): void
        {
            $this->loadedModels[] = $route;
            if ($route === 'company/representative') {
                $this->registry->set('model_company_representative', $this->representative);
            } elseif ($route === 'tool/upload') {
                $this->registry->set('model_tool_upload', $this->upload ?? new ToolUploadModelStub());
            }
        }
    }
}

if (!class_exists(CompanyApiNoopResponse::class)) {
    final class CompanyApiNoopResponse
    {
        public function addHeader(string $header): void
        {
        }

        public function setOutput($output): void
        {
        }

        public function output(): void
        {
        }
    }
}

/**
 * @return array{0: Registry, 1: CompanyRepresentativesLoadStub}
 */
function company_repr_registry(
    CompanyApiCustomerStub $customer,
    CompanyRepresentativeModelStub $representative,
    ?CompanySealModelStub $seal = null,
    ?ToolUploadModelStub $upload = null,
    ?LegalDocumentModelStub $legalDocument = null,
    ?CustomerLegalDocumentAcceptanceModelStub $legalAcceptance = null,
): array {
    $registry = new Registry();
    $load = new CompanyRepresentativesLoadStub(
        $registry,
        $representative,
        $seal,
        $upload,
        $legalDocument,
        $legalAcceptance,
    );
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', new CompanyApiConfigStub([
        'config_language'    => 'en',
        'config_language_id' => 2,
    ]));
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);
    $registry->set('response', new CompanyApiNoopResponse());

    return [$registry, $load];
}

/**
 * @return array{0: Registry, 1: CompanyRepresentativeRequestsLoadStub}
 */
function company_req_registry(
    CompanyApiCustomerStub $customer,
    CompanyRepresentativeModelStub $representative,
    ?ToolUploadModelStub $upload = null,
): array {
    $registry = new Registry();
    $load = new CompanyRepresentativeRequestsLoadStub($registry, $representative, $upload);
    $registry->set('load', $load);
    $registry->set('customer', $customer);
    $registry->set('config', new CompanyApiConfigStub([
        'config_language'    => 'en',
        'config_language_id' => 2,
    ]));
    $registry->set('request', (object) [
        'get'    => [],
        'server' => [],
    ]);

    return [$registry, $load];
}
