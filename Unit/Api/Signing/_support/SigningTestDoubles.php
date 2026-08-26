<?php

declare(strict_types=1);

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

// signing.php calls \ModelSigningSigner::isGuestInvitation() / isSimpleSignatureKind()
// without going through $this->load->model. Unit tests do not bootstrap the catalog
// model file, so provide the same static helpers as catalog/model/signing/signer.php.
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

// signing.php passes \ModelCertificateCertificate::USAGE_DOCUMENT_SIGNING to the certificate model.
if (!class_exists('ModelCertificateCertificate', false)) {
    class ModelCertificateCertificate {
        public const USAGE_DOCUMENT_SIGNING = 'document_signing';

        public const USAGE_CLIENT_AUTHENTICATION = 'client_authentication';

        public static function normalizeUsageType($usage): ?string
        {
            $usage = strtolower(trim((string) $usage));

            return in_array($usage, [self::USAGE_DOCUMENT_SIGNING, self::USAGE_CLIENT_AUTHENTICATION], true)
                ? $usage
                : null;
        }
    }
}

// signing.php / SigningWorkflow call \ModelSigningAuditEvent statics and ->record()
// without bootstrapping catalog/model/signing/audit_event.php in unit tests.
// Pest runs all unit tests in one process, so this stub may also serve the Documents
// tests (or vice versa). Keep it a superset identical to the one in
// tests/Unit/Api/Documents/_support/DocumentsTestDoubles.php.
if (!class_exists('ModelSigningAuditEvent', false)) {
    class ModelSigningAuditEvent {
        public const EVENT_SENT = 'sent';
        public const EVENT_VIEWED = 'viewed';
        public const EVENT_CONSENT_ACCEPTED = 'consent_accepted';
        public const EVENT_SIGNED = 'signed';
        public const EVENT_REJECTED = 'rejected';
        public const EVENT_COMPLETED = 'completed';

        /** @var list<array{document_id:int,document_signer_id:int|null,event_type:string,meta:array}> */
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

/**
 * Skip production integrator-redirect resolution in signing unit tests.
 *
 * PublicAPIController::resolveIntegratorSigningRedirectUrl() require_onces
 * csc_document_redirect.php, which pulls in the real CscKeycloakProvisioner.
 * CSC unit tests may already have loaded a test double of that class in the
 * same Pest process; re-including the production file would fatal.
 */
trait SigningSkipsIntegratorRedirect
{
    protected function resolveIntegratorSigningRedirectUrl(array $document, $sign_code, $status)
    {
        return null;
    }
}

if (!class_exists(TestableControllerPublicAPIV1Signing::class)) {
    class TestableControllerPublicAPIV1Signing extends ControllerPublicAPIV1Signing {
        use SigningSkipsIntegratorRedirect;

        public function getUploadRoot()
        {
            return parent::getUploadRoot();
        }

        public function getDocumentFile($sign_code)
        {
            return parent::getDocumentFile($sign_code);
        }

        public function signDocument($sign_code)
        {
            return parent::signDocument($sign_code);
        }

        protected function streamPdfFile($file_path, $filename)
        {
            return parent::streamPdfFile($file_path, $filename);
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1SigningReject::class)) {
    class TestableControllerPublicAPIV1SigningReject extends ControllerPublicAPIV1Signing {
        use SigningSkipsIntegratorRedirect;

        public function rejectDocument($sign_code)
        {
            return parent::rejectDocument($sign_code);
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1SigningDraft::class)) {
    class TestableControllerPublicAPIV1SigningDraft extends ControllerPublicAPIV1Signing {
        use SigningSkipsIntegratorRedirect;

        // Invoked via reflection in draft tests.
    }
}

if (!class_exists(TestSigningSignerModel::class)) {
    class TestSigningSignerModel {
        public function getSignerBySignCode($signCode) {}
        public function getNextSigner($documentId) {}
        public function isGroupComplete($documentId, $signingOrder, $parallelGroup) {}
        public function getNextGroupToInvite($documentId, $signingOrder) {}
        public function getSignersByDocumentId($documentId) {}
        public function areAllSignersSigned($documentId) {}
        public function updateSignerStatus($signerId, $status) {}
        public function updateSignatureResultAnnotations($signerId, $annotations) {}
        public function updateDraftSignature($signCode, $draftSignature) {}
    }
}

if (!class_exists(TestSigningSignerModelWithInvite::class)) {
    class TestSigningSignerModelWithInvite extends TestSigningSignerModel {
        public function inviteGroup($documentId, $signingOrder, $parallelGroup) {}
    }
}

if (!class_exists(TestSigningSignerModelWithNext::class)) {
    class TestSigningSignerModelWithNext extends TestSigningSignerModel {
        public function getNextSigner($documentId) {}
    }
}

if (!class_exists(TestSigningSignerModelWithDraft::class)) {
    class TestSigningSignerModelWithDraft extends TestSigningSignerModel {
        public function updateDraftSignature($signCode, $draftSignature) {}
    }
}

if (!class_exists(TestSigningSignerModelFull::class)) {
    class TestSigningSignerModelFull extends TestSigningSignerModel {
        public function updateSignerStatus($signerId, $status) {}
        public function updateSignatureResultAnnotations($signerId, $annotations) {}
        public function isGroupComplete($documentId, $signingOrder, $parallelGroup) {}
        public function getNextGroupToInvite($documentId, $signingOrder) {}
        public function getSignersByDocumentId($documentId) {}
        public function areAllSignersSigned($documentId) {}
    }
}

if (!class_exists(TestSigningSignerModelFullWithInvite::class)) {
    class TestSigningSignerModelFullWithInvite extends TestSigningSignerModelFull {
        public function inviteGroup($documentId, $signingOrder, $parallelGroup) {}
    }
}

if (!class_exists(TestSigningSignerModelFullWithNext::class)) {
    class TestSigningSignerModelFullWithNext extends TestSigningSignerModelFull {
        public function getNextSigner($documentId) {}
    }
}

if (!class_exists(TestSigningDocumentModel::class)) {
    class TestSigningDocumentModel {
        public function getDocumentById($documentId) {}
        final public function canActAsDocumentOwner($customerRoleId, array $document) {
            return (int)$customerRoleId > 0
                && isset($document['owner_customer_role_id'])
                && (int)$customerRoleId === (int)$document['owner_customer_role_id'];
        }
        public function updateCurrentFileCode($documentId, $fileCode) {}
        public function sendDocument($documentId, $lockToken) {}
        public function completeDocument($documentId) {}
        public function rejectDocument($documentId) {}
    }
}

if (!class_exists(TestSigningDocumentModelWithUpdates::class)) {
    class TestSigningDocumentModelWithUpdates extends TestSigningDocumentModel {
        public function updateCurrentFileCode($documentId, $fileCode) {}
        public function sendDocument($documentId, $lockToken) {}
        public function completeDocument($documentId) {}
    }
}

if (!class_exists(TestSigningDocumentModelWithReject::class)) {
    class TestSigningDocumentModelWithReject extends TestSigningDocumentModel {
        public function rejectDocument($documentId) {}
    }
}

if (!class_exists(TestSigningVisibilityModel::class)) {
    class TestSigningVisibilityModel {
        public function isVisible($documentId, $customerRoleId, $email) {}
        public function isDocumentAccessible(array $document, $customerRoleId, $email) {}
        public function updateCustomerRoleId($documentId, $email, $customerRoleId) {}
        public function createVisibility($documentId, $email, $customerRoleId) {}
    }
}

if (!class_exists(TestUploadModel::class)) {
    class TestUploadModel {
        public function getUploadByCodeForSigning($code) {}
        public function addUpload($name, $filename, $arg1, $arg2, $arg3, $customerId, $companyId, $customerRoleId, $folder) {}
    }
}

if (!class_exists(TestCustomer::class)) {
    class TestCustomer {
        public function getRoleId() {}
        public function getTotpSecret() {}
        public function getId() {}
        public function getCompanyId() {}
        public function getFirstName() { return 'Signer'; }
        public function getLastName() { return 'Test'; }
    }
}

if (!class_exists(TestCustomerWithIds::class)) {
    class TestCustomerWithIds extends TestCustomer {
        public function getId() {}
        public function getCompanyId() {}
        public function getFirstName() {}
        public function getLastName() {}
    }
}
