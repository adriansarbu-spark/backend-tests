<?php

declare(strict_types=1);

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', '');
}

if (!class_exists(TestableControllerPublicAPIV1Signing::class)) {
    class TestableControllerPublicAPIV1Signing extends ControllerPublicAPIV1Signing {
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
        public function rejectDocument($sign_code)
        {
            return parent::rejectDocument($sign_code);
        }
    }
}

if (!class_exists(TestableControllerPublicAPIV1SigningDraft::class)) {
    class TestableControllerPublicAPIV1SigningDraft extends ControllerPublicAPIV1Signing {
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

