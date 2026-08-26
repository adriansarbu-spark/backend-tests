<?php

declare(strict_types=1);

if (!class_exists('Request', false)) {
    class Request {
        public function clean($data) { return $data; }
    }
}

if (!class_exists('Request', false)) {
    class Request {
        public function clean($data) { return $data; }
    }
}

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists('ModelSigningSigner', false)) {
    class ModelSigningSigner {
        public const INVITATION_STANDARD = 'standard_invitation';
        public const INVITATION_GUEST = 'guest_invitation';
        public const INVITATION_SPONSORED = 'sponsored_invitation';
        public const KIND_QUALIFIED = 'qualified';
        public const KIND_SIMPLE = 'simple';

        public static function invitationTypeFromRow(array $row): string
        {
            $type = isset($row['invitation_type']) ? trim((string)$row['invitation_type']) : '';
            if ($type === self::INVITATION_GUEST || $type === self::INVITATION_SPONSORED) {
                return $type;
            }
            if (!empty($row['uses_prepaid_invite'])) {
                return self::INVITATION_GUEST;
            }
            return self::INVITATION_STANDARD;
        }

        public static function isGuestInvitation(array $row): bool
        {
            return self::invitationTypeFromRow($row) === self::INVITATION_GUEST;
        }

        public static function isSponsoredInvitation(array $row): bool
        {
            return self::invitationTypeFromRow($row) === self::INVITATION_SPONSORED;
        }

        public static function isValidInvitationType($type): bool
        {
            return in_array($type, [self::INVITATION_STANDARD, self::INVITATION_GUEST, self::INVITATION_SPONSORED], true);
        }

        public static function isValidSignatureKind($kind): bool
        {
            return in_array($kind, [self::KIND_QUALIFIED, self::KIND_SIMPLE], true);
        }

        public static function isSimpleSignatureKind(array $row): bool
        {
            return isset($row['signature_kind']) && trim((string)$row['signature_kind']) === self::KIND_SIMPLE;
        }
    }
}

// Pest runs all unit tests in one process, so this stub may also serve the Signing
// tests (or vice versa). Keep it a superset identical to the one in
// tests/Unit/Api/Signing/_support/SigningTestDoubles.php.
if (!class_exists('ModelSigningAuditEvent', false)) {
    class ModelSigningAuditEvent {
        public const EVENT_SENT = 'sent';
        public const EVENT_VIEWED = 'viewed';
        public const EVENT_CONSENT_ACCEPTED = 'consent_accepted';
        public const EVENT_SIGNED = 'signed';
        public const EVENT_REJECTED = 'rejected';
        public const EVENT_COMPLETED = 'completed';

        public array $records = [];

        public function record($document_id, $document_signer_id, $event_type, array $meta = []): void
        {
            $this->records[] = [
                'document_id'        => (int)$document_id,
                'document_signer_id' => $document_signer_id !== null ? (int)$document_signer_id : null,
                'event_type'         => (string)$event_type,
                'meta'               => $meta,
            ];
        }

        public function isCertificateAvailable(array $document, bool $hasEvents = false): bool
        {
            return false;
        }

        public function documentIdsWithEvents(array $ids): array
        {
            return [];
        }

        /**
         * @param string|null $cert_pem
         * @return array<string,string>
         */
        public static function certificateEvidenceFromPem($cert_pem): array
        {
            // Unit fixtures use placeholder PEM strings; skip openssl parsing.
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// Test model doubles for documents controller
// ---------------------------------------------------------------------------

if (!class_exists(TestDocumentsDocumentModel::class)) {
    class TestDocumentsDocumentModel {
        public function getDocumentByUuid($uuid) {}
        public function getDocumentById($id) {}
        public function createDocument($data) {}
        public function updateDocumentDraft($id, $patch) {}
        public function updateAnnotations($id, $annotations) {}
        public function cancelDocument($id) {}
        public function sendDocument($id, $lockToken) {}
        public function completeDocument($id) {}
        public function softDeleteDocument($id) {}
        public function detachDocumentFromGroup($id, $newGroupUuid) {}
        public function listDocuments($params) { return ['documents' => [], 'total' => 0]; }
        public function listRecipientCandidates($params) { return ['recipients' => [], 'total' => 0]; }
        public function getDocumentsByGroupUuid($uuid) {}
        public function countGroupMembers($uuid) {}
        public function isGroupLocked($document) { return false; }
        public function isGroupFullySent($uuid) { return false; }
        public function isPersonalOwned($document) { return empty($document['owner_company_id']); }
        public function setGroupSentAt($uuid) {}
        public function validateGroupJoin($uuid, $roleId, $companyId, $ownerRoleId) { return ['ok' => true]; }
        public function validatePackInvitationRules($uuid) { return ['ok' => true]; }
        public function roleCanOwnCompanyDocuments($roleId, $companyId) { return true; }
        public function isPackComparableSignerRow($s) { return true; }
        public function getGuestPackPrimaryDocumentUuidByEmail($uuid) { return []; }
        public function canActAsDocumentOwner($customerRoleId, array $document): bool {
            return (int)$customerRoleId > 0
                && isset($document['owner_customer_role_id'])
                && (int)$customerRoleId === (int)$document['owner_customer_role_id'];
        }
        public function buildDocumentAccessiblePredicateSql($roleId, $email) { return '1=1'; }
        public function buildEffectiveDocumentOwnerWithEnvelopeSql($roleId, $ownership) { return '1=1'; }
        public function buildSignerRowMatchSql($roleId, $email, $claimSql) { return '1=1'; }
    }
}

if (!class_exists(TestDocumentsSignerModel::class)) {
    class TestDocumentsSignerModel {
        public function getSignersByDocumentId($documentId, $includeDraft = false) { return []; }
        public function getSignerByDocumentAndEmail($documentId, $email) {}
        public function addSigner($documentId, $data) {}
        public function deleteAllSigners($documentId) { return 0; }
        public function inviteGroup($documentId, $signingOrder, $parallelGroup) {}
        public function areAllSignersSigned($documentId) { return false; }
        public function markNoActionSignersComplete($documentId) {}
        public function getSignerStatusCountsForDocuments($ids) { return []; }
        public function getRecipientsForDocuments($ids) { return []; }
    }
}

if (!class_exists(TestDocumentsVisibilityModel::class)) {
    class TestDocumentsVisibilityModel {
        public function isDocumentAccessible(array $document, $roleId, $email) { return true; }
        public function deleteVisibility($documentId, $roleId, $email) {}
        public function hideVisibilityForPrincipal($documentId, $roleId, $email) { return true; }
        public function createVisibility($documentId, $email, $roleId = null) {}
        public function hasLiveStakeholderVisibility($documentId) { return false; }
    }
}

if (!class_exists(TestDocumentsUploadModel::class)) {
    class TestDocumentsUploadModel {
        public function getFileSizeByCode($code) { return null; }
        public function getUploadByCodeForSigning($code) { return null; }
        public function addUpload($name, $filename, $area, $desc, $descFull, $customerId, $companyId, $roleId, $path, $size = null) {
            return ['code' => 'test-code-' . uniqid()];
        }
        public function deleteUploadByCodeForCustomer($code, $customerId, $area) {}
    }
}

if (!class_exists(TestDocumentsGuestSessionModel::class)) {
    class TestDocumentsGuestSessionModel {
        public function getSessionsByDocumentId($id) { return []; }
        public function getByDocumentSignerId($id) { return null; }
        public function getVerificationStateCountsForDocuments($ids) { return []; }
    }
}

if (!class_exists(TestDocumentsEntitlementModel::class)) {
    class TestDocumentsEntitlementModel {
        public function chargePrepaidEnvelopeForDocumentIfNeeded($companyId, $docId, $customerId, $flag, $roleId) { return ['ok' => true]; }
        public function chargeSponsoredSigningInvitationsForDocumentIfNeeded($companyId, $docId, $customerId, $flag, $roleId) { return ['ok' => true]; }
        public function getSimpleSignatureEnvelopesRemainingForCustomerRole($roleId) { return 10; }
        public function chargeSimpleSignaturePackOnDocumentCompleted($docId) {}
    }
}

if (!class_exists(TestDocumentsSignerClaimModel::class)) {
    class TestDocumentsSignerClaimModel {
        public function buildSignerClaimExistsSql($customerId, $roleId, $email) { return '0'; }
    }
}

if (!class_exists(TestDocumentsFileVersionModel::class)) {
    class TestDocumentsFileVersionModel {
        public function purgeAllVersionsForDocument($id) { return ['failed' => 0]; }
        public function getLastPurgeError() { return ''; }
    }
}

if (!class_exists(TestDocumentsCustomer::class)) {
    class TestDocumentsCustomer {
        private int $roleId;
        private int $id;
        private int $companyId;
        private string $email;
        private string $firstName;
        private string $lastName;
        private ?string $totpSecret;

        public function __construct(
            int $roleId = 10,
            int $id = 1,
            int $companyId = 0,
            string $email = 'owner@example.com',
            string $firstName = 'Owner',
            string $lastName = 'Test',
            ?string $totpSecret = null
        ) {
            $this->roleId = $roleId;
            $this->id = $id;
            $this->companyId = $companyId;
            $this->email = $email;
            $this->firstName = $firstName;
            $this->lastName = $lastName;
            $this->totpSecret = $totpSecret;
        }

        public function getRoleId() { return $this->roleId; }
        public function getId() { return $this->id; }
        public function getCompanyId() { return $this->companyId; }
        public function getEmail() { return $this->email; }
        public function getFirstName() { return $this->firstName; }
        public function getLastName() { return $this->lastName; }
        public function getTotpSecret() { return $this->totpSecret; }
    }
}

// Testable subclass exposing private methods for unit testing.
if (!class_exists(TestableControllerPublicAPIV1Documents::class)) {
    class TestableControllerPublicAPIV1Documents extends ControllerPublicAPIV1Documents {

        protected function checkPlugin() { /* no-op in tests */ }

        protected function resolveIntegratorSigningRedirectUrl(array $document, $sign_code, $status)
        {
            return null;
        }

        public ?array $testPost = null;
        public array $committedReservations = [];
        public array $releasedReservations = [];
        public ?array $remindCheck = null;

        protected function getPost()
        {
            if ($this->testPost !== null) {
                $this->post = $this->testPost;
                return $this->testPost;
            }
            if ($this->apiRequest && method_exists($this->apiRequest, 'getContent')) {
                $content = $this->apiRequest->getContent();
                $json = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    $this->post = $json;
                    return $json;
                }
            }
            $this->post = [];
            return [];
        }

        public function callIndex() { return $this->index(); }
        public function callGetDocument($uuid) {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'getDocument');
            $m->setAccessible(true);
            return $m->invoke($this, $uuid);
        }
        public function callGetDocumentFile($uuid) {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'getDocumentFile');
            $m->setAccessible(true);
            return $m->invoke($this, $uuid);
        }
        public function callGetDocumentAuditCertificate($uuid) {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'getDocumentAuditCertificate');
            $m->setAccessible(true);
            return $m->invoke($this, $uuid);
        }
        public function callListDocuments() {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'listDocuments');
            $m->setAccessible(true);
            return $m->invoke($this);
        }
        public function callListRecipients() { return $this->listRecipients(); }
        public function callPutDocumentDraft($uuid) {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'putDocumentDraft');
            $m->setAccessible(true);
            return $m->invoke($this, $uuid);
        }
        public function callSetAnnotations($uuid) {
            $m = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, 'setAnnotations');
            $m->setAccessible(true);
            return $m->invoke($this, $uuid);
        }
        public function callAddSigners($uuid) { return $this->callPrivate('addSigners', [$uuid]); }
        public function callSendDocument($uuid) { return $this->callPrivate('sendDocument', [$uuid]); }
        public function callCancelDocument($uuid) { return $this->callPrivate('cancelDocument', [$uuid]); }
        public function callRemindSigner($uuid) { return $this->callPrivate('remindSigner', [$uuid]); }
        public function callDeleteDocument($uuid) { return $this->callPrivate('deleteDocument', [$uuid]); }
        public function callDeleteDocumentAsOwner($document, $roleId, $email, $purge) { return $this->callPrivate('deleteDocumentAsOwner', [$document, $roleId, $email, $purge]); }

        private function callPrivate(string $method, array $args) {
            $ref = new \ReflectionMethod(ControllerPublicAPIV1Documents::class, $method);
            $ref->setAccessible(true);
            return $ref->invokeArgs($this, $args);
        }
        public function callParseCcNotificationEmails($raw) { return $this->parseCcNotificationEmails($raw); }
        public function callSplitCcNotificationEmails($stored) { return $this->splitCcNotificationEmails($stored); }
        public function callBuildSigningUrl(array $signer) { return $this->buildSigningUrl($signer); }
        public function callResolveSigningEmailSenderName(array $document, array $ownerInfo) { return $this->resolveSigningEmailSenderName($document, $ownerInfo); }

        protected function sendResponse() { /* no-op: avoids die() and side effects in tests */ }
        protected function streamPdfFile($file_path, $filename) { /* no-op in tests */ }

        protected function assertDocumentRemindAllowed($customer_id, $document_id, $signer_email) {
            if ($this->remindCheck !== null) {
                return $this->remindCheck;
            }
            return ['ok' => true, 'rate_limit_reservation' => ['key' => 'reservation']];
        }

        protected function releaseSendRateLimitReservation(array $reservation) {
            $this->releasedReservations[] = $reservation;
        }

        protected function commitDocumentRemindRateLimit(array $reservation) {
            $this->committedReservations[] = $reservation;
        }

    }
}

// ---------------------------------------------------------------------------
// Routing/ownership test helpers (documents_test_controller, etc.)
// ---------------------------------------------------------------------------

if (!class_exists('DocumentsCustomerStub', false)) {
    class DocumentsCustomerStub {
        private int $customerId;
        private int $roleId;
        private int $companyId;
        private string $email;

        public function __construct(int $customerId = 1, int $roleId = 9, int $companyId = 0, string $email = 'owner@example.com') {
            $this->customerId = $customerId;
            $this->roleId = $roleId;
            $this->companyId = $companyId;
            $this->email = $email;
        }

        public function getRoleId() { return $this->roleId; }
        public function getId() { return $this->customerId; }
        public function getCompanyId() { return $this->companyId; }
        public function getEmail() { return $this->email; }
        public function getFirstName() { return 'Test'; }
        public function getLastName() { return 'User'; }
        public function getTotpSecret() { return null; }
    }
}

if (!class_exists('RoutingDocumentModel', false)) {
    class RoutingDocumentModel extends TestDocumentsDocumentModel {
        public ?array $document = null;
        public ?array $groupDocuments = null;
        public bool $accessibleOwnerRole = false;
        public int $groupMemberCount = 1;
        public bool $groupLocked = false;
        public bool $personalOwned = true;
        public ?bool $canActAsOwner = null;
        public array $writes = [];
        public array $groupMembers = [];
        public array $documentsById = [];

        public function getDocumentByUuid($uuid) { return $this->document; }
        public function getDocumentById($id) {
            return $this->documentsById[(int)$id] ?? $this->document;
        }
        public function getDocumentsByGroupUuid($uuid) {
            if ($this->groupMembers) {
                return $this->groupMembers;
            }
            return $this->groupDocuments ?? [$this->document];
        }
        public function countGroupMembers($uuid) { return $this->groupMemberCount; }
        public function isGroupLocked($document) { return $this->groupLocked; }
        public function isPersonalOwned($document) {
            return $this->personalOwned;
        }
        public function canActAsDocumentOwner($customerRoleId, array $document): bool {
            if ($this->canActAsOwner !== null) {
                return $this->canActAsOwner;
            }
            return (int)$customerRoleId > 0
                && isset($document['owner_customer_role_id'])
                && (int)$customerRoleId === (int)$document['owner_customer_role_id'];
        }
        public function softDeleteDocument($id) {
            $this->writes[] = ['method' => 'softDeleteDocument', 'id' => $id];
        }
        public function cancelDocument($id) {
            $this->writes[] = ['method' => 'cancelDocument', 'id' => $id];
        }
        public function updateDocumentDraft($id, $patch) {
            $this->writes[] = ['method' => 'updateDocumentDraft', 'id' => $id, 'patch' => $patch];
        }
        public function updateAnnotations($id, $annotations) {
            $this->writes[] = ['method' => 'updateAnnotations', 'id' => $id];
        }
        public function sendDocument($id, $lockToken = null) {
            $this->writes[] = ['method' => 'sendDocument', 'id' => $id];
        }
        public function hasLiveStakeholderVisibility($documentId) { return false; }
    }
}

if (!class_exists('RoutingSignerModel', false)) {
    class RoutingSignerModel extends TestDocumentsSignerModel {
        public array $signers = [];
        public array $writes = [];

        public function getSignersByDocumentId($documentId, $includeDraft = false) { return $this->signers; }
        public function getSignerByDocumentAndEmail($documentId, $email) {
            foreach ($this->signers as $s) {
                if (strtolower($s['email'] ?? '') === strtolower($email)) {
                    return $s;
                }
            }
            return null;
        }
        public function addSigner($documentId, $data) {
            $this->writes[] = ['method' => 'addSigner', 'documentId' => $documentId, 'data' => $data];
        }
        public function deleteAllSigners($documentId) {
            $this->writes[] = ['method' => 'deleteAllSigners', 'documentId' => $documentId];
            return 0;
        }
    }
}

if (!class_exists('RoutingVisibilityModel', false)) {
    class RoutingVisibilityModel extends TestDocumentsVisibilityModel {
        public int $checks = 0;
        public bool $accessible = true;
        public bool $hidden = false;
        public bool $hasLiveStakeholders = false;
        public array $writes = [];

        public function isDocumentAccessible(array $document, $roleId, $email) {
            $this->checks++;
            return $this->accessible;
        }
        public function hideVisibilityForPrincipal($documentId, $roleId, $email) {
            $this->writes[] = ['method' => 'hideVisibilityForPrincipal', 'documentId' => $documentId, 'roleId' => $roleId, 'email' => $email];
            $this->hidden = true;
            return true;
        }
        public function hasLiveStakeholderVisibility($documentId) {
            return $this->hasLiveStakeholders;
        }
        public function deleteVisibility($documentId, $roleId, $email) {
            $this->writes[] = ['method' => 'deleteVisibility', 'documentId' => $documentId, 'roleId' => $roleId];
        }
    }
}

if (!class_exists('RoutingUploadModel', false)) {
    class RoutingUploadModel extends TestDocumentsUploadModel {
        public array $purgedDocumentIds = [];
        public ?array $purgeResult = null;

        public function getFileSizeByCode($code) { return 12345; }
    }
}

if (!class_exists('RoutingFileVersionModel', false)) {
    class RoutingFileVersionModel extends TestDocumentsFileVersionModel {
        public array $purgedDocumentIds = [];
        public ?array $purgeResult = null;

        public function purgeAllVersionsForDocument($id) {
            $this->purgedDocumentIds[] = $id;
            return $this->purgeResult ?? ['deleted' => 1, 'failed' => 0];
        }
    }
}

if (!class_exists('RoutingDbStub', false)) {
    class RoutingDbStub {
        public bool $mailSuccess = true;
        public int $lastId = 1;
        public array $queries = [];

        public function query(string $sql) {
            $this->queries[] = $sql;
            return (object)['row' => null, 'rows' => [], 'num_rows' => 0];
        }
        public function escape(string $value): string { return addslashes($value); }
        public function getLastId() { return $this->lastId; }
        public function countAffected() { return 1; }
    }
}

/**
 * Build a controller wired for routing/ownership/integrator tests.
 *
 * @return array [controller, documentModel, signerModel, visibilityModel, filesModel, db]
 */
function documents_test_controller(?object $customer = null): array
{
    $registry = new Registry();
    $controller = new TestableControllerPublicAPIV1Documents($registry);

    $controller->json = ['success' => 1, 'error' => [], 'data' => []];
    $controller->statusCode = 200;
    $controller->allowedHeaders = null;
    $controller->integratorClientRow = null;

    $controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void { $this->loaded[] = $name; }
        public function language(string $name): void {}
        public function library(string $name): void {}
        public function controller(string $name): void {}
    };

    if ($customer === null) {
        $customer = new DocumentsCustomerStub();
    }

    $controller->customer = $customer;
    $controller->useremail = $customer->getEmail();

    $controller->request = (object)['get' => []];
    $controller->permission = (object)[
        'get'    => ['publicapi/v1/documents'],
        'post'   => ['publicapi/v1/documents'],
        'put'    => ['publicapi/v1/documents'],
        'delete' => ['publicapi/v1/documents'],
    ];

    $documentModel = new RoutingDocumentModel();
    $signerModel = new RoutingSignerModel();
    $visibilityModel = new RoutingVisibilityModel();
    $filesModel = new RoutingUploadModel();
    $fileVersionModel = new RoutingFileVersionModel();
    $db = new RoutingDbStub();

    $controller->model_signing_document = $documentModel;
    $controller->model_signing_signer = $signerModel;
    $controller->model_signing_visibility = $visibilityModel;
    $controller->model_tool_upload = $filesModel;
    $controller->model_signing_guest_session = new TestDocumentsGuestSessionModel();
    $controller->model_signing_audit_event = new ModelSigningAuditEvent();
    $controller->model_signing_signer_claim = new TestDocumentsSignerClaimModel();
    $controller->model_signing_document_file_version = $fileVersionModel;
    $controller->model_billing_entitlement_signing = new TestDocumentsEntitlementModel();

    $controller->config = new class {
        private array $data = [];
        public function get($key) { return $this->data[$key] ?? null; }
        public function load($name) {}
        public function set($key, $value) { $this->data[$key] = $value; }
    };

    $controller->db = $db;
    $registry->set('db', $db);
    $registry->set('config', $controller->config);
    $registry->set('load', $controller->load);
    $registry->set('model_localisation_language', new class {
        public function getLanguages() { return ['en-gb' => ['language_id' => 1]]; }
    });

    return [$controller, $documentModel, $signerModel, $visibilityModel, $fileVersionModel, $db];
}

function documents_test_document(array $overrides = []): array
{
    return array_replace([
        'document_id' => 71,
        'uuid' => 'document-owner-uuid',
        'name' => 'Test Document',
        'lifecycle_status_code' => 'DRAFT',
        'signature_level' => 'qualified',
        'owner_email' => 'owner@example.com',
        'owner_customer_role_id' => 9,
        'owner_company_id' => 0,
        'document_group_uuid' => '',
        'origin_csc_api_client_id' => 700,
        'integrator_return_url' => 'https://integrator.example.test/return',
        'original_file_code' => 'file-code-1',
        'current_file_code' => 'file-code-1',
        'final_file_code' => null,
        'files_purged_at' => null,
        'signature_request_annotations' => null,
        'cc_notification_emails' => null,
        'expires_at' => null,
        'completed_at' => null,
        'cancelled_at' => null,
        'rejected_at' => null,
        'date_added' => '2025-01-01 00:00:00',
    ], $overrides);
}

function documents_test_signers(array $overrides = []): array
{
    return $overrides ?: [
        [
            'document_signer_id' => 1,
            'email' => 'owner@example.com',
            'signing_type' => 'SIGN',
            'signature_kind' => 'qualified',
            'status_code' => 'INVITED',
            'sign_code' => 'owner-sign-code',
            'signing_order' => 1,
            'parallel_group' => null,
            'invitation_type' => 'standard_invitation',
            'invited_at' => null,
            'signed_at' => null,
            'viewed_at' => null,
            'rejected_at' => null,
            'draft_signature_data' => null,
            'uses_prepaid_invite' => 0,
        ],
        [
            'document_signer_id' => 2,
            'email' => 'invitee@example.com',
            'signing_type' => 'SIGN',
            'signature_kind' => 'qualified',
            'status_code' => 'INVITED',
            'sign_code' => 'invitee-sign-code',
            'signing_order' => 2,
            'parallel_group' => null,
            'invitation_type' => 'standard_invitation',
            'invited_at' => null,
            'signed_at' => null,
            'viewed_at' => null,
            'rejected_at' => null,
            'draft_signature_data' => null,
            'uses_prepaid_invite' => 0,
        ],
    ];
}


/**
 * Build a minimal controller instance wired with test doubles.
 *
 * @return TestableControllerPublicAPIV1Documents
 */
function buildDocumentsController(array $overrides = []): TestableControllerPublicAPIV1Documents
{
    $registry = new Registry();
    $controller = new TestableControllerPublicAPIV1Documents($registry);

    $controller->json = ['success' => 1, 'error' => [], 'data' => []];
    $controller->statusCode = 200;
    $controller->allowedHeaders = null;
    $controller->integratorClientRow = null;

    $controller->load = new class {
        public array $loaded = [];
        public function model(string $name): void { $this->loaded[] = $name; }
        public function language(string $name): void {}
        public function library(string $name): void {}
    };

    $roleId = $overrides['role_id'] ?? 10;
    $customerId = $overrides['customer_id'] ?? 1;
    $companyId = $overrides['company_id'] ?? 0;
    $email = $overrides['email'] ?? 'owner@example.com';
    $totpSecret = $overrides['totp_secret'] ?? null;

    $controller->customer = new TestDocumentsCustomer($roleId, $customerId, $companyId, $email, 'Owner', 'Test', $totpSecret);
    $controller->useremail = $email;

    $controller->request = (object)['get' => $overrides['request_get'] ?? []];
    $controller->permission = (object)[
        'get'    => $overrides['perm_get'] ?? ['publicapi/v1/documents'],
        'post'   => $overrides['perm_post'] ?? ['publicapi/v1/documents'],
        'put'    => $overrides['perm_put'] ?? ['publicapi/v1/documents'],
        'delete' => $overrides['perm_delete'] ?? ['publicapi/v1/documents'],
    ];

    $controller->model_signing_document = new TestDocumentsDocumentModel();
    $controller->model_signing_signer = new TestDocumentsSignerModel();
    $controller->model_signing_visibility = new TestDocumentsVisibilityModel();
    $controller->model_tool_upload = new TestDocumentsUploadModel();
    $controller->model_signing_guest_session = new TestDocumentsGuestSessionModel();
    $controller->model_signing_audit_event = new ModelSigningAuditEvent();
    $controller->model_signing_signer_claim = new TestDocumentsSignerClaimModel();
    $controller->model_signing_document_file_version = new TestDocumentsFileVersionModel();
    $controller->model_billing_entitlement_signing = new TestDocumentsEntitlementModel();

    $controller->config = new class {
        private array $data = [];
        public function get($key) { return $this->data[$key] ?? null; }
        public function load($name) {}
        public function set($key, $value) { $this->data[$key] = $value; }
    };

    $controller->db = new class {
        public function query(string $sql) {
            return (object)['row' => [], 'rows' => [], 'num_rows' => 0];
        }
        public function escape(string $value): string { return addslashes($value); }
    };

    return $controller;
}
