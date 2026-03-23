### Signing API testing strategy

**Controller**: `ControllerPublicAPIV1Signing` (`public/catalog/controller/publicapi/v1/signing.php`)

**Endpoints under test**

- **GET** `/api/signing/{sign_code}` → `getSignerInfo`
- **GET** `/api/signing/{sign_code}/file` → `getDocumentFile`
- **POST** `/api/signing/{sign_code}/sign` → `signDocument`
- **POST** `/api/signing/{sign_code}/reject` → `rejectDocument`
- **PUT** `/api/signing/{sign_code}/draft` → `saveDraftSignature`

**Primary objectives**

- **Unauthorized access / security risks**
  - Prevent viewing or signing/rejecting a document by someone who is not the intended signer or owner.
  - Prevent information leakage about documents (existence, status, content) when the caller is not allowed.
- **Edge cases and input robustness**
  - Handle empty/malformed fields and invalid state transitions without crashes or inconsistent data.

---

### Unit tests (controller-level, with mocks)

Unit tests mock all external dependencies (`model_signing_signer`, `model_signing_document`, `model_signing_visibility`, `model_certificate_certificate`, `model_tool_upload`, `customer`, `permission`, `db`, `DocumentSigner`, `TwoFactorAuth`, filesystem calls where needed).

#### `index()` routing and permissions

- **Missing sign_code**
  - No `sign_code` in `$_GET` or `route` → returns 400, error `sign_code is required`.
- **Permission matrix**
  - GET `/api/signing/{sign_code}` without `publicapi/v1/signing` in `$permission->get` → 403.
  - GET `/api/signing/{sign_code}/file` without `publicapi/v1/signing` in `$permission->get` → 403.
  - POST `/api/signing/{sign_code}/sign` without `publicapi/v1/signing` in `$permission->post` → 403.
  - POST `/api/signing/{sign_code}/reject` without `publicapi/v1/signing` in `$permission->post` → 403.
  - PUT `/api/signing/{sign_code}/draft` without `publicapi/v1/signing` in `$permission->put` → 403.
- **Invalid methods/actions**
  - Unsupported HTTP method or unknown `action` → 405 with `allowedHeaders = ["GET", "POST", "PUT"]`.

#### `getSignerInfo($sign_code)`

- **Not found**
  - `getSignerBySignCode` returns null → 404 `Signer not found`.
  - Document lookup returns null → 404 `Document not found`.
- **Email-based authorization**
  - `$useremail` anonymous or null → 403 `Access denied. Email does not match signer email.`.
  - `$useremail` not equal to signer email (case-insensitive compare) → 403.
  - Matching emails (with case/whitespace variations) → allowed.
- **Visibility**
  - `isVisible` false for `(document_id, customer_role_id, user_email)` → 404 `Document not found` (to avoid leaking existence).
- **Lifecycle**
  - Document `lifecycle_status_code = DRAFT` → 403 `Document has not been sent yet...`.
- **Annotations filtering**
  - `signature_request_annotations` with mixed emails → only annotations for `user_email` returned.
  - Invalid or non-array JSON → treated as empty annotations list, no crash.

#### `getDocumentFile($sign_code)`

- **Not found**
  - Missing signer or document → 404.
- **Visibility**
  - `isVisible` returns false for `(document_id, customer_role_id, signer_email)` → 404.
- **Lifecycle rules**
  - `CANCELLED` → 422 `Document has been cancelled`.
  - `expires_at` in the past → 422 `Document has expired`.
  - Status not in `['DRAFT', 'PENDING', 'COMPLETED']` → 422 `Document must be in DRAFT, PENDING, or COMPLETED status to download`.
- **Signer status rules**
  - Signer `status_code` not in `['DRAFT', 'INVITED', 'VIEWED', 'SIGNED']` → 422 `Signer status does not allow viewing document`.
- **File selection**
  - COMPLETED with `final_file_code` → uses that.
  - Otherwise falls back to `current_file_code`, then `original_file_code`.
  - No usable file code → 404 `Document file not found`.
- **Upload & filesystem errors**
  - No upload row → 404 `File not found`.
  - File missing on disk → 404 `File not found on disk`.
- **Status transitions**
  - When signer status `INVITED` → `updateSignerStatus(..., 'VIEWED')` is called.
  - If already `VIEWED` or other, no status change.
- **Streaming**
  - Unit tests stub `streamPdfFile` and assert it is invoked with expected path and filename.

#### `signDocument($sign_code)`

- **Not found**
  - Missing signer or document → 404.
- **Owner vs non-owner document status**
  - Owner (`owner_customer_role_id == customer_role_id`):
    - Allowed document statuses: `DRAFT`, `PENDING`.
    - Any other status → 422 `Document must be in DRAFT or PENDING status to sign`.
  - Non-owner:
    - Document must be `PENDING`, otherwise → 422 `Document must be in PENDING status to sign`.
- **Signer identity and authorization**
  - Non-owner with mismatched `signer.customer_role_id` vs `customer_role_id` → 403 `You are not authorized to sign this document`.
  - Owner exempt from `customer_role_id` check.
- **Signer status rules**
  - Owner: allowed signer statuses `DRAFT`, `INVITED`, `VIEWED`; others → 422 `Signer status does not allow signing`.
  - Non-owner: allowed statuses `INVITED`, `VIEWED`; others → 422.
- **Turn-based signing / parallel groups**
  - Non-owner or owner not in `DRAFT`:
    - `getNextSigner` returns null → 422 `It is not your turn to sign`.
    - Next signer exists, but `(signing_order, parallel_group)` mismatch → 422.
  - Owner in `DRAFT` skips the `getNextSigner` turn check.
- **TOTP validation**
  - Missing `totp` in both raw and cleaned POST → 422 `TOTP code is required`.
  - Missing `customer` secret → 422 `TOTP secret not configured for user`.
  - `TwoFactorAuth::verifyCode` returns false → 422 `Invalid TOTP code`.
- **Signature payload parsing**
  - Missing `signature_payload` or not array after parsing → 422 `signature_payload is required and must be an array`.
  - String payload:
    - HTML-entity encoded JSON decodes successfully.
    - Invalid JSON even after fallback decode → 422 `Invalid signature_payload JSON format`.
  - Per-entry validation:
    - New format: `page`, `x`, `y`, `width`, `height` → transformed into expected `pageNumber`/`bounds`.
    - Old format: already `pageNumber` + `bounds` → accepted as-is.
    - Any entry missing required fields → 422 `Invalid signature format. Expected page/x/y/width/height or pageNumber/bounds`.
- **Certificate requirements**
  - No certificate or `cert_status` not `valid` → 422 `Valid certificate required for signing`.
- **Current file validation**
  - `getUploadByCodeForSigning` returns null → 422 `Current file not found`.
- **DocumentSigner integration (mocked)**
  - `sign()` returns false → exception → 500 `Signing failed: ...`.
  - `sign()` throws exception → 500 `Signing failed: ...`.
- **Post-signing updates**
  - New signed file written to dated folder and upload record created.
  - `updateCurrentFileCode` invoked with new code.
  - `updateSignerStatus(..., 'SIGNED')` called.
  - `updateSignatureResultAnnotations` called with transformed payload array.
  - If `customer_role_id > 0` and signer email present → `updateCustomerRoleId` invoked.
- **Lifecycle transitions and next group**
  - When `isGroupComplete` true and `getNextGroupToInvite` returns group:
    - `inviteGroup` called for that `(signing_order, parallel_group)`.
    - If document was `DRAFT` → `sendDocument` called to move to `PENDING`.
    - Visibility records created via `createVisibility` for each signer in invited group.
  - When no next group:
    - If `areAllSignersSigned` returns true → `completeDocument` called.
  - When group not complete but all signers signed (parallel group edge) → `completeDocument` called.

#### `rejectDocument($sign_code)`

- **Not found**
  - Missing signer or document → 404.
- **Document lifecycle**
  - Status not `PENDING` → 422 `Document must be in PENDING status to reject`.
- **Authorization**
  - `signer.customer_role_id > 0` and not equal to `customer_role_id` → 403 `You are not authorized to reject this document`.
- **Signer status rules**
  - Status not in `['INVITED', 'VIEWED']` → 422 `Signer status does not allow rejection`.
- **TOTP validation**
  - Missing `totp` → 422 `TOTP code is required`.
  - Missing secret → 422 `TOTP secret not configured for user`.
  - Invalid code → 422 `Invalid TOTP code`.
- **Post-rejection updates**
  - On valid request, `updateSignerStatus(..., 'REJECTED')` and `rejectDocument(document_id)` called and success response returned.

#### `saveDraftSignature($sign_code)`

- **Draft parsing**
  - JSON body (`application/json`) with `draft_signature` key parsed successfully.
  - Fallback to `getPost()` when JSON parsing fails or missing.
  - HTML-encoded JSON string decoded and parsed.
- **Empty draft semantics (idempotent delete)**
  - Input considered empty when:
    - `draft_signature` is `null` or `""`.
    - Empty array `[]`.
    - Empty object `{}`.
    - Array/object whose values are themselves empty arrays/objects/empty scalars (e.g. `{"signatures": []}`).
  - Behavior for empty draft:
    - If signer exists → `updateDraftSignature($sign_code, [])` is called.
    - If signer does not exist → no error.
    - Always returns success: `Draft signature cleared successfully`.
- **Non-empty draft: not found**
  - Signer not found → 404 `Signer not found`.
  - Document not found → 404 `Document not found`.
- **Ownership and email checks**
  - Owner (`owner_customer_role_id == customer_role_id`) may save drafts regardless of email match.
  - Non-owner:
    - If `user_email` missing/anonymous or not equal to signer email (case-insensitive) → 403 `Access denied. Email does not match signer email.`.
- **Lifecycle rule for non-owners**
  - Non-owner and document `DRAFT` → 403 `Document has not been sent yet. Signers can only save drafts after the document has been sent.`.
- **Required and type validation**
  - `draft_signature === null` after parsing → 422 `draft_signature is required`.
  - `draft_signature` not array/object → 422 `draft_signature must be a valid JSON object or array`.
- **Successful save**
  - Valid non-empty draft for authorized caller → `updateDraftSignature($sign_code, $draft_signature)` called and success response returned.

---

### Integration tests (HTTP-level)

Use Pest/PHPUnit feature tests with the real HTTP layer, real models and database (or transactions), and a stubbed `DocumentSigner` that returns a known base64 PDF. Aim to simulate realistic flows and enforce security guarantees.

#### Security / unauthorized access

- **Viewing a document you do not own or sign**
  - Create document + signer for `user1@example.com`.
  - Authenticate as `user2@example.com`.
  - `GET /api/signing/{sign_code}` → 403, no document details.
  - `PUT /api/signing/{sign_code}/draft` as non-owner with mismatching email → 403.
- **Visibility hiding**
  - Configure visibility so the document is hidden for the authenticated user.
  - `GET /api/signing/{sign_code}/file` → 404 (no existence leak).
- **Role and group authorization**
  - Signer with mismatching `customer_role_id` for current user:
    - `POST /api/signing/{sign_code}/sign` → 403.
    - `POST /api/signing/{sign_code}/reject` → 403.
  - Signer in disallowed status (e.g. `SIGNED`) trying to sign again → 422.
- **TOTP end-to-end**
  - Invalid TOTP to `/sign` or `/reject` → 422 with relevant error message.
  - Valid TOTP with correct secret → 200 success.
- **Owner vs signer behavior**
  - Owner can:
    - Sign `DRAFT` document via `/sign`.
    - Save draft on `DRAFT` document via `/draft`.
  - Non-owner:
    - Cannot sign when document is `DRAFT` (needs `PENDING`).
    - Cannot save drafts for `DRAFT` document; only after it is `PENDING` or later.

#### Happy-path flows

- **Signer viewing information and file**
  - `GET /api/signing/{sign_code}` returns:
    - Only signer-scoped annotations.
    - Non-confidential document metadata.
  - `GET /api/signing/{sign_code}/file` returns 200 with `Content-Type: application/pdf` and updates signer status from `INVITED` to `VIEWED`.
- **Full signing flow**
  - Setup:
    - Document in `PENDING` with owner and one or more signers.
    - Signer with correct role, status `INVITED`.
    - Valid certificate row.
    - Upload row and physical PDF file under `DIR_UPLOAD`.
  - Call `POST /api/signing/{sign_code}/sign` with:
    - Valid TOTP.
    - Valid signature payload (new frontend format).
  - Assert:
    - Response 200 with `message`, `document_uuid`, and `new_file_code`.
    - DB: signer status `SIGNED`, document advanced appropriately (PENDING→COMPLETED when last signer signs).
    - New file exists in dated upload folder and `current_file_code` updated.
- **Parallel signing groups**
  - Create document with multiple signers sharing same `(signing_order, parallel_group)`.
  - Sign them in any order:
    - Each `POST /sign` allowed while group active.
    - When all in group are `SIGNED`, next group invited or document completed based on configuration.

#### Edge-case integration scenarios

- **Expired / cancelled documents**
  - `GET /api/signing/{sign_code}/file` on:
    - Expired document → 422 `Document has expired`.
    - Cancelled document → 422 `Document has been cancelled`.
- **Bad JSON bodies**
  - `POST /api/signing/{sign_code}/sign` with malformed `signature_payload` JSON → 422.
  - `PUT /api/signing/{sign_code}/draft` with invalid JSON or wrong structure → 422 `draft_signature must be a valid JSON object or array`.
- **Idempotent draft clearing**
  - Call `PUT /api/signing/{sign_code}/draft` with empty draft → 200 `Draft signature cleared successfully`.
  - Call again with same payload → still 200, no unexpected changes.
  - Call with non-existent `sign_code` but empty draft → 200, no-op.

---

### Suggested test file layout

- **Unit**
  - `tests/Unit/Api/Signing/IndexTest.php`
  - `tests/Unit/Api/Signing/GetSignerInfoTest.php`
  - `tests/Unit/Api/Signing/GetDocumentFileTest.php`
  - `tests/Unit/Api/Signing/SignDocumentTest.php`
  - `tests/Unit/Api/Signing/RejectDocumentTest.php`
  - `tests/Unit/Api/Signing/SaveDraftSignatureTest.php`
- **Integration**
  - `tests/Feature/Api/Signing/GetSignerInfoTest.php`
  - `tests/Feature/Api/Signing/GetDocumentFileTest.php`
  - `tests/Feature/Api/Signing/SignDocumentFlowTest.php`
  - `tests/Feature/Api/Signing/RejectDocumentFlowTest.php`
  - `tests/Feature/Api/Signing/SaveDraftSignatureFlowTest.php`
