# CSC API Testing Plan

This plan covers all 28 PHP controllers under `public/catalog/controller/publicapi/v1/csc`, their routing and authentication paths, directly used models and services, migrations, configuration, and the existing Pest conventions. It proposes tests only; no tests or production code are implemented here.

The plan contains **73 unit-test groups** and **24 integration-test workflows**. Dataset-driven tests will expand several groups into multiple cases.

## 1. Folder Overview

### Responsibilities

The folder exposes three API surfaces:

- Company-admin APIs authenticated with a customer JWT: CSC access approval, security questionnaire and evidence; API-client provisioning; redirect URI and webhook management; integration dashboards; delivery logs; ledgers; developer packs; and credential revocation.
- Integrator APIs authenticated with a Keycloak `client_credentials` bearer: enrollment creation/retrieval; credential issuance, lookup and revocation; signing-session creation, retrieval, cancellation, SMS and signing; enrollment photos; and webhook test delivery.
- Anonymous token-bound APIs: hosted SMS/TOTP enrollment, hosted and one-shot signing, document review, and PDF/image streaming.

### Main data flows

```text
Company admin
  ├─ access request → questionnaire/evidence → staff approval
  ├─ API client → Keycloak client + service principal
  ├─ redirect URI/webhook configuration
  └─ dashboards, deliveries, credentials and allowance ledgers

Integrator
  ├─ enrollment → hosted subject flow → Checkin IDV
  │    └─ verified identity → CSC credential issuance
  └─ signing session
       ├─ partner SMS/TOTP
       ├─ hosted SMS/TOTP
       └─ one-shot email PIN → IDV → transient certificate → automatic signing

Side effects
  ├─ database state transitions and allowance consumption
  ├─ Keycloak client changes
  ├─ Checkin, EJBCA and SignServer calls
  ├─ SMS and email
  ├─ signed/unsigned files
  ├─ webhook delivery/retry
  └─ audit/security logs
```

### Important dependencies

- Base controller: `public/system/engine/publicapicontroller.php`
- Routing: `public/catalog/controller/startup/router.php` and `seo_url.php`
- Services: `CscSigningSessionService`, `CscCredentialIssuance`, `CscApiMetering`, `CscIntegratorAuth`, `CscWebhookDispatcher`, `CscKeycloakProvisioner`
- Models: `csc/api_client`, `company_access`, `enrollment_session`, `signer`, `credential`, `signing_session`, `redirect_uri`, `webhook_endpoint`, `webhook_delivery`, `csc/integration/*`, and billing entitlement grant/usage models
- External boundaries: Keycloak/JWKS, Checkin, EJBCA, SignServer, SmsAlert, email queue, outbound HTTPS webhooks, local storage, and `ZipArchive`

### Existing coverage

The repository uses Pest 3 with separate `tests/Unit` and `tests/Feature` suites. Existing controller tests use lightweight registries, model stubs, subclassed controllers, and HTTP helpers.

Confirmed CSC-specific coverage: **none**. Repository search found no test referencing a CSC controller or CSC HTTP route. `tests/Feature/Api/Signing/SigningFlowTest.php` covers the older signing API, not CSC.

## 2. Coverage Gaps

- All 28 controllers are untested.
- `publicapi/v1/csc/sign` is rewritten by the router but absent from `PublicAPIController`'s anonymous allowlist; hosted signing may be rejected before reaching its controller.
- `publicapi/v1/csc/signers/credential/revoke` is absent from the integrator bearer bypass/anonymous allowlist even though the controller performs `CscIntegratorAuth` itself.
- No coverage exists for enrollment SMS/TOTP selection, expiry, replay, PIN rollback, or IDV readiness.
- No tests protect signing concurrency, idempotency, partial document failure, allowance debit-once behavior, or file cleanup.
- No tenant-isolation tests cover enrollments, credentials, photos, signing sessions, webhook endpoints or deliveries.
- API-client `PATCH` persists DB status before Keycloak synchronization and ignores failure from `setClientEnabled()`, allowing divergence.
- Evidence upload checks extension but does not reject mismatched detected MIME.
- Enrollment-photo containment uses a string-prefix check without a path-separator boundary.
- Several list endpoints silently normalize invalid sort/order/date input. Unknown enrollment/credential filters may be silently ignored.
- `integration/signing_sessions.php` excludes domain statuses `cancelled` and `pending_enrollment` from accepted filters.
- Streaming methods use `header()`, `readfile()`, `echo`, and `exit`, making conventional unit tests difficult.
- Auth, signing, Keycloak, HTTP, SMS, email PIN, webhook, credential, TOTP, time and randomness dependencies are directly constructed or static.

## 3. Phase 1 — Unit Tests

General rules:

- Call public controller methods only; exercise private helpers through observable entry points.
- Capture `sendResponse()` in a test subclass and avoid real output/exit.
- Use datasets for unauthenticated, missing company/role, non-admin, permission-denied and unsupported-method cases.
- Existing coverage for every case below is **none**.

### `access.php` — `ControllerPublicAPIV1CscAccess`

#### `access_status_returns_policy_metering_and_allowance_snapshot`

- Production file: `catalog/controller/publicapi/v1/csc/access.php`
- Suggested test file: `tests/Unit/Api/Csc/AccessTest.php`
- Method or behavior: `index()` status payload.
- Scenario: No access row plus approved/rejected/suspended and each metering mode.
- Setup and mocks: Customer/admin context; company-access and entitlement models; metering wrapper.
- Action: Call `index()`.
- Assertions: State, effective flags, request/provision decisions, Zulu dates, subscription, blocked reason and three allowance balances.
- Edge cases: No questionnaire/evidence, invalid dates, zero allowance, unmetered company.
- Priority: Critical
- Existing coverage: None.
- Notes: Static metering calls need an injectable wrapper for strict isolation.

#### `access_questionnaire_returns_schema_saved_answers_evidence_and_dpa`

- Production file: `access.php`
- Suggested test file: `tests/Unit/Api/Csc/AccessQuestionnaireTest.php`
- Method or behavior: `questionnaire()`.
- Scenario: Latest questionnaire present/absent with evidence and legal documents.
- Setup and mocks: Company-access model and legal-document provider.
- Action: Call `questionnaire()`.
- Assertions: Empty answers are an object; schema/version/timestamps are correct; stored paths and hashes are absent.
- Edge cases: Saved schema differs from current; zero evidence; no legal documents.
- Priority: High
- Existing coverage: None.
- Notes: Assert behavior, not schema field ordering.

#### `access_request_validates_state_answers_and_dpa`

- Production file: `access.php`
- Suggested test file: `tests/Unit/Api/Csc/AccessRequestTest.php`
- Method or behavior: `request()` validation.
- Scenario: Master disabled, approved/pending states, missing/invalid answers, no DPA, stale acceptance and valid acceptance.
- Setup and mocks: Company-access model; questionnaire/legal seams.
- Action: Submit representative bodies.
- Assertions: Exact 403/409/422; `submitRequest()` never runs on failure; pending DPA data is returned when relevant.
- Edge cases: Unknown keys, new DPA revision, fallback contact email.
- Priority: Critical
- Existing coverage: None.
- Notes: No configured DPA currently does not block submission.

#### `access_request_persists_normalized_submission_and_best_effort_notifications`

- Production file: `access.php`
- Suggested test file: `tests/Unit/Api/Csc/AccessRequestTest.php`
- Method or behavior: Successful request.
- Scenario: Valid answers/DPA and notification success/failure.
- Setup and mocks: `submitRequest()` ID; legal acceptance and email fakes.
- Action: POST request.
- Assertions: 201; normalized answers/schema metadata; legal acceptances once; questionnaire ID and status returned.
- Edge cases: Multiple DPA versions, empty contact email, email exception.
- Priority: Critical
- Existing coverage: None.
- Notes: Email failure must not change the 201 result.

#### `access_evidence_upload_and_delete_enforce_file_and_state_rules`

- Production file: `access.php`
- Suggested test file: `tests/Unit/Api/Csc/AccessEvidenceTest.php`
- Method or behavior: Evidence upload/delete.
- Scenario: 25-file cap; missing/upload-error/zero/oversized/unsupported file; storage failure; success; missing/foreign/locked deletion.
- Setup and mocks: Temporary directory, deterministic UUID, company-access model.
- Action: POST or DELETE evidence.
- Assertions: Correct status/error; hash and metadata persisted; path hidden; owned file removed only in allowed states.
- Edge cases: Exact 20 MB, 255-character filename, uppercase extension, MIME mismatch, stored `..` path.
- Priority: Critical
- Existing coverage: None.
- Notes: MIME mismatch is a security regression case; current code appears to accept it.

### `api_client.php` — `ControllerPublicAPIV1CscApiClient`

#### `api_client_routes_crud_and_rotate_secret_paths`

- Production file: `catalog/controller/publicapi/v1/csc/api_client.php`
- Suggested test file: `tests/Unit/Api/Csc/ApiClientRoutingTest.php`
- Method or behavior: `index()` and `rotate_secret()` routing.
- Scenario: GET/POST/PATCH plus rotate detection through `route` and `_route_`.
- Setup and mocks: Admin and route-permission recorder.
- Action: Invoke route/method matrix.
- Assertions: Correct permission path/verb; unsupported methods return 405 with correct headers.
- Edge cases: Unrelated substring containing `rotate_secret`; absent method.
- Priority: High
- Existing coverage: None.
- Notes: Characterize substring-based rotate detection.

#### `api_client_create_handles_defaults_keycloak_and_persistence_rollback`

- Production file: `api_client.php`
- Suggested test file: `tests/Unit/Api/Csc/ApiClientCreateTest.php`
- Method or behavior: Provisioning.
- Scenario: Rate limit, duplicate, default name, Keycloak unconfigured/create failure, DB exception/rollback, success.
- Setup and mocks: API-client/company models; Keycloak, UUID, service-principal and email seams.
- Action: POST create.
- Assertions: Exact errors; rollback only after DB failure; secret exposed once; audit/email omit secret.
- Edge cases: Empty company/name and absent cache.
- Priority: Critical
- Existing coverage: None.
- Notes: Add a protected Keycloak factory.

#### `api_client_patch_validates_fields_and_synchronizes_keycloak`

- Production file: `api_client.php`
- Suggested test file: `tests/Unit/Api/Csc/ApiClientPatchTest.php`
- Method or behavior: Name/status patch.
- Scenario: Unprovisioned, invalid/empty update, name only, active/disabled, combined update and Keycloak failure.
- Setup and mocks: Client model and Keycloak fake.
- Action: PATCH.
- Assertions: Only supplied fields update; disable audit occurs; refreshed overview returned.
- Edge cases: Empty name with valid status; repeated status.
- Priority: Critical
- Existing coverage: None.
- Notes: Current code ignores Keycloak failure; test should expose DB/Keycloak divergence.

#### `api_client_rotate_secret_enforces_totp_state_rate_limit_and_notifications`

- Production file: `api_client.php`
- Suggested test file: `tests/Unit/Api/Csc/ApiClientRotateSecretTest.php`
- Method or behavior: Secret rotation.
- Scenario: Method/rate/client/status/TOTP failures; incompatible client; empty secret; success.
- Setup and mocks: Customer TOTP, API-client model, Keycloak/audit/email fakes.
- Action: POST rotate.
- Assertions: Correct 405/409/422/429/502; secret only on success; no secret in audit/email.
- Edge cases: `totp` fallback key, no customer email, logger/email exception.
- Priority: Critical
- Existing coverage: None.
- Notes: Generate current TOTP with the existing helper algorithm.

### `dev_pack.php` — `ControllerPublicAPIV1CscDevPack`

#### `dev_pack_index_lists_every_supported_resource`

- Production file: `catalog/controller/publicapi/v1/csc/dev_pack.php`
- Suggested test file: `tests/Unit/Api/Csc/DevPackTest.php`
- Method or behavior: Index response and guards.
- Scenario: Provisioned admin, absent integration, unsupported method.
- Setup and mocks: Admin and client row.
- Action: GET without item.
- Assertions: Every static item plus environment and zip appears once.
- Edge cases: OPTIONS/method path.
- Priority: Medium
- Existing coverage: None.
- Notes: Compare manifest entries to real source files.

#### `dev_pack_personalizes_environment_without_secrets_and_builds_reference`

- Production file: `dev_pack.php`
- Suggested test file: `tests/Unit/Api/Csc/DevPackContentTest.php`
- Method or behavior: Environment/static/reference content.
- Scenario: Redirect/client overrides, missing redirect, malformed/missing source.
- Setup and mocks: Redirect model, fixture docs and stream capture.
- Action: Download representative items.
- Assertions: Valid JSON/HTML; client ID/redirect inserted; no secret; safe inline spec; correct headers.
- Edge cases: `</script>` in spec and Unicode client name.
- Priority: High
- Existing coverage: None.
- Notes: Extract `sendFile()` to avoid `exit()`.

#### `dev_pack_zip_cleans_temporary_files_on_success_and_failure`

- Production file: `dev_pack.php`
- Suggested test file: `tests/Unit/Api/Csc/DevPackZipTest.php`
- Method or behavior: ZIP creation.
- Scenario: Missing extension, open failure, missing member, invalid environment, empty read, success.
- Setup and mocks: Temporary directory/docs fixtures.
- Action: GET zip.
- Assertions: Expected entries exactly once; temp file removed; any incomplete bundle returns 500.
- Edge cases: Empty readable source.
- Priority: High
- Existing coverage: None.
- Notes: Requires filesystem/stream seams.

### `enroll.php` — `ControllerPublicAPIV1CscEnroll`

#### `hosted_enrollment_routes_actions_and_handles_missing_or_expired_tokens`

- Production file: `catalog/controller/publicapi/v1/csc/enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentRoutingTest.php`
- Method or behavior: Routing and session lookup.
- Scenario: Every action mapping; missing, unknown, not-found, active-expired and terminal-expired tokens.
- Setup and mocks: Enrollment model and request matrix.
- Action: Call `index()`.
- Assertions: Correct handler/model path and 400/404/410/405.
- Edge cases: Whitespace token; expired verified/declined/failed remains readable.
- Priority: Critical
- Existing coverage: None.
- Notes: Hyphen rewriting belongs in route integration tests.

#### `hosted_enrollment_load_choose_sca_and_accept_terms_follow_state_rules`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentTermsTest.php`
- Method or behavior: Load, choose SCA, accept terms.
- Scenario: Legal payload; valid/invalid method/state/legal data; existing/new verification.
- Setup and mocks: Legal, Checkin and enrollment seams.
- Action: GET load and POST actions.
- Assertions: Choice persisted; verification created once; accepted UUIDs saved; correct public state.
- Edge cases: Single method, malformed body, repeated acceptance.
- Priority: Critical
- Existing coverage: None.
- Notes: Test only through public actions.

#### `hosted_enrollment_send_sms_validates_phone_configuration_and_rate_limit`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentSmsTest.php`
- Method or behavior: SMS send.
- Scenario: Terms/SCA/rate/phone/config/provider failures and success.
- Setup and mocks: Phone, SMS, OTP/clock, cache and language seams.
- Action: POST `send_sms`.
- Assertions: E.164/localized message sent; only hash/expiry persisted; failure does not mutate state.
- Edge cases: Default TTL/rate and validation-code template variants.
- Priority: Critical
- Existing coverage: None.
- Notes: Replace random/static SMS dependencies.

#### `hosted_enrollment_verify_sms_rejects_expired_or_wrong_codes_and_activates_sms`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentSmsTest.php`
- Method or behavior: SMS verify.
- Scenario: Missing, absent hash, UTC-expired, wrong and correct code.
- Setup and mocks: Fixed clock; token-bound hash; signer/session models.
- Action: POST `verify_sms`.
- Assertions: Correct error; success marks verified and activates SMS as sole SCA.
- Edge cases: Exact expiry and non-UTC PHP timezone.
- Priority: Critical
- Existing coverage: None.
- Notes: UTC handling is a regression target.

#### `hosted_enrollment_email_pin_reserves_reverts_and_verifies`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentEmailPinTest.php`
- Method or behavior: PIN send/verify.
- Scenario: Gate/email/rate failures; queue rollback; success; missing/expired/wrong/correct PIN.
- Setup and mocks: Email, deterministic PIN/clock and session model.
- Action: POST send/verify.
- Assertions: Hash binds to token; failed queue releases slot; success verifies email.
- Edge cases: Locale/default TTL/rate/exact expiry.
- Priority: Critical
- Existing coverage: None.
- Notes: Wrap static email call.

#### `hosted_enrollment_totp_setup_and_verify_establish_single_sca`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentTotpTest.php`
- Method or behavior: TOTP gates/setup/verify.
- Scenario: Wrong state/SCA/email; generation failure; missing setup/code; bad/good code.
- Setup and mocks: TOTP factory, session/signer models and issuer config.
- Action: POST setup/verify.
- Assertions: Correct URI; pending secret saved; success activates TOTP and establishes SCA.
- Edge cases: Default issuer/label and tolerance.
- Priority: Critical
- Existing coverage: None.
- Notes: Secret is returned only during setup.

#### `hosted_enrollment_checkin_status_redirect_and_language_decisions`

- Production file: `enroll.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedEnrollmentCompletionTest.php`
- Method or behavior: Checkin token, status, redirect ack, language and cache.
- Scenario: Not ready; Checkin non-2xx/malformed/exception/success; redirect due/not due/already acked; client secret absent/present.
- Setup and mocks: HTTP/API-client/Keycloak/redirect/cache/language seams.
- Action: GET checkin/status; POST ack.
- Assertions: SDK headers; nullable ID flow; safe redirect; mark once; known locales only.
- Edge cases: TOTP error code, absent cache and default timing.
- Priority: Critical
- Existing coverage: None.
- Notes: Direct Guzzle and redirect statics need factories.

### `enrollment_photos.php` — `ControllerPublicAPIV1CscEnrollmentPhotos`

#### `enrollment_photos_enforce_integrator_auth_metering_and_tenant_ownership`

- Production file: `catalog/controller/publicapi/v1/csc/enrollment_photos.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentPhotosTest.php`
- Method or behavior: Request guards.
- Scenario: Method/auth/client/gate failures; bad kind/UUID; foreign/missing/non-verified enrollment.
- Setup and mocks: Auth/metering/session seams.
- Action: GET photo.
- Assertions: Exact errors; no downstream lookup after a failed guard.
- Edge cases: Front/back/case/empty identifiers.
- Priority: Critical
- Existing coverage: None.
- Notes: Foreign/non-verified resources must not disclose file existence.

#### `enrollment_photos_resolve_only_stored_upload_codes_and_safe_image_files`

- Production file: `enrollment_photos.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentPhotosStreamingTest.php`
- Method or behavior: Verification/upload/path/MIME/streaming.
- Scenario: Missing verification, invalid JSON/raw URL, absent upload/file, symlink escape and valid image.
- Setup and mocks: Temp upload root, Checkin/upload models and response seam.
- Action: Request photo.
- Assertions: Correct code/path; image MIME; no-store; correct bytes.
- Edge cases: Sibling path sharing root prefix, no `finfo`, relative path.
- Priority: Critical
- Existing coverage: None.
- Notes: Use a separator-aware containment assertion.

#### `first_upload_code_from_json_returns_first_trimmed_string_only`

- Production file: `enrollment_photos.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentPhotosHelperTest.php`
- Method or behavior: Protected helper.
- Scenario: Null/empty/malformed/scalar/object/empty/non-string/multiple values.
- Setup and mocks: Test subclass exposing helper.
- Action: Dataset invocation.
- Assertions: First trimmed non-empty string or empty string.
- Edge cases: Raw vendor URL and whitespace.
- Priority: Medium
- Existing coverage: None.
- Notes: No reflection required.

### `enrollments.php` — `ControllerPublicAPIV1CscEnrollments`

#### `integrator_enrollments_enforce_auth_metering_and_method_routing`

- Production file: `catalog/controller/publicapi/v1/csc/enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentsRoutingTest.php`
- Method or behavior: `index()`.
- Scenario: Bearer/client/gate failure; GET/POST/unsupported method; non-array body.
- Setup and mocks: Auth/metering factories.
- Action: Request matrix.
- Assertions: Correct status and client ID/row forwarding.
- Edge cases: Missing method.
- Priority: Critical
- Existing coverage: None.
- Notes: Direct auth construction needs a seam.

#### `enrollment_create_validates_redirect_email_sca_flow_and_host_configuration`

- Production file: `enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentsCreateValidationTest.php`
- Method or behavior: Create validation.
- Scenario: Required, malformed/unallowlisted redirect, bad timing/email/SCA/ID flow, TOTP without email, no host and invalid signer.
- Setup and mocks: Config, redirect/signer models and validator seam.
- Action: POST.
- Assertions: Exact error; no session/allowance call before successful validation.
- Edge cases: CSV/duplicate methods, explicit null flow, 100/101 chars, localhost policy.
- Priority: Critical
- Existing coverage: None.
- Notes: Cover default SMS and client redirect timing.

#### `enrollment_create_new_session_checks_allowance_uses_ttl_and_dispatches_event`

- Production file: `enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentsCreateTest.php`
- Method or behavior: New-session path.
- Scenario: Allowance denied, default/configured TTL, persistence failure and success.
- Setup and mocks: Models, metering, event and fixed session.
- Action: POST valid body.
- Assertions: 201; exact create data/host URL; one `application.created` event.
- Edge cases: Hosted path without slash and TTL below 300.
- Priority: Critical
- Existing coverage: None.
- Notes: Billing debit occurs at terminal IDV, not create.

#### `enrollment_create_resumes_existing_session_without_recharging`

- Production file: `enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentsResumeTest.php`
- Method or behavior: Resume.
- Scenario: Update permitted fields/SCA set; missing reload; stored/new email for TOTP.
- Setup and mocks: Existing and refreshed session.
- Action: Repeat POST for signer.
- Assertions: 200/resumed; no allowance check or duplicate event.
- Edge cases: Omitted email, explicit null flow, omitted SCA list.
- Priority: Critical
- Existing coverage: None.
- Notes: Critical idempotency/billing regression.

#### `enrollment_get_serializes_status_credential_identity_and_photo_links`

- Production file: `enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentsGetTest.php`
- Method or behavior: GET payload.
- Scenario: Missing/foreign; optional timestamps; credential none/failed/issued; unverified/verified identity; missing identity row.
- Setup and mocks: Session, credential, Checkin models and HTTPS base.
- Action: GET UUID.
- Assertions: Tenant scope; Zulu times; conditional failure/credential/identity/image fields.
- Edge cases: Zero dates, null identity values, CNP county and malformed upload JSON.
- Priority: High
- Existing coverage: None.
- Notes: Never expose raw Checkin URLs.

### `enrollments/credentials.php` — `ControllerPublicAPIV1CscEnrollmentsCredentials`

#### `credential_issue_endpoint_validates_guards_uuid_email_and_error_mapping`

- Production file: `catalog/controller/publicapi/v1/csc/enrollments/credentials.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentCredentialIssueTest.php`
- Method or behavior: Validation/error mapping.
- Scenario: Method/auth/client/gate/UUID/email failures and every issuance error.
- Setup and mocks: Auth/metering/issuer factories.
- Action: POST.
- Assertions: Confirmed 404/422/409/502/500/default 400 mappings.
- Edge cases: Empty email, non-array body, `sam_keypair_invalid` currently defaulting to 400.
- Priority: Critical
- Existing coverage: None.
- Notes: Characterize unmapped service codes.

#### `credential_issue_endpoint_returns_public_credential_with_enrollment_uuid`

- Production file: `enrollments/credentials.php`
- Suggested test file: `tests/Unit/Api/Csc/EnrollmentCredentialIssueTest.php`
- Method or behavior: Successful issuance.
- Scenario: New and idempotently existing credential.
- Setup and mocks: Issuer returns public data.
- Action: POST.
- Assertions: Enrollment UUID injected; private key/SAM/JWS material absent.
- Edge cases: Null public data.
- Priority: Critical
- Existing coverage: None.
- Notes: Persistence is verified in integration tests.

### Integration dashboard controllers

#### `integration_overview_enforces_guards_and_returns_clamped_period_result`

- Production file: `integration/overview.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationOverviewTest.php`
- Method or behavior: `index()`.
- Scenario: Auth/context/admin/method/permission/client guards and period absent/0/negative/>365.
- Setup and mocks: Admin, API-client and overview models.
- Action: GET.
- Assertions: Correct client ID/raw period forwarded; model result returned unchanged.
- Edge cases: The model, not controller, currently clamps to 1..365.
- Priority: High
- Existing coverage: None.
- Notes: Decide later whether silent clamping should become validation.

#### `integration_enrollments_enforces_admin_and_provisioning_guards`

- Production file: `integration/enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationEnrollmentsTest.php`
- Method or behavior: Precondition gates.
- Scenario: Unauthenticated, no company/role, non-admin, wrong method, permission denied, no client.
- Setup and mocks: Customer, entitlement and API-client models.
- Action: GET.
- Assertions: Exact 401/400/403/405/409 and no integration-model call.
- Edge cases: OPTIONS through `checkPlugin()`.
- Priority: High
- Existing coverage: None.
- Notes: Reuse a shared admin dataset.

#### `integration_enrollments_returns_scoped_detail_or_paginated_list`

- Production file: `integration/enrollments.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationEnrollmentsTest.php`
- Method or behavior: Detail/list.
- Scenario: Detail found/missing; paging/search/sort/status/credential/signer/stuck/date filters.
- Setup and mocks: Integration enrollment model capturing options.
- Action: GET collection/detail.
- Assertions: 404 or data; exact options and pagination.
- Edge cases: Boolean spellings, invalid date and unknown status currently passed/ignored.
- Priority: High
- Existing coverage: None.
- Notes: Characterize silent filter behavior.

#### `integration_signers_enforces_admin_and_provisioning_guards`

- Production file: `integration/signers.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationSignersTest.php`
- Method or behavior: Preconditions.
- Scenario: Shared admin/method/permission/client failures.
- Setup and mocks: Customer, entitlement and API-client models.
- Action: GET.
- Assertions: Correct errors and short-circuit.
- Edge cases: Missing role/company independently.
- Priority: High
- Existing coverage: None.
- Notes: Dataset-driven.

#### `integration_signers_returns_scoped_detail_or_filtered_list`

- Production file: `integration/signers.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationSignersTest.php`
- Method or behavior: Detail/list.
- Scenario: Found/missing detail; pagination/search/sorts and `has_valid_credential`.
- Setup and mocks: Signer integration model.
- Action: GET.
- Assertions: Client scope, option forwarding and pagination.
- Edge cases: Boolean strings are forwarded without normalization.
- Priority: High
- Existing coverage: None.
- Notes: Integration tests establish accepted boolean forms.

#### `integration_credentials_enforces_admin_and_provisioning_guards`

- Production file: `integration/credentials.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationCredentialsTest.php`
- Method or behavior: Preconditions.
- Scenario: Shared auth/context/admin/method/permission/client failures.
- Setup and mocks: Customer, entitlement and client models.
- Action: GET.
- Assertions: Exact response and no credential model use.
- Edge cases: Missing method.
- Priority: High
- Existing coverage: None.
- Notes: Shared harness.

#### `integration_credentials_validates_status_and_returns_detail_or_list`

- Production file: `integration/credentials.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationCredentialsTest.php`
- Method or behavior: Detail/list.
- Scenario: Detail found/missing; valid/expired/revoked/superseded/invalid status; date/signer/expiry filters.
- Setup and mocks: Credential integration model.
- Action: GET.
- Assertions: Detail has `totp_required=true`; invalid status 400; correct options/pagination.
- Edge cases: Negative and >365 expiry days; invalid dates.
- Priority: High
- Existing coverage: None.
- Notes: Model clamps positive expiry days to 365.

#### `integration_signing_sessions_enforces_admin_and_provisioning_guards`

- Production file: `integration/signing_sessions.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationSigningSessionsTest.php`
- Method or behavior: Preconditions.
- Scenario: Shared guard failures.
- Setup and mocks: Customer, entitlement and client models.
- Action: GET.
- Assertions: Exact errors and short-circuit.
- Edge cases: OPTIONS/missing method.
- Priority: High
- Existing coverage: None.
- Notes: Shared dataset.

#### `integration_signing_sessions_validates_status_and_returns_detail_or_list`

- Production file: `integration/signing_sessions.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationSigningSessionsTest.php`
- Method or behavior: Detail/list.
- Scenario: Detail found/missing; every accepted/invalid status and date/signer/sort filters.
- Setup and mocks: Signing integration model.
- Action: GET.
- Assertions: Invalid status 400; scoped ID/options/pagination correct.
- Edge cases: `cancelled` and `pending_enrollment` are currently rejected.
- Priority: High
- Existing coverage: None.
- Notes: Flag domain/filter mismatch for product review.

#### `integration_credential_revoke_validates_totp_reason_and_result_states`

- Production file: `integration/credentials/revoke.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationCredentialRevokeTest.php`
- Method or behavior: Admin revocation.
- Scenario: Guards/UUID/TOTP; numeric/symbolic/default/invalid reason; exception/not-found/not-revocable/revoked/already states.
- Setup and mocks: Credential model, reason table and event seam.
- Action: POST.
- Assertions: Correct statuses; source `company_admin`; event only on first revocation.
- Edge cases: Negative and unknown symbolic reason (currently falls back to 5).
- Priority: Critical
- Existing coverage: None.
- Notes: Unknown reason behavior is surprising and must be characterized.

#### `integration_credential_revoke_serializes_idempotent_response`

- Production file: `integration/credentials/revoke.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegrationCredentialRevokeTest.php`
- Method or behavior: Response mapping.
- Scenario: Revoked/already revoked/superseded rows.
- Setup and mocks: Controlled dates/reasons.
- Action: POST.
- Assertions: Zulu date, reason precedence, UUID/status and `already_revoked` flag.
- Edge cases: Missing row fields/invalid date.
- Priority: High
- Existing coverage: None.
- Notes: Never expose certificate/JWS internals.

### Integrator utility controllers

#### `integrator_ping_accepts_only_valid_active_client_credentials`

- Production file: `integrator/ping.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegratorPingTest.php`
- Method or behavior: Ping.
- Scenario: Wrong method, missing/invalid bearer and valid client.
- Setup and mocks: Auth factory.
- Action: GET.
- Assertions: 405/401 or `{ok:true, api_client_uuid}`; no metering.
- Edge cases: Row without UUID.
- Priority: High
- Existing coverage: None.
- Notes: Controller intentionally does not call `checkPlugin()`.

#### `integrator_webhook_test_enforces_auth_metering_and_dispatches_once`

- Production file: `integrator/webhooks/test.php`
- Suggested test file: `tests/Unit/Api/Csc/IntegratorWebhookTest.php`
- Method or behavior: Test event.
- Scenario: Method/auth/client/gate failures, success and dispatcher exception.
- Setup and mocks: Auth/metering/dispatcher factories.
- Action: POST.
- Assertions: Dispatcher receives client ID once; stable success payload.
- Edge cases: Exception currently propagates.
- Priority: High
- Existing coverage: None.
- Notes: Decide whether exception should map to 502.

### Ledger controllers

#### `ledger_grants_enforces_admin_guards_and_validates_query`

- Production file: `ledger_grants.php`
- Suggested test file: `tests/Unit/Api/Csc/LedgerGrantsTest.php`
- Method or behavior: Guards/query validation.
- Scenario: Auth/context/permission/admin failures; invalid sort/order/state; page boundaries.
- Setup and mocks: Entitlement models.
- Action: GET.
- Assertions: Existing `authentication_required`; 422 errors; per-page max 100.
- Edge cases: Empty search/state and lowercase order.
- Priority: High
- Existing coverage: None.
- Notes: Preserve or explicitly change inconsistent auth error later.

#### `ledger_grants_maps_quantities_activity_and_pagination`

- Production file: `ledger_grants.php`
- Suggested test file: `tests/Unit/Api/Csc/LedgerGrantsTest.php`
- Method or behavior: Serialization.
- Scenario: Active/future/expired/over-consumed/null rows.
- Setup and mocks: Fixed clock and model result.
- Action: GET.
- Assertions: Nonnegative remaining, correct active flag/Zulu dates/pagination.
- Edge cases: Start/expiry exactly now.
- Priority: High
- Existing coverage: None.
- Notes: Clock seam required.

#### `ledger_consumption_validates_complete_iso_date_range_and_query`

- Production file: `ledger_consumption.php`
- Suggested test file: `tests/Unit/Api/Csc/LedgerConsumptionTest.php`
- Method or behavior: Date/list validation.
- Scenario: One-sided/invalid/valid offset range; invalid sort/order and bounds.
- Setup and mocks: Usage model/date converter.
- Action: GET.
- Assertions: 422 for incomplete/invalid; SQL UTC forwarded.
- Edge cases: Empty strings and reversed range currently accepted.
- Priority: High
- Existing coverage: None.
- Notes: Add requirement decision for reversed ranges.

#### `ledger_consumption_maps_attribution_references_and_pagination`

- Production file: `ledger_consumption.php`
- Suggested test file: `tests/Unit/Api/Csc/LedgerConsumptionTest.php`
- Method or behavior: Usage serialization.
- Scenario: Attributed/orphan, null references and missing date.
- Setup and mocks: Paged rows.
- Action: GET.
- Assertions: Typed quantity, nullable refs, attribution, pagination.
- Edge cases: Missing date currently becomes empty string.
- Priority: Medium
- Existing coverage: None.
- Notes: Characterize current response.

### `redirect_uris.php` — `ControllerPublicAPIV1CscRedirectUris`

#### `redirect_uri_endpoint_enforces_admin_method_and_permission_matrix`

- Production file: `catalog/controller/publicapi/v1/csc/redirect_uris.php`
- Suggested test file: `tests/Unit/Api/Csc/RedirectUrisTest.php`
- Method or behavior: Guards and routing.
- Scenario: Auth/context/admin/permission/method/unprovisioned failures.
- Setup and mocks: Admin/models.
- Action: GET/POST/DELETE matrix.
- Assertions: Correct permission verb and no mutation on failure.
- Edge cases: OPTIONS.
- Priority: High
- Existing coverage: None.
- Notes: Shared admin dataset.

#### `redirect_uri_create_requires_totp_normalizes_and_rejects_duplicates`

- Production file: `redirect_uris.php`
- Suggested test file: `tests/Unit/Api/Csc/RedirectUrisMutationTest.php`
- Method or behavior: Create.
- Scenario: TOTP/URL/local-host/duplicate/success cases.
- Setup and mocks: TOTP harness and redirect model.
- Action: POST.
- Assertions: Normalized URI; null empty label; duplicate 409.
- Edge cases: 2048/2049 chars, query/fragment and localhost variants.
- Priority: Critical
- Existing coverage: None.
- Notes: HTTPS only except configured localhost HTTP.

#### `redirect_uri_list_and_delete_are_tenant_scoped`

- Production file: `redirect_uris.php`
- Suggested test file: `tests/Unit/Api/Csc/RedirectUrisMutationTest.php`
- Method or behavior: List/delete.
- Scenario: List, UUID body/query, missing/foreign/not-found/success.
- Setup and mocks: Tenant model.
- Action: GET/DELETE.
- Assertions: `totp_required`; scoped ID; `deleted=true`.
- Edge cases: Body UUID precedence.
- Priority: High
- Existing coverage: None.
- Notes: No unrelated secret fields.

### `review.php` — `ControllerPublicAPIV1CscReview`

#### `review_endpoint_validates_method_token_and_rate_limit`

- Production file: `catalog/controller/publicapi/v1/csc/review.php`
- Suggested test file: `tests/Unit/Api/Csc/ReviewTest.php`
- Method or behavior: Entry guards.
- Scenario: Non-GET, malformed token, absent cache and below/at limit.
- Setup and mocks: Cache/config.
- Action: Call `index()`.
- Assertions: 405/404/429; key varies by token/IP; default 60.
- Edge cases: Missing IP/nonnumeric count.
- Priority: Critical
- Existing coverage: None.
- Notes: Success token is 32 lowercase hex chars.

#### `review_endpoint_maps_service_results_and_routes_document_requests`

- Production file: `review.php`
- Suggested test file: `tests/Unit/Api/Csc/ReviewTest.php`
- Method or behavior: Review/file delegation.
- Scenario: Metadata/file success and failure.
- Setup and mocks: Signing service and stream seams.
- Action: GET root/file.
- Assertions: Correct arguments/result mapping/path/label.
- Edge cases: Missing document UUID and fallback error/http.
- Priority: High
- Existing coverage: None.
- Notes: Final service construction needs a factory.

#### `review_pdf_stream_sanitizes_filename_and_emits_cors_no_store_headers`

- Production file: `review.php`
- Suggested test file: `tests/Unit/Api/Csc/ReviewStreamTest.php`
- Method or behavior: PDF response.
- Scenario: Unsafe/empty/non-PDF labels and origin present/absent.
- Setup and mocks: Temp PDF and emitter seam.
- Action: Stream.
- Assertions: Safe `.pdf` basename, bytes/length, CORS and no-store.
- Edge cases: Unicode/separators/wildcard origin.
- Priority: High
- Existing coverage: None.
- Notes: Extract output from `exit()`.

### `sign.php` — `ControllerPublicAPIV1CscSign`

#### `hosted_signing_routes_valid_tokens_actions_and_rate_limits`

- Production file: `catalog/controller/publicapi/v1/csc/sign.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedSigningRoutingTest.php`
- Method or behavior: Routing.
- Scenario: Invalid token, rate limit, every action/file and unsupported combination.
- Setup and mocks: Service/cache/POST harness.
- Action: Request matrix.
- Assertions: Correct method/arguments; trimmed code; 404/429/405.
- Edge cases: Uppercase token, missing IP, default 120.
- Priority: Critical
- Existing coverage: None.
- Notes: Integration must prove anonymous reachability.

#### `hosted_signing_load_augments_one_shot_enrollment_without_leaking_email`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedSigningLoadTest.php`
- Method or behavior: Hosted load.
- Scenario: Normal hosted; one-shot with/without enrollment and different states.
- Setup and mocks: Service, enrollment and legal seams.
- Action: GET load.
- Assertions: Public state, masked email and conditional legal data; no full email.
- Edge cases: Invalid email, one-character local and missing UUID.
- Priority: High
- Existing coverage: None.
- Notes: Mask is first character + `***@domain`.

#### `hosted_signing_status_and_redirect_ack_are_signed_and_idempotent`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedSigningRedirectTest.php`
- Method or behavior: Status/ack.
- Scenario: Resolve failure, failure code, one-shot status, already acked, not due and due.
- Setup and mocks: Service/models/Keycloak/redirect seams.
- Action: GET status/POST ack.
- Assertions: Safe signed redirect; mark once; 409 when not due.
- Edge cases: Missing client secret/enrollment.
- Priority: Critical
- Existing coverage: None.
- Notes: Never return client secret.

#### `one_shot_accept_terms_creates_verification_and_records_legal_acceptance`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/OneShotTermsTest.php`
- Method or behavior: One-shot context/terms.
- Scenario: Resolve/wrong flow/not-ready/missing enrollment/state/legal failures and success.
- Setup and mocks: Service, enrollment, Checkin and legal seams.
- Action: POST accept.
- Assertions: Correct 409/422/500/400; verification once; `CSC_ONE_SHOT` acceptance.
- Edge cases: Repeated accepted state.
- Priority: Critical
- Existing coverage: None.
- Notes: Exercise context through public action.

#### `one_shot_email_pin_reservation_hash_expiry_and_sca_transition`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/OneShotEmailPinTest.php`
- Method or behavior: PIN send/verify.
- Scenario: Terms/email/rate/queue failures; send; missing/expired/wrong/correct PIN.
- Setup and mocks: Fixed PIN/clock, context, email/session seams.
- Action: POST send/verify.
- Assertions: Session locale; token-bound hash; email and SCA marked on success.
- Edge cases: Default rate/TTL and exact UTC expiry.
- Priority: Critical
- Existing coverage: None.
- Notes: Never persist/return raw PIN.

#### `one_shot_checkin_token_maps_state_and_external_failures`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/OneShotCheckinTest.php`
- Method or behavior: SDK token.
- Scenario: Created/terms/terminal/missing verification/non-2xx/malformed/exception/success.
- Setup and mocks: HTTP/context seam.
- Action: GET token.
- Assertions: State-specific 422; correct SDK call; nullable flow/token response.
- Edge cases: 2xx without token and invalid JSON.
- Priority: Critical
- Existing coverage: None.
- Notes: Add Guzzle factory.

#### `hosted_signing_stream_and_result_mapping_preserve_safe_response_contract`

- Production file: `sign.php`
- Suggested test file: `tests/Unit/Api/Csc/HostedSigningStreamTest.php`
- Method or behavior: Result map/PDF output.
- Scenario: Custom success, failure with data, fallback fields and safe/unsafe filename/CORS.
- Setup and mocks: Temp PDF/emitter.
- Action: Delegated action/file.
- Assertions: Failure data retained; correct fallback; safe PDF headers/body.
- Edge cases: Missing file is handled by service.
- Priority: High
- Existing coverage: None.
- Notes: Reuse review stream seam.

### Signer credential controllers

#### `signer_credential_lookup_enforces_integrator_tenant_scope_and_serializes_public_row`

- Production file: `signers/credential.php`
- Suggested test file: `tests/Unit/Api/Csc/SignerCredentialTest.php`
- Method or behavior: Lookup.
- Scenario: Method/auth/client/gate/missing signer/no credential/success.
- Setup and mocks: Auth/metering/credential model.
- Action: GET.
- Assertions: Scoped lookup; 404; public data + signer ID only.
- Edge cases: Encoded slash in signer ID via router.
- Priority: Critical
- Existing coverage: None.
- Notes: No SAM/private fields.

#### `signer_credential_revoke_validates_reason_and_model_outcomes`

- Production file: `signers/credential/revoke.php`
- Suggested test file: `tests/Unit/Api/Csc/SignerCredentialRevokeTest.php`
- Method or behavior: Integrator revoke.
- Scenario: Guards/signer/reason/no active/exception/not-found/not-revocable.
- Setup and mocks: Auth/metering/credential/reason seams.
- Action: POST.
- Assertions: Correct status and source `integrator`.
- Edge cases: Unknown symbol currently defaults to reason 5.
- Priority: Critical
- Existing coverage: None.
- Notes: Route reachability separately critical.

#### `signer_credential_revoke_dispatches_once_and_is_idempotent`

- Production file: `signers/credential/revoke.php`
- Suggested test file: `tests/Unit/Api/Csc/SignerCredentialRevokeTest.php`
- Method or behavior: Success mapping.
- Scenario: Revoked/already revoked/already superseded.
- Setup and mocks: Serializer/event seam.
- Action: POST.
- Assertions: Event only for first; signer ID and flag correct.
- Edge cases: Missing result row falls back to active row.
- Priority: Critical
- Existing coverage: None.
- Notes: Integrator M2M revocation currently has no TOTP.

### Signing-session integrator controllers

#### `signing_sessions_collection_routes_create_get_cancel_and_result_mapping`

- Production file: `signing_sessions.php`
- Suggested test file: `tests/Unit/Api/Csc/SigningSessionsTest.php`
- Method or behavior: Collection/detail routing.
- Scenario: Auth/client/gate; POST body; GET/DELETE UUID; service success/failure.
- Setup and mocks: Auth/metering/service factories.
- Action: POST/GET/DELETE.
- Assertions: Correct delegation and mapped status/error/data.
- Edge cases: Unsupported method and fallback fields.
- Priority: Critical
- Existing coverage: None.
- Notes: Service validation is integration scope.

#### `signing_sessions_get_parses_include_documents_strictly`

- Production file: `signing_sessions.php`
- Suggested test file: `tests/Unit/Api/Csc/SigningSessionsTest.php`
- Method or behavior: Signed-document inclusion flag.
- Scenario: Absent, `0`, `false`, `1`, `true`, uppercase and boolean values.
- Setup and mocks: Capturing service.
- Action: GET.
- Assertions: Only string `1`/lowercase `true` (and boolean true cast to `1`) enable it.
- Edge cases: Whitespace.
- Priority: High
- Existing coverage: None.
- Notes: Characterization test.

#### `signing_session_send_sms_rejects_non_partner_flows_and_maps_service`

- Production file: `signing_sessions/send_sms.php`
- Suggested test file: `tests/Unit/Api/Csc/SigningSessionSendSmsTest.php`
- Method or behavior: Partner SMS action.
- Scenario: Guards/UUID; hosted/one-shot; absent row delegated; service success/failure.
- Setup and mocks: Session model/service.
- Action: POST.
- Assertions: Non-partner 409 without service; mapped response otherwise.
- Edge cases: Missing flow defaults partner.
- Priority: Critical
- Existing coverage: None.
- Notes: Tenant lookup occurs before flow check.

#### `signing_session_sign_accepts_sms_or_otp_code_and_rejects_hosted_flow`

- Production file: `signing_sessions/sign.php`
- Suggested test file: `tests/Unit/Api/Csc/SigningSessionSignTest.php`
- Method or behavior: Partner sign.
- Scenario: Guards/UUID/hosted; SMS/OTP/both/no code; service outcomes.
- Setup and mocks: Session/service.
- Action: POST.
- Assertions: SMS key precedence; hosted 409; correct service call/result.
- Edge cases: Whitespace/non-array body.
- Priority: Critical
- Existing coverage: None.
- Notes: Empty code reaches service for SCA-specific error.

### Webhook delivery controllers

#### `webhook_deliveries_list_validates_filters_and_serializes_safe_rows`

- Production file: `webhook_deliveries.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhookDeliveriesTest.php`
- Method or behavior: List.
- Scenario: Guards; valid/invalid status; paging/search/sort/date/endpoint/event.
- Setup and mocks: Delivery model.
- Action: GET.
- Assertions: Correct options; no payload; typed endpoint and dates/pagination.
- Edge cases: Zero/malformed dates and defaulted sort.
- Priority: High
- Existing coverage: None.
- Notes: Invalid dates are currently omitted.

#### `webhook_delivery_detail_is_tenant_scoped_and_decodes_payload_safely`

- Production file: `webhook_deliveries.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhookDeliveriesTest.php`
- Method or behavior: Detail.
- Scenario: Missing/foreign; valid/scalar/malformed/empty JSON.
- Setup and mocks: Scoped row.
- Action: GET UUID.
- Assertions: 404 or normalized detail; invalid payload null.
- Edge cases: Null description/error/enrollment.
- Priority: High
- Existing coverage: None.
- Notes: No signing secret.

#### `webhook_delivery_resend_validates_endpoint_and_payload_before_dispatch`

- Production file: `webhook_deliveries/resend.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhookDeliveryResendTest.php`
- Method or behavior: Resend validation.
- Scenario: Guards/UUID/missing/disabled/invalid payload.
- Setup and mocks: Delivery/dispatcher.
- Action: POST.
- Assertions: 400/404/409/422; no dispatch on failure.
- Edge cases: Empty JSON object/array.
- Priority: Critical
- Existing coverage: None.
- Notes: No TOTP by current contract.

#### `webhook_delivery_resend_reports_fresh_attempt_state`

- Production file: `webhook_deliveries/resend.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhookDeliveryResendTest.php`
- Method or behavior: Resend result.
- Scenario: Delivered/failed, refreshed row present/absent, exception.
- Setup and mocks: Sequential model and dispatcher.
- Action: POST.
- Assertions: Exact body/URL/secret sent; fresh/fallback status/count/error returned.
- Edge cases: Exception currently propagates.
- Priority: High
- Existing coverage: None.
- Notes: Secret never appears in response.

### `webhooks.php` — `ControllerPublicAPIV1CscWebhooks`

#### `webhooks_endpoint_enforces_admin_permission_and_client_provisioning`

- Production file: `catalog/controller/publicapi/v1/csc/webhooks.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhooksTest.php`
- Method or behavior: Shared guards/routing.
- Scenario: Auth/context/admin/permission/method/unprovisioned cases.
- Setup and mocks: Admin/client models.
- Action: GET/POST/PATCH/DELETE.
- Assertions: Correct permission verb and no mutation after failure.
- Edge cases: OPTIONS.
- Priority: High
- Existing coverage: None.
- Notes: Shared harness.

#### `webhook_create_validates_totp_limit_https_events_and_description`

- Production file: `webhooks.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhooksMutationTest.php`
- Method or behavior: Create.
- Scenario: TOTP/limit/URL/event failures, normalization/deduplication and success.
- Setup and mocks: Model, event config and deterministic secret.
- Action: POST.
- Assertions: HTTPS; normalized events; null empty description; 64-hex secret persisted.
- Edge cases: 2048/2049 URL, HTTP localhost and duplicates.
- Priority: Critical
- Existing coverage: None.
- Notes: Confirm whether response returns secret only at create.

#### `webhook_patch_delete_and_list_are_tenant_scoped_and_totp_protected`

- Production file: `webhooks.php`
- Suggested test file: `tests/Unit/Api/Csc/WebhooksMutationTest.php`
- Method or behavior: List/update/delete.
- Scenario: UUID body/query; invalid fields; empty update; missing/foreign/success and enabled coercion.
- Setup and mocks: Scoped model/TOTP harness.
- Action: GET/PATCH/DELETE.
- Assertions: `totp_required`; only supplied fields update; correct 404/success.
- Edge cases: Empty description, body precedence and string `false` under `!empty()`.
- Priority: Critical
- Existing coverage: None.
- Notes: Boolean parsing is a characterization target.

## 4. Phase 2 — Integration Tests

Run integration tests locally against an isolated stack or in a dedicated non-production environment. Internal application components and the database should be real. Use deterministic local fakes or dedicated sandboxes for Keycloak/JWKS, Checkin, EJBCA, SignServer, SMS/email and webhooks.

HTTP tests cannot rely on a PHPUnit transaction because the application processes requests separately. Give each run unique company, client, signer, endpoint and document IDs, then clean them explicitly.

### `csc_routes_reach_expected_controllers_before_auth_rejection`

- Components: Startup router, SEO normalization, base controller and every CSC controller.
- Entry point: Canonical hyphenated CSC HTTP routes.
- Environment: Local/dedicated stack.
- Preconditions: Application booted.
- Test data: Valid-shape UUIDs and tokens.
- Steps: Exercise every canonical nested route with its expected method.
- Expected response: Controller validation/auth errors, never a generic route 404.
- Expected database state: Unchanged.
- Expected side effects: None.
- Failure cases: Hosted signing or integrator credential revoke blocked before controller.
- Cleanup: None.
- Parallel-safe: Yes.
- Priority: Critical
- Existing coverage: None.
- Notes: Confirms the two route allowlist/bypass gaps found in analysis.

### `csc_admin_endpoints_enforce_customer_company_admin_and_route_permissions`

- Components: JWT auth, company/role context, entitlement assignment and all admin CSC controllers.
- Entry point: Admin CSC routes.
- Environment: Dedicated test environment.
- Preconditions: Admin/member/no-company users and route permissions.
- Test data: Three JWT identities.
- Steps: Call each endpoint anonymously, as member, admin without permission and authorized admin.
- Expected response: 401/400/403 or normal endpoint response.
- Expected database state: No mutation on denial.
- Expected side effects: No external calls.
- Failure cases: Cross-company or non-admin access.
- Cleanup: Restore permissions.
- Parallel-safe: With worker-specific roles.
- Priority: Critical
- Existing coverage: None.
- Notes: Include ledgers' `authentication_required` compatibility behavior.

### `csc_access_request_questionnaire_dpa_and_evidence_lifecycle`

- Components: Access controller/model, legal documents, storage and email queue.
- Entry point: Access, questionnaire, request and evidence routes.
- Environment: Real DB/storage; captured email.
- Preconditions: Master enabled, company admin and current DPA.
- Test data: Valid/invalid questionnaire, PDF evidence and revised DPA.
- Steps: Upload, read, submit, resubmit pending, revise DPA and delete in allowed/locked states.
- Expected response: Correct 201/409/422 and safe metadata.
- Expected database state: Superseded questionnaires, request metadata, acceptances and evidence rows.
- Expected side effects: File lifecycle and two captured emails.
- Failure cases: Oversize/MIME mismatch/storage/email failure.
- Cleanup: Delete rows/files/messages.
- Parallel-safe: Yes with unique company/storage.
- Priority: Critical
- Existing coverage: None.
- Notes: Email failure must not roll back a valid request.

### `api_client_provision_create_patch_disable_and_rotate`

- Components: API-client controller/model, Keycloak, service principal, TOTP, email and audit.
- Entry point: API-client CRUD/rotate.
- Environment: Dedicated Keycloak realm or fake.
- Preconditions: Approved company with subscription.
- Test data: Unique company/client.
- Steps: GET absent, create, use credentials, rename, disable/re-enable and rotate.
- Expected response: Secret only on create/rotate and accurate overview.
- Expected database state: One client row and service principal.
- Expected side effects: Keycloak lifecycle, audit and emails.
- Failure cases: Keycloak create/enable/rotate and DB insert failures.
- Cleanup: Remove Keycloak/DB fixtures.
- Parallel-safe: Yes with unique clients.
- Priority: Critical
- Existing coverage: None.
- Notes: Assert DB/Keycloak consistency after enable failure.

### `redirect_uri_and_webhook_management_require_totp_and_tenant_scope`

- Components: Admin controllers/models, TOTP and validation.
- Entry point: Redirect URI/webhook CRUD.
- Environment: Real DB and generated TOTP.
- Preconditions: Two provisioned companies.
- Test data: HTTPS URLs and event sets.
- Steps: Owner CRUD; repeat with wrong TOTP and foreign UUID.
- Expected response: Owner succeeds; invalid/foreign fails safely.
- Expected database state: Only owner rows mutate.
- Expected side effects: Webhook signing secret generated.
- Failure cases: Duplicate redirect, endpoint limit, invalid URL/events/booleans.
- Cleanup: Delete rows.
- Parallel-safe: Yes per company.
- Priority: Critical
- Existing coverage: None.
- Notes: Confirm secret exposure contract.

### `integrator_jwt_authentication_and_metering_modes`

- Components: Keycloak token/JWKS, integrator auth, access policy and grants.
- Entry point: Ping and metered integrator endpoints.
- Environment: Dedicated realm and real DB.
- Preconditions: Active/disabled/unknown clients; enforce/log-only/unmetered companies.
- Test data: Valid, expired, wrong issuer, unknown kid and missing-client-claim tokens.
- Steps: Call ping and a metered endpoint for each combination.
- Expected response: Ping validates client but skips metering; others 401/403/402 or proceed.
- Expected database state: No ping usage/no denied mutation.
- Expected side effects: JWKS access only.
- Failure cases: Missing JWKS config and disabled client.
- Cleanup: Remove clients/grants.
- Parallel-safe: Yes per company.
- Priority: Critical
- Existing coverage: None.
- Notes: Exercise `azp`, `client_id` and `clientId` claim fallback.

### `enrollment_create_resume_and_tenant_isolation`

- Components: Enrollment controller/models, redirect allowlist, metering and events.
- Entry point: POST/GET enrollments.
- Environment: Real DB and captured webhooks.
- Preconditions: Active client, allowance and redirect.
- Test data: SMS/TOTP/mixed methods and ID flow.
- Steps: Create, repeat signer, alter resume params, GET as owner/other client.
- Expected response: 201 new, 200 resumed, 404 foreign.
- Expected database state: One open enrollment; permitted fields updated; no duplicate charge.
- Expected side effects: One creation event.
- Failure cases: Exhaustion, invalid input and concurrent create.
- Cleanup: Delete sessions/signers/events.
- Parallel-safe: Yes with unique signer.
- Priority: Critical
- Existing coverage: None.
- Notes: Concurrent create leaves at most one in-progress row.

### `hosted_enrollment_sms_state_machine`

- Components: Hosted enrollment, legal acceptance, SMS capture, signer/session models and Checkin fake.
- Entry point: Load, terms, send/verify SMS and Checkin token.
- Environment: Real DB with fake SMS/Checkin.
- Preconditions: SMS enrollment.
- Test data: Legal UUIDs, RO phone and captured OTP.
- Steps: Attempt out of order, accept, send/resend, verify wrong/right and request SDK token.
- Expected response: State errors followed by success.
- Expected database state: `created → terms_accepted → sms_verified`, hashes/timestamps and signer SMS SCA.
- Expected side effects: Localized SMS and SDK request.
- Failure cases: Expired code/session, rate limit and provider failure.
- Cleanup: Delete rows/messages/cache.
- Parallel-safe: With isolated token/IP/cache.
- Priority: Critical
- Existing coverage: None.
- Notes: Run under non-UTC PHP timezone.

### `hosted_enrollment_totp_state_machine_and_replay_resistance`

- Components: Enrollment, email queue, TOTP and models.
- Entry point: Choose SCA, terms, email PIN, setup and verify.
- Environment: Real DB/captured email.
- Preconditions: TOTP offered with email.
- Test data: Captured PIN/current TOTP.
- Steps: Choose, accept, send/verify PIN, setup/verify authenticator.
- Expected response: URI then established SCA.
- Expected database state: Verified email and signer TOTP SCA.
- Expected side effects: One email per successful reservation.
- Failure cases: Missing email, rate/queue, expired PIN and invalid TOTP.
- Cleanup: Delete rows/mail.
- Parallel-safe: Yes per signer.
- Priority: Critical
- Existing coverage: None.
- Notes: Re-enrollment choosing SMS must remove previous active TOTP.

### `checkin_callback_transitions_enrollment_and_charges_once`

- Components: Checkin callback handler, enrollment, metering, events and one-shot hook.
- Entry point: CSC-associated Checkin callback.
- Environment: Fake callback data and real DB.
- Preconditions: SCA-established session with verification ID.
- Test data: Approved/declined/expired and duplicate callbacks.
- Steps: Deliver each outcome and repeat.
- Expected response: Idempotent acceptance.
- Expected database state: Correct terminal state/reasons and one enrollment usage.
- Expected side effects: Correct events and one-shot hook.
- Failure cases: Unknown verification and partial identity.
- Cleanup: Delete sessions/usage/deliveries.
- Parallel-safe: Yes per verification.
- Priority: Critical
- Existing coverage: None.
- Notes: Handler is outside the folder but completes the controller flow.

### `verified_enrollment_identity_and_document_photo_retrieval`

- Components: Enrollment GET/photos, identity, upload model and storage.
- Entry point: Enrollment and photo GET routes.
- Environment: Real DB and temp upload root.
- Preconditions: Verified session with stored upload-code arrays.
- Test data: Front/back JPEG/PNG and legacy raw URL.
- Steps: GET enrollment/photos as owner and second client.
- Expected response: Normalized identity/CNP data and valid image streams; no legacy URL.
- Expected database state: Unchanged.
- Expected side effects: Reads only.
- Failure cases: Missing file, symlink escape and non-image file.
- Cleanup: Remove rows/files.
- Parallel-safe: Yes with unique directories.
- Priority: Critical
- Existing coverage: None.
- Notes: No absolute storage paths.

### `credential_issuance_is_idempotent_and_supersedes_prior_credential`

- Components: Issuance endpoint/service, EJBCA fake, models and events.
- Entry point: POST enrollment credentials.
- Environment: Real DB and CA fake/sandbox.
- Preconditions: Verified identity/enrollment.
- Test data: Complete certificate response and email override.
- Steps: Issue, repeat, then issue for a new enrollment of same signer.
- Expected response: Existing credential on repeat and new credential after reenrollment.
- Expected database state: One credential per enrollment; old superseded; linking UUID/status.
- Expected side effects: Issued events and old serial revocation.
- Failure cases: Missing identity/email, malformed SAM JWS, CA/persistence failure.
- Cleanup: Revoke sandbox certificates/delete rows.
- Parallel-safe: Per signer; sandbox may serialize.
- Priority: Critical
- Existing coverage: None.
- Notes: Assert failed issuance state/code.

### `credential_lookup_and_revocation_are_tenant_scoped_and_idempotent`

- Components: Integrator/admin credential controllers, model, EJBCA and events.
- Entry point: Signer GET/revoke and admin UUID revoke.
- Environment: Real DB/CA fake.
- Preconditions: Credentials for two clients.
- Test data: Numeric/symbolic reasons.
- Steps: Lookup, revoke twice, foreign access and post-revocation sign attempt.
- Expected response: Public lookup; first succeeds; repeat idempotent; foreign 404.
- Expected database state: Revoked status/time/reason/source.
- Expected side effects: One CA call/event.
- Failure cases: Missing serial, CA failure and invalid reason.
- Cleanup: Delete fixtures.
- Parallel-safe: Yes per credential.
- Priority: Critical
- Existing coverage: None.
- Notes: Includes integrator-revoke reachability.

### `partner_sms_signing_end_to_end`

- Components: Signing controllers/service/models/storage, SMS/email, SignServer, metering and events.
- Entry point: Create, send SMS, sign and GET result.
- Environment: Real DB/storage with fakes.
- Preconditions: SMS signer, valid credential and allowances.
- Test data: One/multiple PDFs, placements and both billing/review modes.
- Steps: Create, inspect digest, send/resend, wrong/right code and fetch results.
- Expected response: 201, SMS state and completed signed PDFs.
- Expected database state: Session/docs/signed paths and exactly one debit.
- Expected side effects: SMS, optional email, webhook, audit and unsigned-file deletion.
- Failure cases: Invalid/duplicate docs/signatures, revoked credential, provider/sign/storage failure.
- Cleanup: Delete rows/files/messages/usage.
- Parallel-safe: Yes with unique signer.
- Priority: Critical
- Existing coverage: None.
- Notes: Partial signing failure marks failed and cleans all session files.

### `partner_totp_signing_and_code_replay_guard`

- Components: Signing service, signer TOTP, review email and SignServer.
- Entry point: Create/sign without SMS.
- Environment: Real DB plus fakes.
- Preconditions: TOTP signer/credential.
- Test data: Current TOTP and PDFs.
- Steps: Create, confirm email review, sign, create follow-up and replay code.
- Expected response: First completes; replay rejected.
- Expected database state: Code hash claimed; created-to-signing transition.
- Expected side effects: Review email, no SMS.
- Failure cases: Explicit SMS review, missing secret and invalid/replayed code.
- Cleanup: Delete rows/files/usage/messages.
- Parallel-safe: No for same signer/window.
- Priority: Critical
- Existing coverage: None.
- Notes: Retryable failure must release claim where intended.

### `hosted_signing_review_files_redirect_and_idempotent_sign`

- Components: Hosted sign/review, router/auth, storage, signing service and redirect HMAC.
- Entry point: Hosted/review URLs.
- Environment: Real app/local files/fakes.
- Preconditions: Hosted session.
- Test data: Unsigned/signed PDFs and captured SMS.
- Steps: Load/review/stream/send/sign/repeat/fetch/status/ack twice.
- Expected response: Safe metadata, valid streams and idempotent sign/ack.
- Expected database state: One completion/redirect timestamp.
- Expected side effects: One debit/event plus read audits.
- Failure cases: Invalid/expired token, wrong doc, early signed-file and rate limit.
- Cleanup: Delete session/files/cache.
- Parallel-safe: Yes per token/IP.
- Priority: Critical
- Existing coverage: None.
- Notes: Expected to fail until anonymous route is reachable.

### `one_shot_signing_completes_after_verified_idv`

- Components: One-shot create/hosted flow, legal, email PIN, Checkin, transient issuance and signing.
- Entry point: Create plus hosted actions/callback.
- Environment: Real DB/storage and external fakes.
- Preconditions: Allowlisted return URL and both allowances.
- Test data: Email, ID flow and PDF.
- Steps: Create, terms, PIN, SDK token and approved callback.
- Expected response: `pending_enrollment` then completed/result retrieval.
- Expected database state: Linked enrollment, transient serial, terminal states and one of each debit.
- Expected side effects: Events, redirect signature and transient certificate.
- Failure cases: Declined/expired IDV, incomplete identity and CA/sign failure.
- Cleanup: Delete rows/files/revoke sandbox cert.
- Parallel-safe: Yes per signer.
- Priority: Critical
- Existing coverage: None.
- Notes: Duplicate callback cannot sign/debit twice.

### `signing_session_cancel_expiry_and_concurrency_controls`

- Components: Signing controller/service/model/filesystem.
- Entry point: Create/cancel/get/concurrent sign.
- Environment: Real DB/storage.
- Preconditions: Sessions in each status.
- Test data: Created/SMS-sent/signing/terminal.
- Steps: Cancel valid/invalid, force expiry and race create/sign calls.
- Expected response: Correct 200/409/422/idempotent completion.
- Expected database state: One open session; atomic claim; correct cancelled/expired states.
- Expected side effects: Cleanup and no debit for cancel/expiry.
- Failure cases: Blocking session disappears between attempts.
- Cleanup: Delete fixtures/files.
- Parallel-safe: Intentionally concurrent with unique signer.
- Priority: Critical
- Existing coverage: None.
- Notes: Use barriers, not sleeps.

### `webhook_delivery_signature_retry_dead_and_manual_resend`

- Components: Endpoint/delivery models, dispatcher, retry worker, controllers and local receiver.
- Entry point: Domain dispatch and delivery list/detail/resend.
- Environment: Real DB/local HTTP receiver.
- Preconditions: Enabled subscribed endpoint.
- Test data: Receiver 2xx/4xx/5xx/timeout modes.
- Steps: Dispatch, verify HMAC/headers/body, process retries, disable, resend.
- Expected response: Accurate observability/resend payloads.
- Expected database state: Attempts/status/error/exponential next retry/dead state.
- Expected side effects: Expected request count.
- Failure cases: Invalid stored payload/disabled endpoint.
- Cleanup: Delete rows/receiver records.
- Parallel-safe: Yes per endpoint with controllable clock.
- Priority: Critical
- Existing coverage: None.
- Notes: List omits payload; detail includes it.

### `integration_dashboards_filter_sort_paginate_and_isolate_clients`

- Components: Five integration read models/controllers and overview.
- Entry point: Overview/signers/enrollments/credentials/signing-session routes.
- Environment: Real DB.
- Preconditions: Two companies with varied states/dates.
- Test data: Multiple pages/every status.
- Steps: Exercise detail/search/filter/sort/order/date/stuck/expiry/status.
- Expected response: Correct counts/order/timelines/masking and isolation.
- Expected database state: Unchanged.
- Expected side effects: None.
- Failure cases: Invalid status/UUID/date/pagination.
- Cleanup: Delete fixtures.
- Parallel-safe: Yes per company.
- Priority: High
- Existing coverage: None.
- Notes: Resolve cancelled/pending signing filter support.

### `allowance_ledgers_match_enrollment_and_signing_usage`

- Components: Metering, grant/usage models and ledger controllers.
- Entry point: Terminal enrollment/signing actions and ledger GET.
- Environment: Real DB.
- Preconditions: Active/future/expired grants.
- Test data: Enforce/log-only/unmetered and per-document/per-pack.
- Steps: Complete/decline/sign then query ledgers.
- Expected response: Correct remaining/active/attributed/reference/filter values.
- Expected database state: FIFO usage, log-only orphan and no unmetered usage.
- Expected side effects: Usage rows only.
- Failure cases: Enforce exhaustion and post-precheck race.
- Cleanup: Delete grants/usage/domain rows.
- Parallel-safe: No shared buckets; allocate per worker.
- Priority: Critical
- Existing coverage: None.
- Notes: No duplicate charge on retries/callback repeats.

### `developer_pack_downloads_are_complete_personalized_and_non_secret`

- Components: Dev-pack controller, docs, redirect model and ZIP.
- Entry point: Index/every item.
- Environment: Real docs/DB.
- Preconditions: Provisioned admin/client.
- Test data: Client name/ID/redirect.
- Steps: Download and parse all formats/ZIP.
- Expected response: Correct headers and valid complete content.
- Expected database state: Unchanged.
- Expected side effects: Temp ZIP removed.
- Failure cases: Missing/corrupt source.
- Cleanup: Remove downloads.
- Parallel-safe: Yes.
- Priority: High
- Existing coverage: None.
- Notes: Search artifacts for secrets/private material.

### `migration_schema_supports_csc_controller_assumptions`

- Components: CSC migration chain and disposable DB.
- Entry point: Fresh migration application.
- Environment: Disposable database.
- Preconditions: Base schema.
- Test data: None.
- Steps: Apply migrations in date order and inspect columns/indexes/defaults.
- Expected response: Success and intended rerun safety.
- Expected database state: Unique company/client/tokens, SCA/TOTP fields and ledger references.
- Expected side effects: Schema only.
- Failure cases: Missing intermediate migration and existing columns/indexes.
- Cleanup: Drop DB.
- Parallel-safe: Yes per DB.
- Priority: High
- Existing coverage: None.
- Notes: Include July 2026 hosted/TOTP defaults.

### `external_failure_contracts_do_not_leave_unrecoverable_partial_state`

- Components: Keycloak, Checkin, SMS/email, EJBCA, SignServer, webhooks and filesystem boundaries.
- Entry point: Main mutating flows.
- Environment: Failure-injecting fakes.
- Preconditions: Valid pre-call states.
- Test data: Timeout, malformed response, 4xx/5xx and write failure.
- Steps: Fail one boundary at a time and retry supported actions.
- Expected response: Stable 500/502/503 errors.
- Expected database state: Claims/slots rollback where retryable or explicit failed terminal state.
- Expected side effects: No duplicate messages/debits/events on retry.
- Failure cases: Keycloak/DB divergence, partial multi-document signing and leaked reservation.
- Cleanup: Delete rows/files.
- Parallel-safe: Yes per fixture.
- Priority: Critical
- Existing coverage: None.
- Notes: Cross-workflow resilience suite.

## 5. Recommended Test File Structure

```text
tests/
├── Unit/
│   └── Api/
│       └── Csc/
│           ├── AccessTest.php
│           ├── AccessEvidenceTest.php
│           ├── ApiClientCreateTest.php
│           ├── ApiClientPatchTest.php
│           ├── ApiClientRotateSecretTest.php
│           ├── DevPackTest.php
│           ├── EnrollmentsTest.php
│           ├── HostedEnrollmentSmsTest.php
│           ├── HostedEnrollmentTotpTest.php
│           ├── HostedSigningTest.php
│           ├── SigningSessionsTest.php
│           ├── CredentialsTest.php
│           ├── WebhooksTest.php
│           ├── WebhookDeliveriesTest.php
│           ├── RedirectUrisTest.php
│           ├── IntegrationDashboardTest.php
│           ├── LedgerGrantsTest.php
│           ├── LedgerConsumptionTest.php
│           └── _support/
│               ├── CscControllerTestDoubles.php
│               ├── CscRegistryFactory.php
│               ├── CscModelStubs.php
│               └── CscResponseCapture.php
├── Feature/
│   └── Api/
│       └── Csc/
│           ├── RoutesAndAuthorizationTest.php
│           ├── AccessProvisioningFlowTest.php
│           ├── EnrollmentSmsFlowTest.php
│           ├── EnrollmentTotpFlowTest.php
│           ├── CredentialLifecycleTest.php
│           ├── PartnerSigningFlowTest.php
│           ├── HostedSigningFlowTest.php
│           ├── OneShotSigningFlowTest.php
│           ├── WebhookDeliveryFlowTest.php
│           ├── IntegrationDashboardFlowTest.php
│           ├── MeteringLedgerFlowTest.php
│           └── DevPackFlowTest.php
├── Support/
│   ├── CscApiHelper.php
│   ├── CscDatabaseFixture.php
│   ├── CscKeycloakHelper.php
│   ├── CscTotpHelper.php
│   └── CscFakeServer.php
└── FileSamples/
    └── Csc/
        ├── minimal.pdf
        ├── second.pdf
        ├── invalid.pdf
        ├── document-front.jpg
        └── document-back.png
```

## 6. Implementation Order

1. Create shared registry/customer/load/model/response doubles.
2. Add factories for auth, signing, Keycloak, HTTP, SMS, email PIN, TOTP, webhook and credential dependencies.
3. Add controllable clock, OTP/UUID generator and filesystem/stream response seams.
4. Implement route reachability and authentication tests.
5. Implement enrollment create/resume and hosted SMS/TOTP state machines.
6. Implement signing controllers, then partner/hosted/one-shot integration flows.
7. Add tenant-isolation tests for every UUID/token/signer lookup.
8. Add credential issuance/revocation and EJBCA failure tests.
9. Add metering, idempotency, concurrency and ledger reconciliation tests.
10. Add webhook HMAC/retry/resend tests.
11. Add access/evidence/API-client provisioning tests.
12. Add dashboard filters/serialization tests.
13. Add streaming, developer-pack, migration and lower-risk helper tests.
14. Run stateful integration suites serially first; parallelize only with separate company/client/signer/grant fixtures.

## 7. Final Summary

- Proposed unit-test groups: **73**
- Proposed integration workflows: **24**
- Production files analyzed: **28**
- Existing CSC-specific tests: **0**
- Highest-risk areas: route reachability; SMS/TOTP/IDV transitions; signing concurrency/idempotency/partial failure; tenant isolation; credential lifecycle; debit-once metering; Keycloak/DB consistency; webhook retry/signature behavior.
- Required fixtures: CSC registry/controller doubles; dedicated company/client/signer data; valid PDFs/images; fake Keycloak/JWKS, Checkin, EJBCA, SignServer, SMS/email and webhook services; clock/random/TOTP helpers; temporary storage roots.
- Required testability refactoring: protected dependency factories or injected collaborators; remove direct external-client construction; extract streaming/output/exit; add clock/random/filesystem adapters.
- Assumptions: Canonical routes use hyphens and router normalization; no production external accounts; invalid filter/date behavior is characterized until the API contract chooses explicit validation.
