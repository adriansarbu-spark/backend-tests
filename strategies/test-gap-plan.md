# Simplifi Public API — Test Gap Plan

This plan records **meaningful testing gaps** across the Public API controllers
under `public/catalog/controller/publicapi/v1/`. It proposes tests only; it does
not implement them.

Evidence sources:

- Pest HTML coverage report: `tests/coverage_report/2026-08-19/` (generated
  2026-08-19; **do not regenerate** for planning purposes)
- Existing tests: `tests/Unit/Api/` and `tests/Feature/Api/`

**Revision limitation:** The tests repository is separate from the application
repository. Controller code may have changed since 2026-08-19; coverage
percentages and uncovered line references reflect the report cutoff, not
necessarily the current tree. Assertion quality reflects tests as they exist
today.

**2026-08-31 policy revision:** This document is an editorial/policy update of
the existing audit and is the **standalone source of truth** for what to
implement next. Coverage cutoff remains 2026-08-19. No new tests were
implemented. Stripe, Keycloak, tokens, and certificates are out of scope for
recommended tests. CSC, general setup, TOTP, and identification are Unit-only.

---



## 1. Scope



### Included

All 120 controllers under `public/catalog/controller/publicapi/v1/`, grouped by
module. Coverage figures below are inventory only; excluded modules still appear
in §1 Excluded and are **not** recommended for new tests.


| Module            | Controllers                                                         | Coverage (lines, 2026-08-19)       |
| ----------------- | ------------------------------------------------------------------- | ---------------------------------- |
| Account           | `account.php` + 22 sub-controllers                                  | 3.55% folder; `account.php` 62.75% |
| Billing           | 12 controllers (local catalog/entitlements/grants/seats/me/ledgers) | 72.44%                             |
| Checkin           | `checkin.php`                                                       | 0.00%                              |
| Company           | 7 controllers                                                       | 57.81%                             |
| CSC               | 31 controllers                                                      | 10.20%                             |
| Documents         | `documents.php`                                                     | 21.61%                             |
| eSign / Author    | `esign/author/documents.php`                                        | 35.65% (esign folder 52.66%)       |
| eSign / Templates | `esign/tpl/templates.php`, `library.php`                            | 52.66% folder                      |
| Lists             | `lists.php`                                                         | 35.21%                             |
| Partner           | `partner/onboard.php`, `join.php`                                   | Not in report                      |
| Platform          | `platform/retention.php` (tokens excluded — see below)              | 24.27% folder                      |
| Preview           | `preview.php`                                                       | 0.00%                              |
| Referrals         | 7 controllers                                                       | 77.88% folder                      |
| Signing           | `signing.php`, `signing/signhash.php`                               | 53.27% + 61.25%                    |
| Signing Guest     | `signing_guest.php`                                                 | 25.81%                             |
| Sponsorships      | 9 controllers                                                       | Not in report                      |
| Team              | 6 controllers                                                       | 43.05%                             |
| Verify            | `verify.php`                                                        | 65.87%                             |
| Misc              | `counties`, `localities`, `languages`, `legal_documents`, `openapi` | 0.00% each                         |


**Overall publicapi/v1:** 34.21% lines (7,445 / 21,762), 13.35% methods
(86 / 644), 6.86% classes (7 / 102).

### Excluded / brief note

- `_unused` controllers (if any): skip unless reactivated.
- Admin/test-dashboard controllers outside `publicapi/v1` are out of scope.
- **Stripe** (`stripe/webhook.php`, 60.23% in the Aug-19 report) — **no further
  Stripe tests are planned**. Existing unit files under `tests/Unit/Api/Stripe/`
  may remain on disk; do not add Unit, Feature, or Integration tests for webhook
  handlers, checkout customer creation, portal Stripe 502, or other Stripe SDK
  orchestration.
- **Keycloak / OIDC / IdP** — `auth/backchannel_logout.php` (not in report),
  Users IdP bootstrap (`users.php`, 0%), CSC Keycloak provisioner/sync
  (`setClientEnabled`, API-client Keycloak as SUT). No new tests whose purpose
  is Keycloak Admin, JWKS, or IdP registration.
- **Tokens** — `platform/tokens.php` (offline Keycloak sessions),
  `account/sessions` (list/revoke/revoke-all), OAuth connections / account
  linking. Existing files may remain; no new tests.
- **Certificates** — `account/certificates`, `public/certificates.php` (0% —
  anonymous CNP lookup/download), CSC credential **issuance** that depends on
  EJBCA/SignServer cert material. Signing *document* flows and Verify hash tests
  are not certificates.

### Testing-type policy

This section is authoritative. Do not look elsewhere for type/scope rules.

1. **Out of scope for new/recommended tests** (do not add Unit, Feature, or
   Integration tests; existing files on disk may remain):
   - **Stripe** — `stripe/webhook.php`, checkout customer creation, portal
     Stripe 502, `handleCancelPost` / checkout modes that exist only to
     exercise the Stripe SDK, webhook subscription sync, invoice-paid grant
     chains.
   - **Keycloak** — Keycloak Admin, JWKS, client provisioning
     (`setClientEnabled`, CSC API-client Keycloak sync as SUT), IdP
     registration/bootstrap, OIDC back-channel logout JWT/JWKS.
   - **Tokens** — `platform/tokens.php` (offline Keycloak sessions),
     `account/sessions` (list/revoke/revoke-all), OAuth connections / account
     linking.
   - **Certificates** — `account/certificates`, `public/certificates.php`,
     CSC credential **issuance** that depends on EJBCA/SignServer cert
     material. Signing *document* flows and Verify hash tests are not
     certificates.
2. **Unit-only surfaces (never Feature or Integration):**
   - **CSC** — entire `publicapi/v1/csc/` module (admin setup, access, API
     client *local* validation, redirect URIs, webhooks, hosted enrollment,
     signing sessions, credentials *revoke*, photos, review, ledgers,
     dashboards, alerts, entitlements, decline reasons, route/allowlist
     reachability, tenant isolation). There must be no
     `tests/Feature/Api/Csc/` items in the recommended list. The former 24
     CSC “integration workflows” and route-reachability Feature test are
     out of Feature/Integration scope.
   - **General setup** — CSC/account/company bootstrap and configuration that
     is not a document/signing/local-billing workflow (CSC access/questionnaire/
     evidence/API-client local provisioning/redirect URIs; account
     companies/active-role; company profile/branding).
   - **TOTP** — `account/totp.php` lifecycle and TOTP *gates* on CSC/account/
     company. A Team/Documents Feature workflow may still exist for that product
     area; TOTP itself stays Unit.
   - **Identification** — `checkin.php`, `account/identity_verifications`,
     identity photos, CSC IDV/checkin-token/pending-identification paths.
3. **Skip if unfakeable:** if a Unit test cannot reasonably recreate the
   dependency graph (Keycloak, Checkin vendor, EJBCA/SignServer, Stripe, SMS
   gateway, real TOTP device, identity photos, etc.), **omit the test**. Do not
   fall back to Feature/Integration. Do not invent integration harnesses.
   Contract/smoke outside this suite is acceptable.

**Not feasible in-suite** (kept out of §3 counts and §4 recommended list):

- Checkin HTTP lifecycle against the real vendor (`createVerification` /
  JWT / submitted / result).
- CSC hosted enrollment checkin **success** that requires a real or fake
  Keycloak client as the subject under test.
- CSC signing **crypto ceremony** / SignServer cert material (session
  routing, idempotency, and debit-once with doubles remain Unit).
- CSC credential **certificate issuance** (EJBCA/SignServer). API-client
  **Keycloak sync** (including `setClientEnabled` failure after DB update).

---



## 2. Executive Summary



### Gap counts (deduplicated, in-scope only)

Counts are remaining **in-scope gap-table rows** after the 2026-08-31 policy
filter. Previous headline was **38 / 52 / 41 / 22** (behavior-level, including
now-excluded Stripe / Keycloak / tokens / certificates and CSC
Feature/Integration workflows).


| Priority | Count  | Description                                                                         |
| -------- | ------ | ----------------------------------------------------------------------------------- |
| **P0**   | **23** | Security, authorization, data integrity, destructive operations, money-moving paths |
| **P1**   | **20** | Core business workflows and critical HTTP flows                                     |
| **P2**   | **14** | Validation failures, edge cases, assertion strengthening                            |
| **P3**   | **6**  | Low-risk completeness, reference endpoints, debug helpers                           |




### Modules with zero or negligible coverage


| Module                                 | Notes                                                                                         |
| -------------------------------------- | --------------------------------------------------------------------------------------------- |
| **Sponsorships** (9 controllers)       | No tests; absent from coverage report                                                         |
| **Partner** (`onboard`, `join`)        | No tests; absent from coverage report                                                         |
| **Preview**                            | 0% — entire controller untested                                                               |
| **Checkin** (`checkin.php`)            | 0% — library-layer tests exist; HTTP lifecycle **not feasible in-suite** (vendor graph)       |
| **Account sub-controllers**            | ~0% each except `companies.php` (99%); sessions/certificates **excluded**; TOTP/setup = Unit  |
| **Company branding/profile**           | Not in coverage report; no tests — **Unit only** (general setup)                              |
| **Misc reference**                     | `counties`, `localities`, `languages`, `legal_documents`, `openapi` — 0%                      |
| **Auth / Users / Public certificates** | 0% or absent — **out of scope** (Keycloak / IdP / certificates); listed for inventory only    |




### Modules with misleadingly high coverage


| Module                    | Coverage                   | Issue                                                                                          |
| ------------------------- | -------------------------- | ---------------------------------------------------------------------------------------------- |
| **Billing (local)**       | 72–98% on read controllers | High local-read %; Stripe checkout/webhook % is **out of plan** and must not drive new tests   |
| **Referrals**             | 65–88%                     | High line %; send/remind success deferred to feature; status dashboard has no feature test     |
| **Verify**                | 65.87%                     | `uploadSha256` and rate limiter stubbed in unit tests                                          |
| **Signing**               | 61.25%                     | Many negative `signDocument` tests; `performSignSteps` success unverified                      |
| **Documents**             | 21.61%                     | `IndexTest` and validation tests execute routes without behavioral assertions                  |
| **Account/companies**     | 99%                        | One unit test accepts overly permissive edge payload                                           |
| **Team/members**          | 91%                        | Unit-heavy; feature never asserts successful terminate                                         |
| **Lists**                 | 35%                        | Helper methods covered; security-critical `index()` permission gate at 0%                      |
| **CSC access/enrollment** | Partial                    | Aug-19 report stale (shows 0% on controllers that now have unit tests); signing/webhooks still 0% |




### Tests to strengthen (not duplicate)

- `Documents/IndexTest.php` — routing permission only; strengthen to assert handler/payload
- `Documents/SendDocumentTest.php`, `CancelDocumentTest.php` — guards without success paths
- `VerificationControllerTest.php` — remove stubs for hash/rate-limit integration tests
- `AccountDeletionTest.php` — add success, lockout, closure-failure branches
- `CompanyRepresentativesPermissionsFlowTest`, `TeamInvitationsPermissionsFlowTest` — replace skip/incomplete with deterministic non-admin fixtures
- `BillingEntitlementGrantAssignmentFlowTest`, `BillingSeatAssignmentFlowTest` — add happy-path mutations
- `ReferralsGetAndAuthFlowTest` — assert `can_remind`, filters, not just `is_array`
- `ListsControllerTest.php` — extend from private helpers to `index()` permission scenarios

---



## 3. Gap Table


| Priority | Module                               | Coverage      | Missing / Weakly Tested Behavior                                                                                                 | Evidence                                              | Suggested Test Type          | Existing Related Tests                                         |
| -------- | ------------------------------------ | ------------- | -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- | ---------------------------- | -------------------------------------------------------------- |
| P0       | Account / totp                       | 0%            | TOTP setup, confirm, disable; brute-force lockout; secret handling                                                               | 0/295 lines                                           | Unit                         | None                                                           |
| P0       | Account / account.php DELETE         | 62.75%        | Successful deletion: valid TOTP → cascade closure, security event, `data.status=deleted`; closure failure (502/503); 429 lockout | `AccountDeletionTest` stops at invalid TOTP           | Unit                         | `AccountDeletionTest` (negative only)                          |
| P0       | Account / active_role                | 0%            | POST active-role: foreign `role_uuid` → 403; owned role persisted                                                                | 0% in report                                          | Unit                         | None                                                           |
| P0       | Account / signatures                 | 0%            | Create/list/delete saved signatures; max-count and size limits                                                                   | 0% in report                                          | Unit                         | None                                                           |
| P0       | Company / profile                    | Not in report | Admin GET/PATCH/PUT; immutable `country`; seal revocation on PATCH (local only; Stripe sync out of scope)                        | No tests reference `company/profile`                  | Unit                         | None                                                           |
| P0       | Company / representatives            | 64.66%        | `validateTotpIfRequired()` on revoke — TOTP success path; successful revoke happy path                                           | Method 0% in coverage; unit tests cover failures only | Unit                         | `CompanyRepresentativesApiTest`                                |
| P0       | CSC / signing (all)                  | ~0%           | Hosted + one-shot session routing, partner sessions (create/get/cancel/sign/SMS), concurrency, idempotency, debit-once with doubles. SignServer crypto ceremony **not feasible in-suite**. | 17 controllers untested                               | Unit                         | None for signing                                               |
| P0       | CSC / route layer                    | N/A           | `sign` anonymous allowlist gap; `signers/credential/revoke` integrator bypass gap — Unit dispatch/reachability only              | Router vs controller allowlist untested               | Unit                         | None                                                           |
| P0       | CSC / credentials revoke             | 0%            | Integrator + admin credential revocation; tenant scope; idempotency; TOTP on admin path (issuance/EJBCA out of scope)            | No tests                                              | Unit                         | None                                                           |
| P0       | CSC / tenant isolation               | Partial       | No cross-client matrix for enrollments GET, photos, credentials, signing sessions, webhooks                                      | Foreign enrollment 404 only                           | Unit                         | `EnrollmentsGetTest` (basic)                                   |
| P0       | CSC / enrollment photos stream       | Partial       | Valid image stream (bytes, MIME, no-store); path-separator boundary on success. Skip if photo/storage graph cannot be doubled.   | Only 404 paths tested                                 | Unit                         | `EnrollmentPhotosTest`                                         |
| P0       | CSC / hosted enrollment SMS send     | Partial       | `handleSendSms` success: hash-only persistence, E.164 send, state mutation. Skip if SMS gateway cannot be faked.                 | Happy path deliberately skipped                       | Unit                         | `HostedEnrollmentSmsTest` (verify only)                        |
| P0       | CSC / hosted enrollment checkin      | Partial       | Unit: Checkin HTTP **failure mapping** and local gates with vendor doubles. Keycloak redirect-success **not feasible**.          | Gates only; Keycloak seam avoided                     | Unit                         | `HostedEnrollmentCompletionTest`                               |
| P0       | Documents / createDraft + send       | 21.61%        | `createDraft()` 0%, `sendDocumentCore()` 0/222; no assertion on DRAFT→PENDING, signer status, email queue                        | Guards tested; no happy path                          | Feature                      | `SendDocumentTest` (guards)                                    |
| P0       | Documents / pack send + cancel       | 21.61%        | `sendDocumentGroup()` 0%; `cancelDocument()` success never completes (stops at TOTP gate)                                        | 0% methods                                            | Feature                      | `CancelDocumentTest`, `DocumentsGroupsAndMutationsTest`        |
| P0       | Signing Guest                        | 25.81%        | HTTP routing (`index`, `getGuestSignerInfo`) 0%; file/audit download auth; qualified commit success                              | No Feature tests; unit covers guards only             | Feature + Integration        | `GuestSigningControllerTest`                                   |
| P0       | Preview                              | 0%            | Entire controller: `generatePDF`, `addSignaturesPlaceholder`, permission gate on `pdf` post permission                           | 0/93 lines; Team preview is different controller      | Unit + Feature               | None                                                           |
| P0       | Author Documents / send              | 35.65%        | Send pipeline success: PDF generation, signer rows, invite email (`sendSignerInviteEmail` 0%)                                    | Guards tested; feature may skip on PDF/billing        | Feature + Integration        | `AuthorDocumentsSendFlowTest`                                  |
| P0       | Signing / performSignSteps           | 61.25%        | Crypto signing execution success — signed PDF hash / signer status updates unverified                                            | 57/115 lines; feature checks HTTP 200 only            | Integration                  | `SigningFlowTest`                                              |
| P0       | Verify                               | 65.87%        | Real `uploadSha256()` and `isVerificationRateLimited()` — both stubbed in unit tests                                             | Overrides in `VerificationControllerTest`             | Integration                  | `VerificationControllerTest` (strong negatives with stubs)     |
| P0       | Sponsorships (all)                   | Not in report | Entire module: auth, permissions, batch invite all-or-nothing, `mine/revoke` billing hook                                        | No tests under `tests/`                               | Unit + Feature               | None                                                           |
| P0       | Partner / onboard + join             | Not in report | Token preview/claim/consent state machine; rate limits; billing activation on consent                                            | No tests                                              | Feature + Unit               | None                                                           |
| P0       | Lists / index permission             | 35%           | `index()`/`getList()` 0% — permission gate `list.list_id.{id}` before data fetch (IDOR surface)                                  | Tests exercise private helpers only                   | Unit + Feature               | `ListsControllerTest`                                          |
| P1       | Account / identity_verifications     | 0%            | Start/status identity verification workflows (Unit doubles only; skip if vendor/photos unfakeable)                               | 0% in report                                          | Unit                         | None                                                           |
| P1       | Account / data_export                | 0%            | GDPR export request/status; PII in responses                                                                                     | GET account embeds fields only                        | Unit                         | `CurrentAccountResponseTest`                                   |
| P1       | CSC / redirect_uris, webhooks        | 0%            | Full CRUD + TOTP + HTTPS/events; delivery list/resend (fake transport; no live delivery)                                         | No tests                                              | Unit                         | None                                                           |
| P1       | CSC / review                         | 0%            | Token rate limit, metadata/file routing, PDF stream headers                                                                      | No tests                                              | Unit                         | None                                                           |
| P1       | CSC / integration dashboards         | 0%            | 5 integration controllers: guards, filters, pagination, serialization                                                            | No tests                                              | Unit                         | None                                                           |
| P1       | CSC / ledgers                        | 0%            | `ledger_grants`, `ledger_consumption` query validation + serialization                                                           | No tests                                              | Unit                         | None                                                           |
| P1       | CSC / dev_pack ZIP                   | Partial       | ZIP bundle, `sendFile`/`handleZip`, reference HTML                                                                               | Index + env personalization only                      | Unit                         | `DevPackTest`                                                  |
| P1       | CSC / new controllers                | 0%            | `alerts.php`, `integrator/entitlements.php` — not in original plan                                                               | No tests                                              | Unit                         | None                                                           |
| P1       | Billing / subscription_items         | 58%           | TOTP success path on local mutations; new price line vs increment (Stripe `payment_method_required`/portal URL out of scope)     | Failure branches only                                 | Unit                         | `SubscriptionItemsContextAndRiskTest`                          |
| P1       | Billing / portal                     | 61%           | Local guards: auth 401, admin 403, 405, invalid return_url (Stripe 502 out of scope)                                             | Thin coverage                                         | Unit                         | `BillingPortalTest`                                            |
| P1       | Billing / feature mutations          | N/A           | Grant assign happy path; seat assign/release success; `BillingLocalConsistencyFlowTest` missing                                  | Feature has negatives only                            | Feature                      | `BillingEntitlementGrantAssignmentFlowTest`                    |
| P1       | Documents / list + recipients        | 21.61%        | `listDocuments()` 0/189 — scope/filters/tenant isolation in response; `listRecipients`, `getStatistics` 0%                       | Validation tests mock empty list                      | Feature                      | `ListDocumentsValidationTest`                                  |
| P1       | Documents / remind + annotations     | 21.61%        | Reminder success path + email side effect; `setAnnotations()` placement validation branches                                      | Partial coverage                                      | Feature                      | `DocumentsReminderDeletionAndEvidenceTest`                     |
| P1       | Signing / pack + claim email         | 61.25%        | `signPack()` multi-doc; `claimEmail()` OTP send at HTTP layer                                                                    | Unit classifies; no feature pack flow                 | Feature                      | `SigningPhase1ResidualTest`                                    |
| P1       | Signing / signhash success           | 53.27%        | Successful SAM audit-hash response not asserted                                                                                  | Guards strong                                         | Integration                  | `SignHashTest`                                                 |
| P1       | Author Documents / preview HTTP      | 35.65%        | `preview()`, `streamPreviewFile()`, `buildPreviewPdf()` — route-level untested                                                   | Helpers via reflection only                           | Feature                      | `GetListPreviewAndStructuredTest`                              |
| P1       | Team / accept + terminate            | 43% report*   | Accept invitation → membership at HTTP layer; admin terminate success in feature                                                 | Unit exists; feature negatives only                   | Feature                      | `TeamInvitationsAcceptApiTest`, `TeamMembersTerminateFlowTest` |
| P1       | Sponsorships / batch + revoke        | Not in report | Two-phase batch confirm; beneficiary revoke + billing decrement                                                                  | No tests                                              | Feature + Unit               | None                                                           |
| P1       | Referrals / status                   | ~90%          | Authenticated status dashboard (`funnel`, `as_referred`) — no feature test                                                       | Unit only                                             | Feature                      | `ReferralsStatusApiTest`                                       |
| P1       | Template Library                     | 65%           | Library CRUD/versioning/publish feature flows; `index()` dispatch gaps                                                           | 18 unit files; 1 feature flow                         | Feature                      | `tests/Unit/Api/TemplateLibrary/*`                             |
| P2       | Billing / guards                     | Partial       | 405 matrix, company/role ID zero → 400, guard-order characterization                                                             | 3 tests vs 9 planned                                  | Unit                         | `BillingEndpointGuardsTest`                                    |
| P2       | Billing / feature depth              | Partial       | Ledger pagination/sort/search; foreign-role isolation; route-permission E2E                                                      | Partial in entitlements flow                          | Feature                      | `BillingEntitlementsAndLedgersFlowTest`                        |
| P2       | Billing / support                    | Missing       | `BillingFixtureHelper` not created (plan §2)                                                                                     | Only `BillingApiHelper` exists                        | Support                      | —                                                              |
| P2       | Documents / IndexTest                | 21.61%        | Asserts `statusCode !== 403` without handler/payload contract                                                                    | Comment in test                                       | Unit (strengthen)            | `IndexTest`                                                    |
| P2       | Documents / guest verification retry | 21.61%        | `resolveGuestVerificationRetryable()` wiring in document detail                                                                  | Static mapper tested only                             | Unit                         | `GuestVerificationStatusTest`                                  |
| P2       | Signing / draft signature            | 61.25%        | `saveDraftSignature()` — DB persistence not verified                                                                             | Weak DB assertion                                     | Unit (strengthen)            | `SaveDraftSignatureTest`                                       |
| P2       | CSC / assertion gaps                 | Partial       | Expired-verified load weak invariant; enrollment GET extended branches; MIME mismatch characterized not enforced                 | Various unit gaps                                     | Unit (strengthen)            | Multiple CSC unit files                                        |
| P2       | Account / email, roles               | 0%            | Email verification, role listing (OAuth connections / account linking **out of scope**)                                          | 0% each                                               | Unit                         | None                                                           |
| P2       | Company / branding                   | Not in report | Branding CRUD, logo/banner upload, public asset serving                                                                          | No tests                                              | Unit                         | None                                                           |
| P2       | Team / permissions feature           | N/A           | Non-admin tests skipped when env user is admin                                                                                   | `markTestSkipped` in permission flows                 | Feature (fixtures)           | `TeamInvitationsPermissionsFlowTest`                           |
| P2       | Referrals / send + remind unit       | 71–84%        | Missing 401, 405, invalid UUID, not-found unit cases                                                                             | 2 tests each file                                     | Unit                         | `ReferralsInviteSendApiTest`, `ReferralsRemindApiTest`         |
| P2       | Referrals / validate                 | 73%           | No `valid: true` happy path in feature; unit missing 405                                                                         | Feature uses invalid code only                        | Feature + Unit               | `ReferralsValidateApiTest`                                     |
| P2       | Lists / getList paths                | 35%           | `getList`, datalabels, filters, report templates end-to-end                                                                      | 7/14 methods                                          | Unit + Feature               | `ListsControllerTest`                                          |
| P2       | Templates / routing                  | 70%           | Route parsing matrix; version/publish edge branches                                                                              | Strategy §2.1 gaps                                    | Unit                         | `tests/Unit/Api/Templates/*`                                   |
| P3       | Misc reference                       | 0%            | `counties`, `localities`, `languages`, `legal_documents`, `openapi`                                                              | Read-only GET                                         | Unit                         | None                                                           |
| P3       | Account / avatar, marketing, ANAF    | 0%            | Avatar upload, marketing prefs, onboarding, ANAF lookup/letter                                                                   | Low risk                                              | Unit                         | None                                                           |
| P3       | Signing / helpers                    | 61.25%        | `signingDisplayNameFromSamSubject`, certificate request docs by language                                                         | 0% small methods                                      | Unit                         | None                                                           |
| P3       | Preview / logo                       | 0%            | `getLogoUrl()` edge cases                                                                                                        | 0/10 lines                                            | Unit                         | None                                                           |
| P3       | Platform / retention                 | 98%           | Residual error/logging branch (~1 line)                                                                                          | Near-complete                                         | Unit                         | `PlatformRetentionTest`                                        |
| P3       | Referrals / invite index             | 65%           | POST rotate error branches partially uncovered                                                                                   | Unit covers GET + rotate success                      | Unit                         | `ReferralsInviteApiTest`                                       |



Team invitation sub-routes show 0% in the Aug-19 report but have substantial unit
suites — report likely reflects partial coverage run scope.

---



## 4. Recommended Next Tests

Prioritized actionable list. Each new Pest `test()` should have a PHPDoc with
**Prerequisites** and numbered **Steps** immediately above the `test(` call.
Do not add files under `tests/Feature/Api/Csc/`. Do not add Stripe, Keycloak,
token, or certificate tests. If a Unit item cannot be doubled, skip it.

### P0 — Security, integrity, money, destructive operations


| #   | Suggested file                                                             | Test / behavior                                                        | Type        | Prerequisites                              |
| --- | -------------------------------------------------------------------------- | ---------------------------------------------------------------------- | ----------- | ------------------------------------------ |
| 1   | `tests/Unit/Api/Account/AccountDeletionSuccessTest.php`                    | Valid TOTP → cascade closure, `data.status=deleted`, security event    | Unit        | TOTP + `AccountClosure` doubles            |
| 2   | `tests/Unit/Api/Account/AccountTotpTest.php`                               | Setup → confirm → disable lifecycle; lockout on confirm                | Unit        | TOTP doubles (skip if device graph needed) |
| 3   | `tests/Unit/Api/Account/ActiveRoleTest.php`                                | Foreign `role_uuid` → 403; owned role persisted                        | Unit        | Permission + role doubles                  |
| 4   | `tests/Unit/Api/Csc/RoutesAndAuthorizationTest.php`                        | Every CSC route reaches controller; sign + integrator revoke allowlist | Unit        | CSC hyphenated `publicapi/v1/csc` routes   |
| 5   | `tests/Unit/Api/Csc/SigningSessionsTest.php`                               | Collection routing, create/get/cancel, service delegation              | Unit        | `CscIntegratorTestDoubles`                 |
| 6   | `tests/Unit/Api/Csc/HostedSigningRoutingTest.php`                          | Token/actions/rate limits for `sign.php`                               | Unit        | CSC API doubles                            |
| 7   | `tests/Unit/Api/Csc/SignerCredentialRevokeTest.php`                        | Tenant-scoped revoke, idempotency, reason mapping                      | Unit        | Integrator auth doubles                    |
| 8   | `tests/Unit/Api/Csc/HostedEnrollmentSmsTest.php` *(extend)*                | Send success with SMS fake; hash persisted, no raw OTP                 | Unit        | SMS client fake; skip if unfakeable        |
| 9   | `tests/Feature/Api/Documents/DocumentsSendFlowTest.php`                    | Owner send DRAFT→PENDING, signer invites queued                        | Feature     | TEST_USER_1 bearer, PDF upload             |
| 10  | `tests/Feature/Api/Documents/DocumentsCancelFlowTest.php`                  | Owner cancel with valid TOTP updates lifecycle                         | Feature     | PENDING doc, TOTP secret                   |
| 11  | `tests/Feature/Api/SigningGuest/GuestSigningFlowTest.php`                  | Qualified guest commit + signed PDF download                           | Feature     | Guest invitation fixture, checkin mock     |
| 12  | `tests/Feature/Api/SigningGuest/GuestSigningAccessTest.php`                | Foreign sign_code cannot download file or audit cert                   | Feature     | Two guest links                            |
| 13  | `tests/Unit/Api/Preview/GeneratePdfTest.php`                               | Denies without `pdf` permission; returns base64 on valid HTML          | Unit        | Permission + MakePDF mock                  |
| 14  | `tests/Integration/Api/Verification/VerificationFileHashTest.php`          | `uploadSha256` matches stored file digest (no stub)                    | Integration | Known file in upload storage               |
| 15  | `tests/Unit/Api/Sponsorships/SponsorshipsAuthTest.php`                     | 401, permission denied, `link_uuid_required` on core routes            | Unit        | New doubles file                           |
| 16  | `tests/Feature/Api/Partner/PartnerOnboardFlowTest.php`                     | Token preview → claim → consent → activation + billing hook            | Feature     | Partner link fixture                       |
| 17  | `tests/Unit/Api/Lists/ListsIndexPermissionTest.php`                        | `index()` with/without `list.list_id.{id}` → 404 on deny               | Unit        | Permission doubles                         |
| 18  | `tests/Unit/Api/Company/CompanyProfileTest.php`                            | Non-admin 403; seal revocation on PATCH with `confirm_seal_revocation` | Unit        | Company doubles                            |
| 19  | `tests/Feature/Api/AuthorDocuments/AuthorDocumentsSendSuccessFlowTest.php` | Send creates signers + queues invites (when PDF service available)     | Feature     | TEST_USER_1, billing entitlement           |



### P1 — Core business workflows


| #   | Suggested file                                                       | Test / behavior                                                  | Type        | Prerequisites                                 |
| --- | -------------------------------------------------------------------- | ---------------------------------------------------------------- | ----------- | --------------------------------------------- |
| 20  | `tests/Unit/Api/Csc/RedirectUrisTest.php`                            | CRUD + TOTP + localhost policy                                   | Unit        | CSC admin doubles                             |
| 21  | `tests/Unit/Api/Csc/WebhooksTest.php`                                | Create/patch/delete/list + HTTPS/events validation               | Unit        | CSC admin doubles                             |
| 22  | `tests/Unit/Api/Csc/ReviewTest.php`                                  | Token rate limit, metadata/file routing                          | Unit        | Anonymous token doubles                       |
| 23  | `tests/Unit/Api/Csc/IntegrationOverviewTest.php`                     | Admin guards, filters, pagination                                | Unit        | Integration doubles                           |
| 24  | `tests/Unit/Api/Csc/AlertsTest.php`                                  | Allowance alert GET/PATCH (new controller)                       | Unit        | CSC doubles                                   |
| 25  | `tests/Feature/Api/Billing/BillingGrantAssignSuccessFlowTest.php`    | Admin assign → list → `/me` reflects grant                       | Feature     | TEST_USER_1 company admin, `BillingApiHelper` |
| 26  | `tests/Feature/Api/Billing/BillingSeatAssignReleaseFlowTest.php`     | Assign → list → release → idempotent second release              | Feature     | Company admin role, seat capacity             |
| 27  | `tests/Feature/Api/Billing/BillingLocalConsistencyFlowTest.php`      | Cross-endpoint drift check (overview/seats/me/entitlements)      | Feature     | Admin + fixtures                              |
| 28  | `tests/Unit/Api/Billing/BillingPortalGuardsTest.php`                 | 401/403/405, invalid return_url (no Stripe SDK)                  | Unit        | Local billing doubles                         |
| 29  | `tests/Feature/Api/Documents/DocumentsPackSendFlowTest.php`          | Document group send dispatches pack invites                      | Feature     | Multi-member `document_group_uuid`            |
| 30  | `tests/Feature/Api/Documents/DocumentsListFlowTest.php`              | Scope filters return only tenant-visible envelopes               | Feature     | Personal vs company scope docs                |
| 31  | `tests/Feature/Api/Signing/SigningPackFlowTest.php`                  | Signer completes all pack members in one session                 | Feature     | Pack with 2+ documents                        |
| 32  | `tests/Feature/Api/Signing/SigningClaimEmailFlowTest.php`            | Email OTP claim unlocks mismatched-identity sign_code            | Feature     | Unclaimed signer row                          |
| 33  | `tests/Integration/Api/Signing/SignHashSuccessTest.php`              | Valid digest returns signed hash from SAM                        | Integration | SAM test cert (may skip CI)                   |
| 34  | `tests/Feature/Api/Team/TeamInvitationAcceptFlowTest.php`            | Preview → accept → member in GET list                            | Feature     | Pending invitation token                      |
| 35  | `tests/Feature/Api/Team/TeamMembersTerminateSuccessFlowTest.php`     | Admin terminates non-self with TOTP → 200                        | Feature     | Company admin + TOTP                          |
| 36  | `tests/Feature/Api/Referrals/ReferralsStatusFlowTest.php`            | Authenticated GET status; funnel counts + entitlement order      | Feature     | TEST_USER_1 bearer                            |
| 37  | `tests/Feature/Api/Sponsorships/SponsorshipsBatchInviteFlowTest.php` | `confirm=false` validate; `confirm=true` all-or-nothing          | Feature     | Sponsor company admin                         |
| 38  | `tests/Feature/Api/Templates/LibraryManagementCrudFlowTest.php`      | `@simplifi.ro` write ops; external user 403; publish/archive     | Feature     | Simplifi staff + external user                |
| 39  | `tests/Unit/Api/Csc/DevPackZipTest.php`                              | ZIP bundle contents, path traversal on `loadDocFile`             | Unit        | Admin doubles                                 |



### P2 — Validation, edge cases, strengthening


| #   | Suggested file                                                    | Test / behavior                                               | Type    | Prerequisites           |
| --- | ----------------------------------------------------------------- | ------------------------------------------------------------- | ------- | ----------------------- |
| 40  | `tests/Unit/Api/Billing/BillingEndpointGuardsTest.php` *(extend)* | 405 dataset; context-zero → 400; guard-order characterization | Unit    | Existing doubles        |
| 41  | `tests/Support/BillingFixtureHelper.php`                          | Narrow cleanup for grant/seat write scenarios                 | Support | Dedicated test company  |
| 42  | `tests/Unit/Api/Documents/IndexTest.php` *(strengthen)*           | POST create returns 422/201 with expected error or uuid       | Unit    | `createDraft` doubles   |
| 43  | `tests/Unit/Api/Documents/SendDocumentTest.php` *(extend)*        | Success queues invites and returns sent status                | Unit    | Email queue spy         |
| 44  | `tests/Unit/Api/Company/CompanyBrandingTest.php`                  | Welcome text validation; logo/banner upload auth              | Unit    | Company admin doubles   |
| 45  | `tests/Feature/Api/Referrals/ReferralsValidateHappyPathTest.php`  | Valid invite code → `valid: true`                             | Feature | Active invite fixture   |
| 46  | `tests/Unit/Api/Referrals/ReferralsRemindApiTest.php` *(extend)*  | 401, 405, invalid UUID, not-found matrix                      | Unit    | Existing doubles        |
| 47  | `tests/Unit/Api/Csc/DeclineReasonsTest.php`                       | Integrator decline catalog GET                                | Unit    | Integrator auth doubles |



### P3 — Low-risk completeness


| #   | Suggested file                                   | Test / behavior                 | Type |
| --- | ------------------------------------------------ | ------------------------------- | ---- |
| 48  | `tests/Unit/Api/Misc/CountiesLocalitiesTest.php` | GET reference data; 405 on POST | Unit |
| 49  | `tests/Unit/Api/Misc/LegalDocumentsTest.php`     | Legal document retrieval        | Unit |
| 50  | `tests/Unit/Api/Misc/OpenApiTest.php`            | OpenAPI spec serving            | Unit |
| 51  | `tests/Unit/Api/Preview/GetLogoUrlTest.php`      | Logo URL resolution edge cases  | Unit |
| 52  | `tests/Unit/Api/Account/AccountAvatarTest.php`   | Avatar upload/delete            | Unit |


---



## 5. Modules Already Well Covered

Avoid duplicating work in these areas unless strengthening weak assertions
called out above. CSC work that remains is **Unit only** (see §4 items 4–8,
20–24, 39, 47); do not add Feature CSC suites.


| Module                                       | Evidence                                                                                               |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| **Billing — local read paths**               | Catalog, entitlements, me, ledgers, company overview: 93–98% lines; deep unit datasets + feature reads |
| **Billing — grants & seats (unit)**          | List filters, assignment validation, tenant hiding, capacity — local grant/seat unit largely done      |
| **Billing — webhook guards + payment packs** | Existing Stripe unit files (signature, unknown events, idempotent grant keys) — **no further Stripe tests planned** |
| **CSC — access lifecycle**                   | Status, questionnaire, request, evidence guards with strong error codes                                |
| **CSC — API client provisioning**            | Create rollback, rotate TOTP, patch name/disable (local DB). Keycloak sync **out of scope**            |
| **CSC — hosted enrollment gates**            | Routing/expiry, choose SCA, accept terms, SMS/TOTP/email PIN verify, status/redirect_ack               |
| **CSC — integrator enrollments**             | Validation matrix, 402 allowance, resume idempotency, basic GET serialization                          |
| **Account/companies**                        | 99% coverage; exhaustive validation + feature auth/create/list/isolation                               |
| **Account GET + DELETE guards**              | `CurrentAccountResponseTest`, `AccountDeletionTest` negative matrix                                    |
| **Company representatives/requests (unit)**  | Broad mocked coverage: auth, lists, seals, revoke failures, request CRUD                               |
| **Company feature auth**                     | Token refusal, pagination, admin-required POST, cross-tenant probes                                    |
| **Team invitations (unit)**                  | Full matrix: list/create/revoke/resend/accept/preview with rate limits                                 |
| **Team members (unit)**                      | List filters, admin gate, TOTP, terminate success with mocks                                           |
| **Referrals auth & validation**              | Unit exact error codes; feature invite/remind flows with email assertions                              |
| **Templates user CRUD**                      | 17 unit files + CRUD/permissions/versioning/parties feature flows                                      |
| **Platform retention**                       | 98% coverage; admin checks, policy set/get, security events                                            |
| **Verify negative/PII**                      | Uniform `{valid: false}`; no storage paths or PII in JSON                                              |
| **Signing reject**                           | Full guard matrix + successful DB update (`RejectDocumentTest`)                                        |
| **Signing guest access (unit)**              | Guest vs standard invitation, read policy, decline reasons                                             |
| **Documents ownership**                      | CC email, foreign tenant, integrator sign-code hiding                                                  |
| **Documents mutation guards**                | addSigners, send, delete, remind, audit cert, file download guards                                     |


---



## 6. Notes — module status (this plan is source of truth)

Former module strategy files are not required to implement §4. Status below is
copied into this plan so it stands alone.

### Billing (local)

| Area                        | Status                                                                                                                                                                    |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 1 unit (non-Stripe)   | **~90% done** — doubles, catalog, overview, entitlements, me, ledgers, grants, seats                                                                                      |
| Phase 1 guards              | **Partial** — missing 405 matrix, context-zero, guard-order                                                                                                               |
| Phase 2 feature             | **~45% done** — auth/routing, catalog, entitlements partial; **grant/seat happy paths missing**; `BillingLocalConsistencyFlowTest` and `BillingFixtureHelper` not created |
| Stripe                      | **Excluded** — existing unit files may remain; **no further Stripe tests** (webhook sync, invoice paid chain, checkout customer creation, portal Stripe 502)              |
| **Outstanding**             | Local billing items 25–28, 40–41 in §4; do not prioritize Stripe webhook/checkout                                                                                         |

### CSC

| Area                                                             | Status                                                                                                                |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Unit groups                                                      | **~26 / 73 done or partial** (+3 newer controllers: `alerts`, `integrator/entitlements`, `decline_reasons`)           |
| Integration / Feature workflows                                  | **Out of scope** — do not add `tests/Feature/Api/Csc/`. Historical 24 workflows are Unit-only if doubles exist; skip if Keycloak/Checkin/EJBCA/SignServer cannot be faked. |
| Access, api_client (local), hosted enrollment, enrollments       | Strongest areas — see §5. Keycloak API-client sync **out of scope**.                                                  |
| Signing, webhooks, ledgers, integration dashboards, review       | **Open as Unit**; SignServer crypto and credential issuance **not feasible in-suite**                                 |
| Route reachability                                               | **Unit** (item 4) — not a Feature test                                                                                |
| Coverage report                                                  | **Stale** — regenerate after next major CSC slice; do not use Aug-19 % for controllers with new tests                 |
| **Outstanding**                                                  | Unit items 4–8, 20–24, 39, 47 in §4; skip flows that cannot be doubled                                                |

### Other product areas

- **Referrals** — unit for auth/validation, feature for email queue. Outstanding: status feature (item 36), validate happy path (item 45), send/remind unit negatives (item 46).
- **Templates** — user templates well covered. Outstanding: library feature CRUD/versioning/publish (item 38).
- **Author documents** — unit guards strong. Outstanding: send success (item 19), preview HTTP (§3 P1).
- **Signing** — reject/auth guards solid. Outstanding: pack flow (item 31), claim email (item 32), `performSignSteps` integration (§3 P0), guest signing (items 11–12). Verify `uploadSha256` Integration and signing `performSignSteps` Integration stay as they were (not identification/CSC/Stripe).
- **No dedicated prior plan** — Sponsorships, Partner, Lists HTTP, Account sub-controllers still in scope (TOTP, signatures, avatar; **not** sessions/certificates). Checkin HTTP lifecycle and Users/Public certificates/Auth backchannel are **out of scope or not feasible**.

---



## 7. Suggested Implementation Order

1. **P0 security surface** — TOTP lifecycle, account deletion success, active role, lists permission gate (no backchannel logout, sessions, platform tokens, or public certificates)
2. **P0 local billing guards** — portal 401/403/405/return_url; subscription-item TOTP on local mutations (no Stripe webhook/checkout/cancel orchestration)
3. **P0 core document/signing** — documents send/cancel, guest signing feature, preview controller
4. **P0 greenfield modules** — sponsorships auth, partner onboard (no checkin HTTP lifecycle, no users IdP bootstrap)
5. **P0 CSC signing (Unit)** — route reachability **unit** test, then signing session unit suite; skip SignServer crypto if unfakeable
6. **P1 feature mutations** — billing grant/seat happy paths, team accept/terminate, referrals status
7. **P1 CSC admin (Unit)** — webhooks, redirect URIs, integration dashboards, ledgers; skip Keycloak-as-SUT and cert issuance
8. **P2 strengthening** — permission flow fixtures, guard matrices, assertion depth on existing tests; company branding **unit**
9. **Regenerate coverage** — after items 1–5 to replace stale Aug-19 report for CSC and new modules

---

*Audit performed 2026-08-31. Policy revision 2026-08-31 (Stripe / Keycloak / tokens / certificates out of scope; CSC, general setup, TOTP, identification Unit-only; skip if unfakeable). Coverage evidence cutoff: 2026-08-19. No tests were implemented as part of this audit or revision.*
