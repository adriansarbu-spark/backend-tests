# Coverage Testing Strategy

## Overview

This strategy is based on the HTML coverage snapshot under
`tests/coverage_report/publicapi-v1/`, generated on **2026-08-11 13:10 UTC**.
The dashboard, every one of the 102 file-level `*.php.html` reports, the
corresponding controllers under `catalog/controller/publicapi/v1/`, the current
Unit and Feature suites, and `tests/strategies/testing-guidelines.md` were
reviewed.

The dashboard reports:

| Scope | Lines | Methods | Classes |
|---|---:|---:|---:|
| All 102 controllers | 4,115 / 21,866 (18.82%) | 46 / 644 (7.14%) | 6 / 102 (5.88%) |
| Account | 99 / 2,790 (3.55%) | 4 / 94 (4.26%) | 1 / 23 |
| Billing | 818 / 2,108 (38.80%) | 8 / 62 (12.90%) | 3 / 12 |
| Company | 370 / 640 (57.81%) | 4 / 24 (16.67%) | 0 / 2 |
| CSC | 361 / 3,815 (9.46%) | 6 / 121 (4.96%) | 0 / 30 |
| eSign | 1,404 / 3,177 (44.19%) | 15 / 96 (15.62%) | 0 / 3 |
| Referrals | 166 / 434 (38.25%) | 2 / 10 (20.00%) | 2 / 6 |
| Team | 226 / 525 (43.05%) | 4 / 21 (19.05%) | 0 / 6 |

The percentage is not the priority model. The largest meaningful risks are:

- document ownership, signer access, guest-link access, group signing, deletion,
  purge, and audit-certificate rules;
- account deletion, personal-data export, session/token revocation, and public
  certificate lookup;
- tenant isolation and TOTP gates for team, company, and CSC administration;
- billing change-risk, TOTP, audit, and webhook reconciliation logic;
- externally backed identification, signing, PDF, identity-provider, and payment
  paths that need deterministic Unit coverage rather than Feature coverage.

### Important limitation of this snapshot

The HTML line popovers attribute covered lines only to `P\Tests\Unit\...`
classes; no Feature class appears. Existing Feature workflows therefore must be
treated as separately reviewed coverage, not as absent functionality simply
because a controller is 0% in this report.

There is also a source/report mismatch. For example, the report shows 0% for
`csc/api_client.php`, `csc/enroll.php`, `csc/enrollments.php`, enrollment photos,
and credential issuance, while the current tree contains extensive CSC Unit
tests for those controllers. The strategy begins with a baseline-integrity gate
to prevent duplicate work. The HTML snapshot remains useful for locating
uncovered line spans and methods, but it is not authoritative about which test
files currently exist or pass.

The report contains line, method, class, and CRAP data, but no branch metric.
Important branch gaps below were inferred by comparing uncovered line spans with
the source conditions and state machines.

### Existing test situation

- Local billing catalog, entitlement, ledger, grant, and seat behavior already
  has broad Unit coverage and selective Feature workflows. The best-covered
  billing controllers are between 93% and 98% by line; do not add shallow cases
  to chase their remaining defensive lines.
- Templates and library controllers have broad Unit suites and Feature workflows
  despite partial method scores. Their residual gaps are lower-value than the
  signing, account-security, and tenant-control gaps below.
- Referrals, company representatives, team members/invitations, CSC access, and
  author documents have meaningful current tests. Recommendations below extend
  specific missing actions; they do not repeat existing authentication,
  validation, or basic CRUD cases.
- `documents.php` (0 / 2,396 executable lines) and `signing_guest.php`
  (0 / 846) are the largest high-risk controller gaps. The current Documents and
  Signing Feature flows cover basic owner/invitee behavior, but they do not
  replace isolated branch coverage for these controllers.
- `signing.php` is partially covered (575 / 1,773 lines, 3 / 39 complete
  methods). Existing Unit tests focus on routing, file access, single signing,
  rejection, and draft signatures. Pack signing, signer claims, audit files,
  signer-side deletion, and response filtering remain largely uncovered.

## Testing constraints

All implementation phases must follow these rules:

1. Do not modify models or controllers to make a test pass. Test-only subclasses,
   fakes, aliases, fixtures, and helper code may be added under `tests/`.
2. Use Unit tests for controller branching, transformations, decisions, provider
   error mapping, boundary conditions, and failure paths that can be isolated.
3. Use Feature tests only for critical HTTP workflows, authentication,
   authorization, ownership, tenant boundaries, and important state transitions.
   Do not enumerate trivial malformed payloads over HTTP.
4. Feature setup and cleanup must use public endpoints. No raw SQL, OpenCart DB,
   PDO, or DB-backed fixture helper may arrange Feature state. Pre-seeded UUIDs
   from `tests_config.php` are allowed, but mutations still go through APIs.
5. Reuse `ApiAuthHelper`, existing area API helpers, `SigningFlowHelper`,
   `DocumentsFlowManager`, and `DocumentsApiHelper`. Add a helper only after two
   tests need the same setup or payload.
6. Feature tests that depend on configured infrastructure must use the existing
   guard/skip conventions and report a precise skip reason. Known upload-folder
   failures should skip rather than masquerade as product failures.
7. Every new Pest `test()` must have an immediately preceding PHPDoc block with
   short **Prerequisites** and numbered **Steps**. Feature names must use the
   reader-friendly product-area style from the guidelines.
8. Stable assertions take precedence: HTTP status, application error code,
   response shape, identifiers, persisted state, access outcome, audit call, or
   side effect. Expected failures should include a non-empty `error` field, but
   never assert exact human-readable success or error sentences.
9. Security failures must also assert that no protected write/provider call was
   made. Tenant tests must assert that foreign identifiers and secrets are absent
   from the response.
10. File tests should assert safe resolved paths, PDF signatures such as `%PDF`,
    status transitions, and audit events. Do not couple tests to every response
    header or sanitized display filename unless it is a documented contract.
11. Time-sensitive tests should inject or bound time where the current doubles
    permit it, compare instants, and avoid exact countdown values. Use unique
    names and existing polling/retry helpers in Feature tests.
12. Regenerate coverage after each phase and compare meaningful methods and
    branches, not only the headline percentage. No phase has a 100% target.

## Feature-test exclusions

| System | Feature-test decision | Reason and preferred alternative |
|---|---|---|
| Account and legacy `/users` routes | No new Feature tests | Explicitly excluded. Cover deletion eligibility, sanitization, exports, sessions, TOTP, signatures, and provider mapping with Unit doubles. Existing Account Feature files are not a reason to add more. |
| Identification and identity-verification flows | No Feature tests | Explicitly excluded. Checkin/vendor state, identity photos, and identity verification depend on external identity infrastructure and sensitive artifacts. Use Unit tests with sanitized fixtures and fake vendor responses. |
| Stripe and Stripe-backed billing routes | No Feature tests | Explicitly excluded. Use Unit tests for pure calculations, TOTP/risk decisions, idempotency, event mapping, and provider failures. Do not call Stripe test mode. |
| CSC hosted enrollment/signing, credential issuance, and anonymous review | No Feature tests | These cross Keycloak, Checkin, EJBCA/SignServer, SMS/email, filesystem, and metering boundaries. Exercise controllers and services with fakes; reserve end-to-end verification for a separately provisioned contract/smoke environment. Local CSC admin configuration endpoints are not excluded. |
| Hash signing and simple/qualified guest commit | No Feature signing ceremony | The final act requires certificate material and SignServer. Unit-test every pre-provider guard and the workflow call contract. A Feature test may cover guest-link read/access behavior without committing a signature. |
| Account/platform session and offline-token administration | No Feature tests | Keycloak Admin is the system of record and is not deterministic in the Feature environment. Unit-test subject selection, authorization, response mapping, and revoke calls with a fake Keycloak client. |
| Author-document/legacy preview rendering | No Feature rendering assertions | Rendering depends on the Chromium PDF service and complex HTML layout. Unit-test transformations and request construction; use a separate smoke check for the real renderer. |
| Outbound webhook test/resend delivery | No Feature network call | Delivery targets are remote and non-deterministic. Unit-test signature, payload, tenant scoping, retry, and dispatcher outcomes with a fake transport. CRUD of webhook configuration remains suitable for Feature testing. |

## Baseline integrity gate — reconcile the report before adding tests

This is a prerequisite, not a request to add test cases.

1. Record the exact command, suite/filter, coverage driver, and
   `SKIP_INTEGRATION_TESTS` value used to generate coverage.
2. Run the current Unit suite before regenerating coverage and resolve why the
   existing CSC controller tests are not attributed to their source files.
3. Run Feature tests separately. If coverage cannot cross the HTTP process,
   record Feature scenarios in a workflow matrix rather than treating the HTML
   line report as proof that they are absent.
4. Regenerate the same 102-controller report from the current source and retain
   its generation timestamp in the implementation notes.
5. Mark every proposed item below as `covered`, `still missing`, `skipped by
   environment`, or `not attributable`. Only implement `still missing` items.

The merge gate for this step is a trustworthy source-to-test inventory, not a
percentage increase.

## Phase 1 — Protect document and signing access, lifecycle, and evidence

### `catalog/controller/publicapi/v1/documents.php`

**Recommended test type:** Both

**Why this belongs in this phase:** This is the highest dashboard CRAP risk and
controls ownership, signer exposure, group state, files, reminders, deletion,
purging, and audit certificates.

**Existing coverage / gap:** The HTML reports 0 / 2,396 lines and 0 / 42 methods.
`Feature/Api/Documents/DocumentsFlowTest.php` already covers draft creation,
owner/outsider detail, personal/company visibility, and owner/outsider PDF
download. `Feature/Api/Signing/SigningFlowTest.php` covers several single-document
sign/reject/cancel workflows. There is no controller Unit suite, and group,
reminder, audit-certificate, delete/purge, CC, and integrator filtering branches
remain unprotected.

**Tests to add:**

- `Documents API — UUID detail never exposes another tenant or non-originating integrator sign codes`
  - Arrange personal, company, foreign-company, and integrator-origin rows with
    mixed signers.
  - Assert owner/active-company rules, 403/404 hiding behavior, and response
    structure. Only the originating integrator may receive `sign_code`,
    `signing_url`, and its own `return_url`.
  - Assert no foreign document/signer identifier appears.
- `Documents API — group locks block every draft mutation`
  - Exercise add-signers, annotations, PUT draft, detach/join behavior, and send
    against locked and unlocked group fixtures through `index()`.
  - Assert the stable lifecycle error code and that no signer, annotation,
    document, or upload write occurred when locked.
- `Documents API — CC recipients normalize without granting document access`
  - Cover string/array input, case folding, deduplication, maximum count/length,
    invalid entries, and stored response splitting.
  - Assert normalized addresses and model payload only; separately prove a CC
    email does not satisfy owner or signer access.
- `Documents API — reminder reservation is committed only after queue success`
  - Cover wrong lifecycle/status, signer lookup, cooldown, guest continuation
    routing, queue failure, and success.
  - Assert reservation release on queue failure and commit exactly once on
    success; do not assert mail prose.
- `Documents API — delete and purge preserve lifecycle and stakeholder invariants`
  - Cover owner-PENDING requiring cancel, signer-PENDING requiring reject,
    signer-only soft hide, DRAFT/CANCELLED auto-purge, COMPLETED with another live
    stakeholder, explicit owner purge, company-admin uploader visibility, and
    purge failure.
  - Assert visibility/file-version calls and resulting status/error code; never
    touch real upload storage in Unit tests.
- `Documents — cancelling a sent document prevents further signer action`
  - Feature setup must create and send through public APIs, cancel as owner, then
    attempt signing as invitee.
  - Assert lifecycle/status and access outcome. Extend the existing Signing flow
    instead of duplicating draft and invite setup.
- `Documents — owner and signer receive the correct completion evidence`
  - Complete a configured signing flow, fetch owner and signer audit-certificate
    endpoints, then try with an unrelated user.
  - Assert HTTP status, `%PDF`, and access outcome. Skip via existing signing
    infrastructure guards when certificate generation is unavailable.

**Suggested test files:**

- New `tests/Unit/Api/Documents/DocumentsRoutingAndOwnershipTest.php`
- New `tests/Unit/Api/Documents/DocumentsGroupsAndMutationsTest.php`
- New `tests/Unit/Api/Documents/DocumentsReminderAndDeletionTest.php`
- New `tests/Unit/Api/Documents/_support/DocumentsTestDoubles.php`
- Extend `tests/Feature/Api/Documents/DocumentsFlowTest.php` and
  `tests/Feature/Api/Signing/SigningFlowTest.php`

### `catalog/controller/publicapi/v1/signing.php`

**Recommended test type:** Both

**Why this belongs in this phase:** It is the authenticated signer boundary and
contains complex access, pack, billing, TOTP, claim, and signing state machines.

**Existing coverage / gap:** 575 / 1,773 lines (32.43%) and 3 / 39 complete
methods. Existing Unit files cover routing, single file access, single signing
guards, rejection, and draft saves. `getSignerInfo`, pack construction/signing,
audit files, signer delete, payload-to-annotation matching, claims, and claim
email are incomplete or 0%.

**Tests to add:**

- `Signing API — signer envelope contains only pack members for the same principal`
  - Arrange a group with matching and foreign emails, guest/sponsored rows,
    hidden rows, and DRAFT/PENDING/COMPLETED members.
  - Assert authorization via email, bound role, customer, or succeeded claim;
    annotations and signers are filtered; unrelated pack members and sign codes
    are absent.
- `Signing API — pack preflight prevents partial writes`
  - Cover invalid member payload, wrong turn, insufficient aggregate one-sided
    balance, missing certificate/seal, and invalid TOTP.
  - Assert no signing workflow call for hard preflight failures, TOTP is checked
    once per qualified pack, and simple-only packs do not demand TOTP.
- `Signing API — pack result classifies signed skipped and failed members`
  - Fake mixed workflow outcomes and group completion.
  - Assert counts, per-document stable status/reason, one shared timestamp, and a
    redirect only from the first successfully signed originating-integrator
    member.
- `Signing API — claim-email is scoped rate-limited and idempotent`
  - Cover exact email matching, successful prior claim, CNP auto-claim fallback,
    rate limit, OTP queue failure, and propagation to eligible pack siblings.
  - Assert claim/audit/queue calls and masked response fields, never raw secrets.
- `Signing API — signer deletion cannot silently abandon an actionable invite`
  - Cover unauthorized caller, pending actionable invite, signed/rejected rows,
    and already-hidden visibility.
  - Assert 403/409 or idempotent hide behavior and prove files are never purged by
    the signer path.
- `Signing — a signer cannot cross from one pack or email identity into another`
  - Use public APIs and existing helpers to create the smallest viable grouped
    flow, then attempt access/action with the second test account.
  - Assert access outcome and absence of sibling sign codes. Add only after the
    baseline shows this is not already exercised indirectly.

**Suggested test files:**

- New `tests/Unit/Api/Signing/GetSignerInfoAndPackEnvelopeTest.php`
- New `tests/Unit/Api/Signing/PackSigningTest.php`
- New `tests/Unit/Api/Signing/ClaimEmailTest.php`
- New `tests/Unit/Api/Signing/SignerDeleteAndAuditFileTest.php`
- Extend `tests/Unit/Api/Signing/_support/SigningTestDoubles.php`
- Extend `tests/Feature/Api/Signing/SigningFlowTest.php`

### `catalog/controller/publicapi/v1/signing_guest.php`

**Recommended test type:** Unit plus selective read-only Feature tests

**Why this belongs in this phase:** A bearer-like public `sign_code` protects
documents and signing authority. Every method is uncovered, including completed
link expiry and guest/sponsored pack access.

**Existing coverage / gap:** 0 / 846 lines and 0 / 24 methods; no current Unit or
Feature file directly targets this controller.

**Tests to add:**

- `Guest signing API — access is restricted to guest links and eligible same-email pack siblings`
  - Cover unknown link, qualified non-guest link, sponsored row outside a group,
    mismatched/empty email, valid guest primary, and same-email sponsored sibling.
  - Assert 403/404 outcomes and that foreign pack members are omitted.
- `Guest signing API — read policy enforces lifecycle visibility and completed-link expiry`
  - Cover DRAFT, CANCELLED, REJECTED, missing/invalid `completed_at`, active
    completion window, exact cutoff vicinity, expiry, and purged files.
  - Assert stable status/code, no VIEWED transition on failed reads, and one VIEWED
    transition plus audit event after a successful file lookup.
- `Guest signing API — commit validates and merges one payload per pack member`
  - Cover invalid JSON/type/coordinates/branding/timezone, first-commit payload
    requirement, stored-payload reuse, legal acceptance once, same-session member
    merge, and pending-identification replay.
  - Assert session/acceptance/audit state and pack required/committed counts. Fake
    Checkin; do not call an identity provider.
- `Guest signing API — simple commit validates annotations before the signing boundary`
  - Cover annotation count, seal placement rejection, unsupported type, missing
    coordinates, safe display-name fallback/truncation, and workflow failure.
  - Fake `SimpleSignature`/workflow and assert the certificate/payload metadata
    contract, session completion only when every member is signed, and no real
    SignServer call.
- `Guest signing API — declined status exposes only curated safe reasons`
  - Arrange hidden fraud concerns, retryable and terminal concerns, plus missing
    verification data.
  - Assert response shape, safe aliases/messages presence, retryability, and that
    raw vendor prose/codes are absent.
- `Guest signing — a valid invite can read its document but another guest link cannot`
  - Feature setup must create/send via Documents APIs and use only GET entry/file
    calls; do not run identification or signing.
  - Assert the intended link gets document shape/PDF while an unrelated link does
    not disclose it.

**Suggested test files:**

- New `tests/Unit/Api/SigningGuest/GuestAccessAndEnvelopeTest.php`
- New `tests/Unit/Api/SigningGuest/GuestCommitTest.php`
- New `tests/Unit/Api/SigningGuest/GuestFileAndStatusTest.php`
- New `tests/Unit/Api/SigningGuest/_support/SigningGuestTestDoubles.php`
- New `tests/Feature/Api/Signing/GuestSigningAccessFlowTest.php`

### `catalog/controller/publicapi/v1/signing/signhash.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Hash signing is security-sensitive and
operates on caller-supplied digests and certificates, but its provider boundary
is unsuitable for Feature tests.

**Existing coverage / gap:** 0 / 428 lines and 0 / 5 methods; no direct tests.

**Tests to add:**

- `Sign hash API — rejects malformed or unsupported digest requests before SAM`
  - Dataset missing/invalid base64, wrong digest length/algorithm, invalid request
    ID/caller claim, and unsupported signature configuration.
  - Assert status/application code and zero certificate/SAM calls.
- `Sign hash API — certificate must be active and owned by the current account`
  - Cover unknown, foreign, revoked/expired, and valid certificate rows.
  - Assert existence-hiding behavior and no provider call for invalid rows.
- `Sign hash API — SAM failures map to stable client codes and sanitized audit`
  - Fake known/unknown SAM errors and success.
  - Assert result structure, one audit/log event, digest fingerprint rather than
    raw digest, and sanitized caller metadata.

**Suggested test file:** New
`tests/Unit/Api/Signing/SignHashTest.php`.

### `catalog/controller/publicapi/v1/verify.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** The endpoint is public and resolves audit
evidence; it must resist enumeration and never expose storage details.

**Existing coverage / gap:** 0 / 127 lines and 0 / 8 methods; no direct tests.

**Tests to add:**

- `Verification API — unknown and rate-limited certificate lookups disclose no file details`
  - Cover missing/invalid UUID, unknown certificate, and rate limit.
  - Assert stable response shape/code and absence of paths, hashes not intended by
    contract, and internal identifiers.
- `Verification API — uploaded and CSC digests map to verified or not-verified deterministically`
  - Fake matching/mismatching uploaded SHA-256 and CSC signed-file SHA-256 rows.
  - Assert boolean/status fields, normalized Zulu timestamps, and no raw file read
    outside a temporary fixture.
- `Verification API — malformed base64 and missing files fail closed`
  - Exercise digest conversion and missing/escaping relative paths through the
    public controller entry.
  - Assert not-verified/error outcome and no exception or path disclosure.

**Suggested test file:** New
`tests/Unit/Api/Verification/VerificationControllerTest.php`.

### `catalog/controller/publicapi/v1/esign/author/documents.php`

**Recommended test type:** Unit; extend Feature only for local ownership actions

**Why this belongs in this phase:** It creates many signing documents from
campaign data and has ownership, transformation, rollback, and PDF boundaries.

**Existing coverage / gap:** 265 / 1,498 lines (17.69%) and 4 / 43 complete
methods. Current Unit tests cover routing, create/save/campaign/upload guards and
early send guards. Current Feature tests cover campaign ownership, unauthorized
update, send, and repeat-send behavior. Get/list/delete, preview transformation,
structured normalization, late send failure, and persistence branches remain.

**Tests to add:**

- `Author documents API — get list and delete remain company-scoped`
  - Cover owner company, foreign company, missing UUID, draft deletion, and
    non-draft deletion.
  - Assert response identifiers/state and no foreign model write.
- `Author documents API — preview transformation binds only declared party roles`
  - Use compact HTML fixtures with duplicate/missing signature blocks, unsafe
    attributes, party ordering, and field values.
  - Assert transformed annotations/request payload and placeholder dimensions;
    fake Chromium and do not compare rendered pixels or exact HTML formatting.
- `Author documents API — structured campaign payload normalizes one value per role and field`
  - Cover associative/row inputs, unknown role/field keys, duplicates, and
    recipient conversion.
  - Assert normalized model calls and stable validation codes.
- `Author documents API — late send failures do not leave partial signing documents`
  - Fake PDF success followed by upload, billing, signer, or persistence failure.
  - Assert rollback/absence of further writes and stable error structure; success
    should assert linked signing UUIDs without sending real email.

**Suggested test files:**

- New `tests/Unit/Api/AuthorDocuments/GetListDeleteTest.php`
- New `tests/Unit/Api/AuthorDocuments/PreviewTransformationTest.php`
- New `tests/Unit/Api/AuthorDocuments/StructuredCampaignAndSendTest.php`
- Extend `tests/Unit/Api/AuthorDocuments/_support/AuthorDocumentsTestDoubles.php`

## Phase 2 — Protect account privacy and administrative authority with Unit tests

No new Feature test in this phase is permitted for Account or Identification.

### `catalog/controller/publicapi/v1/account.php` and `catalog/controller/publicapi/v1/users.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Account deletion and account response
sanitization are privacy/security boundaries. The legacy `/users` route also
aliases registration/account behavior.

**Existing coverage / gap:** Both controller reports are 0%. Account-company
tests cover a different controller. No Unit suite protects deletion or the
shared current-account response builder.

**Tests to add:**

- `Account API — deletion requires personal context eligibility and valid TOTP`
  - Cover unauthenticated/already-deleted, company context, last-admin blockers,
    no TOTP, missing/invalid TOTP, and eligible success.
  - Assert cascade is never called on a rejected request, TOTP audits record
    success/failure, and provider failure maps to a stable status/code.
- `Account API — current-account response removes internal and secret fields`
  - Arrange duplicate-account, declined verification, avatar, export, language,
    certificate, and deletion-eligibility data.
  - Assert only safe curated decline data appears and internal IDs, token, secret,
    password, raw decline JSON, and profile token are absent.
- `Users API — registration and account aliases preserve the same security contract`
  - Cover anonymous registration, already logged-in conflict, duplicate email,
    and GET response sanitization.
  - Assert stable codes and model calls, not localized text.

**Suggested test files:**

- New `tests/Unit/Api/Account/AccountDeletionTest.php`
- New `tests/Unit/Api/Account/CurrentAccountResponseTest.php`
- New `tests/Unit/Api/Account/UsersAliasTest.php`

### `catalog/controller/publicapi/v1/account/data_export.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Data exports contain the full account record
and require strict ownership, expiry, and filesystem boundaries.

**Existing coverage / gap:** 0 / 156 lines and 0 / 3 methods; no tests.

**Tests to add:**

- `Account data export — create is idempotent/rate-limited and customer scoped`
  - Cover active export reuse, export creation failure, and successful metadata.
  - Assert the archive builder receives only the current customer and response
    does not reveal a storage path.
- `Account data export — download rejects foreign expired missing and escaping files`
  - Use temporary in-root/out-of-root fixtures and owned/foreign export rows.
  - Assert 404/410 or successful archive stream contract; no raw path in JSON.

**Suggested test file:** New
`tests/Unit/Api/Account/AccountDataExportTest.php`.

### `catalog/controller/publicapi/v1/account/sessions.php` and `catalog/controller/publicapi/v1/platform/tokens.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Session/offline-token enumeration and revoke
operations are security-sensitive and use an external Keycloak boundary.

**Existing coverage / gap:** Both reports are 0% (224 and 180 executable lines).

**Tests to add:**

- `Account sessions API — list maps only the current subject and safe client labels`
  - Fake Keycloak sessions with unknown clients and mixed timestamps.
  - Assert response shape/times and absence of tokens, secrets, and foreign
    subjects.
- `Account sessions API — revoke one and revoke all validate target and audit outcome`
  - Cover missing action/session, unknown session, provider unavailable/failure,
    and success.
  - Assert exact Keycloak fake calls and no revoke on invalid input.
- `Platform tokens API — only authorized platform callers can list or revoke resolved subjects`
  - Cover role UUID resolution, no subjects, foreign company role, provider
    failure, unknown offline session, and success.
  - Assert tenant-scoped subject set and no token material in responses.

**Suggested test files:**

- New `tests/Unit/Api/Account/AccountSessionsTest.php`
- New `tests/Unit/Api/Platform/PlatformTokensTest.php`

### `catalog/controller/publicapi/v1/account/certificates.php` and `catalog/controller/publicapi/v1/public/certificates.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Certificate ownership, revocation, download
format, and public CNP lookup are sensitive; provider-backed issuance is excluded
from Feature testing.

**Existing coverage / gap:** Both reports are 0% (451 and 209 executable lines).
Two existing CRL Feature files do not cover these controller branches and must
not be expanded under this strategy.

**Tests to add:**

- `Account certificates API — list and detail expose only owned public material`
  - Cover active/revoked/expired rows, PEM inclusion flag, foreign UUID, and dual
    certificate mode.
  - Assert normalized dates/status and that private keys, provider credentials,
    and foreign identifiers are absent.
- `Account certificates API — revoke and request map prerequisite and provider failures safely`
  - Fake legal/TOTP/identity prerequisites, already-revoked state, provider
    failure, and success.
  - Assert no provider call before all local gates pass and audit/state calls on
    success.
- `Public certificates API — lookup and downloads are rate-limited and format safe`
  - Cover invalid CNP, unknown/foreign UUID, allowed `json`/binary/zip formats,
    invalid format, and lookup/download rate limits.
  - Assert only certificate/public metadata is returned and archive filenames
    cannot escape a temporary directory.

**Suggested test files:**

- New `tests/Unit/Api/Account/AccountCertificatesControllerTest.php`
- New `tests/Unit/Api/PublicApi/PublicCertificatesTest.php`

### `catalog/controller/publicapi/v1/account/totp.php` and `catalog/controller/publicapi/v1/account/signatures.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** TOTP and saved-signature assets authorize or
represent sensitive signing actions.

**Existing coverage / gap:** Both reports are 0% (190 and 239 executable lines).

**Tests to add:**

- `Account TOTP API — setup confirm and disable enforce state transitions and audit`
  - Cover already configured, setup without confirmation, invalid/valid code,
    missing provider support, disable without proof, and idempotent status.
  - Assert secrets are returned only where the setup contract requires them and
    never after confirmation/status.
- `Saved signatures API — create validates image data and ownership`
  - Cover malformed/oversized data URL, MIME mismatch, safe supported formats,
    upload failure, and successful metadata.
  - Assert no arbitrary path/name is trusted.
- `Saved signatures API — list and delete are customer scoped`
  - Cover foreign UUID, missing asset, delete failure, and success.
  - Assert no cross-customer metadata or file delete call.

**Suggested test files:**

- New `tests/Unit/Api/Account/AccountTotpTest.php`
- New `tests/Unit/Api/Account/AccountSignaturesTest.php`

### `catalog/controller/publicapi/v1/checkin.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Identification Feature tests are excluded,
but the controller contains token binding, rate limiting, replay, and state
transition logic worth isolating.

**Existing coverage / gap:** The controller report is 0 / 489 lines. Existing
`Unit/Api/Checkin` tests cover decline-catalog, payload, and expiry-override logic,
not the controller entry points.

**Tests to add:**

- `Checkin API — guest token requests are bound to the intended sign code and session`
  - Cover missing/malformed/foreign/replayed token and rate limit.
  - Assert no vendor request or session mutation before local validation succeeds.
- `Checkin API — submit and result map vendor outcomes without exposing raw data`
  - Fake accepted, pending, declined, malformed, and provider-failure responses.
  - Assert stable application status, one state transition/audit call, curated
    decline fields, and absence of raw vendor payload/prose.

**Suggested test files:**

- New `tests/Unit/Api/Checkin/CheckinControllerTokenTest.php`
- New `tests/Unit/Api/Checkin/CheckinControllerResultTest.php`

## Phase 3 — Enforce tenant isolation and administrative control

### `catalog/controller/publicapi/v1/team/invitations/{accept,preview,resend,revoke}.php`

**Recommended test type:** Both

**Why this belongs in this phase:** These token/action controllers change company
membership or resend/revoke privileged invitations.

**Existing coverage / gap:** All four reports are 0%. Main invitation Unit tests
cover list/create, and Feature tests cover outsider resend/revoke attempts, but
not action success, token replay/expiry, queue rollback, or acceptance.

**Tests to add:**

- `Team invitation actions API — preview exposes safe fields for only a valid active token`
  - Cover unknown, expired, accepted, revoked, and active tokens.
  - Assert no internal numeric IDs or invitation token beyond the path contract.
- `Team invitation actions API — accept is single-use and binds the intended account`
  - Cover wrong email/account, expired/revoked token, already accepted replay, and
    successful role creation.
  - Assert role/membership write exactly once and stable conflict/not-found code.
- `Team invitation actions API — resend and revoke enforce company admin TOTP and tenant scope`
  - Cover foreign UUID, non-admin, missing/invalid TOTP, cooldown, queue failure,
    success, and replay.
  - Assert rate-limit reservation rollback on queue failure and no foreign write.
- `Team invitations — invited account can accept once and gains only the offered role`
  - Feature setup creates the invitation as admin through the public API and
    accepts as the configured second account; cleanup uses available public team
    endpoints.
  - Assert membership/role shape and replay outcome. Skip if safe API cleanup is
    unavailable; do not insert membership rows directly.

**Suggested test files:**

- New `tests/Unit/Api/TeamInvitations/TeamInvitationActionsApiTest.php`
- Extend `tests/Unit/Api/TeamInvitations/_support/TeamInvitationsTestDoubles.php`
- New `tests/Feature/Api/Team/TeamInvitationAcceptFlowTest.php`

### `catalog/controller/publicapi/v1/company/representative_requests.php` and `company/representatives.php`

**Recommended test type:** Unit; selective existing Feature extensions

**Why this belongs in this phase:** Representative document uploads, seal
downloads/revocation, and admin/member boundaries are security-sensitive.

**Existing coverage / gap:** 49.66% and 64.66% by line. Existing Unit/Feature
suites cover auth, list/detail, basic create/cancel, eligibility, legal gates,
and several revoke guards. Multipart flatten/store branches, notification
failure, request path parsing, TOTP outcomes, and seal streaming are incomplete.

**Tests to add:**

- `Representative requests API — multipart documents validate count size MIME and upload errors`
  - Cover both supported nested upload shapes, sparse indexes, too many files,
    size errors, unsupported content, and successful normalization.
  - Use temporary files and fake upload model; assert no DB create after any file
    fails and cleanup of earlier staged uploads.
- `Representative requests API — notification failure does not corrupt request state`
  - Cover missing ops configuration, queue exception/failure, and success.
  - Assert the request response/state contract and observable audit/log/queue call,
    not mail text.
- `Company representatives API — TOTP and seal download remain principal scoped`
  - Cover member/admin view, foreign seal UUID, invalid reason, missing/invalid
    TOTP, provider revocation error, unsafe/missing file, and successful stream
    seam.
  - Assert tenant model parameters and no file read for unauthorized callers.

**Suggested test files:** Extend
`tests/Unit/Api/Company/CompanyRepresentativeRequestsApiTest.php`,
`tests/Unit/Api/Company/CompanyRepresentativesApiTest.php`, and their existing
support doubles. Add Feature cases only when they can arrange/restore state via
the current public APIs.

### `catalog/controller/publicapi/v1/platform/retention.php`

**Recommended test type:** Both

**Why this belongs in this phase:** Retention policy controls destructive document
purging for a company and is a local, deterministic admin workflow.

**Existing coverage / gap:** 0 / 59 lines and 0 / 2 methods; no tests.

**Tests to add:**

- `Platform retention API — only the active company admin can read or update policy`
  - Unit-test unauthenticated, personal/non-admin, foreign company, permission,
    method, invalid bounds, and success branches.
  - Assert the exact company ID sent to the model and no write on denial.
- `Platform retention — admin can update and restore the company policy`
  - Feature test reads the original policy, writes a safe alternate value, checks
    response shape, and restores the original in `finally`, all via HTTP.
  - Skip if a dedicated company/admin role is unavailable.

**Suggested test files:**

- New `tests/Unit/Api/Platform/PlatformRetentionTest.php`
- New `tests/Feature/Api/Platform/PlatformRetentionFlowTest.php`

### CSC redirect URI, webhook, and delivery administration

**Source files:**

- `catalog/controller/publicapi/v1/csc/redirect_uris.php`
- `catalog/controller/publicapi/v1/csc/webhooks.php`
- `catalog/controller/publicapi/v1/csc/webhook_deliveries.php`
- `catalog/controller/publicapi/v1/csc/webhook_deliveries/resend.php`

**Recommended test type:** Unit for all; selective Feature for local CRUD only

**Why this belongs in this phase:** All four are 0% and handle admin/TOTP gates,
tenant-scoped endpoints, signing secrets, remote destinations, and delivery
payloads.

**Existing coverage / gap:** Current CSC tests cover access and API-client
provisioning, not these controllers. The old CSC planning document predates the
current CSC tests and must not be used as the existing-coverage inventory.

**Tests to add:**

- `CSC redirect URIs — create and delete require admin TOTP and exact client ownership`
  - Cover unprovisioned client, invalid/unsafe URI, duplicate, foreign UUID,
    missing/invalid TOTP, and success.
  - Assert normalized URI, tenant client ID, and no write on denial.
- `CSC webhooks — CRUD validates destination and event allowlist without leaking the secret`
  - Cover endpoint limit, unsafe/non-HTTPS URL according to current validation,
    invalid events, patch with no fields, foreign UUID, disable/delete, and
    create.
  - Assert a signing secret is returned only on create if contractual, never on
    list/detail/update, and tenant-scoped model calls.
- `CSC webhook deliveries — list/detail separate summary from payload and stay tenant scoped`
  - Cover filters/pagination, invalid status, foreign UUID, date normalization,
    and detail payload.
  - Assert list omits payload/secret and detail returns only the owned delivery.
- `CSC webhook resend — disabled endpoint missing payload and dispatcher failures map safely`
  - Fake the dispatcher for success/failure and assert signing secret/payload are
    passed internally but never returned. Do not make a network call.
- `CSC integration settings — admin can add and remove a redirect URI and webhook`
  - Feature setup uses a configured provisioned test client; create and cleanup
    through public endpoints in `finally`.
  - Assert second company cannot list/use created identifiers. Do not Feature-test
    delivery or resend.

**Suggested test files:**

- New `tests/Unit/Api/Csc/RedirectUrisTest.php`
- New `tests/Unit/Api/Csc/WebhooksTest.php`
- New `tests/Unit/Api/Csc/WebhookDeliveriesTest.php`
- New `tests/Feature/Api/Csc/CscIntegrationSettingsFlowTest.php`

### CSC credential and signing-session control surfaces

**Source files:**

- `catalog/controller/publicapi/v1/csc/signers/credential.php`
- `catalog/controller/publicapi/v1/csc/signers/credential/revoke.php`
- `catalog/controller/publicapi/v1/csc/integration/credentials.php`
- `catalog/controller/publicapi/v1/csc/integration/credentials/revoke.php`
- `catalog/controller/publicapi/v1/csc/integration/signers.php`
- `catalog/controller/publicapi/v1/csc/integration/signing_sessions.php`
- `catalog/controller/publicapi/v1/csc/signing_sessions.php`
- `catalog/controller/publicapi/v1/csc/signing_sessions/send_sms.php`
- `catalog/controller/publicapi/v1/csc/signing_sessions/sign.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** These 0% controllers expose credential
status/revocation and signing state across integrator and company-admin surfaces.
Tenant confusion or replay would be severe.

**Existing coverage / gap:** Existing CSC enrollment credential tests cover
issuance, not signer lookup, admin inventory, revocation, or signing-session
actions.

**Tests to add:**

- `CSC signer credential API — lookup and revoke are integrator and client scoped`
  - Cover auth/metering, missing signer, foreign credential, invalid reason,
    already revoked, provider failure, and success.
  - Assert public response contains no private key/secret and event dispatch occurs
    once on successful revocation.
- `CSC admin credential API — list detail and revoke never cross clients`
  - Cover admin/permission/TOTP gates, status filters, pagination, foreign UUID,
    invalid reason, provider error, and success.
  - Assert `totp_required`/public fields and tenant client IDs.
- `CSC admin signer and signing-session lists validate filters and hide foreign details`
  - Cover boolean/status/date filters, malformed values, foreign UUID, and empty
    pages.
  - Assert stable pagination and no sensitive payload in list rows.
- `CSC signing sessions API — create get cancel send-SMS and sign map service results consistently`
  - Cover integrator auth, metering, missing UUID, hosted-session action rejection,
    SMS-code presence, service conflict/replay/provider error, and success.
  - Fake the service, SMS, and signing boundary; assert no provider call before
    local auth/tenant/session checks.

**Suggested test files:**

- New `tests/Unit/Api/Csc/SignerCredentialTest.php`
- New `tests/Unit/Api/Csc/IntegrationCredentialsTest.php`
- New `tests/Unit/Api/Csc/IntegrationInventoryTest.php`
- New `tests/Unit/Api/Csc/SigningSessionsControllerTest.php`

### `catalog/controller/publicapi/v1/csc/sign.php` and `csc/review.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Anonymous tokens expose one-shot signing and
PDF review. End-to-end Feature testing is excluded, but token, rate, state, and
file gates must be deterministic.

**Existing coverage / gap:** Both reports are 0%; current hosted-enrollment tests
target `csc/enroll.php`, not these signing/review controllers.

**Tests to add:**

- `CSC hosted signing — token and state gates route every action safely`
  - Cover unknown/expired/rate-limited token, load/status/redirect acknowledgement,
    legal terms, email PIN replay/expiry, checkin readiness, and invalid action.
  - Fake service, email, and Checkin; assert stable state/code and no secret/raw
    email exposure.
- `CSC review — only the bound active token can stream the intended PDF`
  - Cover unknown/expired/rate-limited token, unsafe/missing path, foreign session,
    mapping results, and successful stream seam.
  - Assert path containment and no file read on denial.

**Suggested test files:**

- New `tests/Unit/Api/Csc/HostedSigningTest.php`
- New `tests/Unit/Api/Csc/ReviewTest.php`

## Phase 4 — Isolate payment and subscription integrity without Stripe Feature tests

### `catalog/controller/publicapi/v1/billing/checkout.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** The controller is 0% with 25 uncovered
methods. Provider calls are excluded, but tax, address, audience, interval, and
customer-sync decisions can cause incorrect billing.

**Existing coverage / gap:** Existing billing suites intentionally cover local
billing only; none targets checkout.

**Tests to add:**

- `Billing checkout — role and price resolve the permitted audience interval and currency`
  - Cover personal/admin/unsupported roles, recurring interval groups, foreign or
    inactive prices, and currency mismatch.
  - Assert no Stripe fake call before local validation succeeds.
- `Billing checkout — personal and corporate customer parameters normalize address tax and locale`
  - Dataset Romanian/non-Romanian addresses, TIN/VAT/EUID, missing optional
    fields, Unicode truncation, and locale selection.
  - Assert the Stripe parameter structure and that internal identifiers are used
    only as metadata.
- `Billing checkout — tax-ID retry and customer synchronization are idempotent`
  - Fake duplicate/unsupported tax IDs, address drift, no-op update, update
    failure, and tax-location readiness.
  - Assert bounded retry/update calls and stable provider-error mapping.

**Suggested test file:** New
`tests/Unit/Api/Billing/BillingCheckoutTest.php`.

### `catalog/controller/publicapi/v1/billing/subscription_items.php` and `billing/subscriptions.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Adding/cancelling subscription items uses
high-risk confirmation, TOTP thresholds, audit, proration, and cancellation
state. Both reports are 0%.

**Existing coverage / gap:** No current Unit or Feature tests target these
provider-backed controllers.

**Tests to add:**

- `Billing subscription change — local context validates price quantity interval and cancellation state`
  - Cover missing/foreign/non-recurring price, quantity bounds, currency/interval
    mismatch, missing subscription, cancel-pending subscription/item, and valid
    context.
  - Assert zero provider calls on rejection.
- `Billing subscription change — risk preview and TOTP thresholds are deterministic`
  - Cover immediate/daily amount thresholds by currency, boolean parsing,
    confirmation requirement, no TOTP, invalid TOTP, and preview response.
  - Assert application codes and audit fields without exposing TOTP.
- `Billing subscription change — audit records every terminal outcome once`
  - Fake success, payment required, missing payment method, provider API error,
    reload failure, and portal fallback.
  - Assert stable status/error structure, invoice ID when present, and one audit
    call with outcome/failure reason.
- `Billing subscription cancellation — ownership and current state precede provider cancellation`
  - Cover foreign/missing subscription, already cancelled, provider failure, and
    success.
  - Assert provider calls and local sync/audit behavior.

**Suggested test files:**

- New `tests/Unit/Api/Billing/SubscriptionItemsContextAndRiskTest.php`
- New `tests/Unit/Api/Billing/SubscriptionItemsProviderMappingTest.php`
- New `tests/Unit/Api/Billing/SubscriptionsTest.php`

### `catalog/controller/publicapi/v1/stripe/webhook.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Webhook reconciliation is a high-CRAP,
security-sensitive payment state machine. Feature tests are explicitly excluded,
but deterministic event and idempotency tests have high value.

**Existing coverage / gap:** 0 / 628 lines and 0 / 18 methods; no direct tests.

**Tests to add:**

- `Stripe webhook — method signature and payload validation fail before mutation`
  - Cover non-POST, missing SDK/config, invalid payload/signature, unknown event,
    and duplicate event.
  - Assert response status/code and zero billing writes.
- `Stripe webhook — checkout completion is idempotent across payment-pack and subscription modes`
  - Fake repeated events, missing metadata, foreign/missing local references,
    successful payment-pack fulfillment, and handler failure.
  - Assert grants/records are created once and secrets/payment details are not
    logged or returned.
- `Stripe webhook — invoice events synchronize subscriptions items periods and grants once`
  - Cover plan-line extraction across payload shapes, paid/failed invoice,
    missing subscription ID, repeated invoice, and lower-tier cancellation.
  - Assert normalized upsert rows, integer timestamps, transaction boundaries,
    and idempotent grant insertion.

**Suggested test files:**

- New `tests/Unit/Api/Stripe/StripeWebhookGuardsTest.php`
- New `tests/Unit/Api/Stripe/StripeWebhookCheckoutTest.php`
- New `tests/Unit/Api/Stripe/StripeWebhookInvoiceTest.php`

`billing/portal.php` should receive only a small Unit guard/mapping dataset if the
baseline confirms no indirect coverage; do not create a Feature test merely to
move its 0% score.

## Phase 5 — Medium-value transformations and operational contracts

### `catalog/controller/publicapi/v1/lists.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** The 0%-covered controller contains 14 methods
for report/list transformations. It is branch-heavy but lower risk than signing
or privacy paths.

**Existing coverage / gap:** 0 / 362 lines; no direct tests.

**Tests to add:**

- `Lists API — filter sort pagination and language input normalize deterministically`
  - Cover allowed/unknown filters, missing datalabels, language fallback, and
    report parameters.
  - Assert normalized model arguments and stable rejection codes.
- `Lists API — table and transpose output preserve field/value alignment`
  - Cover empty rows, missing fields, transpose, buttons, styles, and mixed data
    types.
  - Assert keys/order only where contractually meaningful, not presentation text.

**Suggested test file:** New
`tests/Unit/Api/Lists/ListsControllerTest.php`.

### `catalog/controller/publicapi/v1/csc/dev_pack.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Existing tests cover auth, manifest, and
unknown item, but zip/file/personalization methods remain at 0% and could expose
secrets or arbitrary files.

**Existing coverage / gap:** 51 / 163 lines (31.29%), 1 / 7 complete methods.

**Tests to add:**

- `CSC dev pack — file and zip download allow only manifest resources`
  - Use temporary fixtures for missing/unreadable/empty files and traversal-like
    names.
  - Assert no out-of-manifest read and zip entries use safe relative names.
- `CSC dev pack — personalized environment contains only the current public client configuration`
  - Arrange two clients and redirect URIs.
  - Assert only the active client's public IDs/URLs are present and client secret,
    signing secrets, filesystem paths, and foreign IDs are absent.

**Suggested test file:** Extend `tests/Unit/Api/Csc/DevPackTest.php`.

### Referrals invite/remind controllers

**Source files:**

- `catalog/controller/publicapi/v1/referrals/invite.php`
- `catalog/controller/publicapi/v1/referrals/invite/send.php`
- `catalog/controller/publicapi/v1/referrals/remind.php`
- `catalog/controller/publicapi/v1/referrals/validate.php`

**Recommended test type:** Unit

**Why this belongs in this phase:** Existing Feature flows already cover the
meaningful HTTP success paths. The remaining value is deterministic queue,
cooldown, and normalization behavior, not more Feature permutations.

**Existing coverage / gap:** 17.91% to 70% by line; current Unit tests emphasize
invalid email, cooldown, missing target, and basic valid/invalid codes.

**Tests to add:**

- `Referrals invite — send success and queue failure preserve rate-limit semantics`
  - Cover normalized display name, base URL fallback, queue failure, success, and
    duplicate/cooldown reservation.
  - Assert reservation commit/release and stable response fields.
- `Referrals remind — owned invite and eligible state are required before queueing`
  - Cover foreign/already-converted/missing invite, queue failure, and success.
  - Assert no foreign queue call and updated reminder state once.

**Suggested test files:** Extend
`tests/Unit/Api/Referrals/ReferralsInviteSendApiTest.php` and
`tests/Unit/Api/Referrals/ReferralsRemindApiTest.php`.

### `catalog/controller/publicapi/v1/openapi.php`

**Final decision:** Excluded from this testing plan. The temporary anonymous
Feature contract test was removed at the user's request. The controller remains
0 / 19 in CLI PCOV and does not contribute to the final test count or coverage
comparison.

## Additional tests identified during code review

These are not justified by a single red line in the HTML report; they protect
cross-controller invariants found while reading the source.

1. **Feature/coverage attribution check.** Add a CI/reporting check that records
   which Unit and Feature files ran, passed, skipped, and contributed coverage.
   This prevents a 0% controller from being mistaken for “no tests” again.
2. **No partial pack mutation before common preflight.** A grouped signing request
   with insufficient billing/TOTP/certificate prerequisites must not sign the
   first member before rejecting the pack.
3. **Sign-code and return-URL non-disclosure.** Owner, signer, guest, and
   integrator response builders must expose sign codes/redirect URLs only to the
   principal defined by each contract, including grouped documents.
4. **Delete/retention consistency.** After owner/signer soft delete, explicit
   purge, retention purge, and completed guest-link expiry, all relevant detail,
   file, and audit-certificate endpoints must agree on access and 410/404/403
   semantics.
5. **TOTP audit invariant.** Every privileged action that verifies TOTP should
   emit exactly one success/failure audit and must never include the code/secret
   in audit metadata.
6. **Webhook secret invariant.** CSC signing secrets and Stripe webhook secrets
   may enter verifier/dispatcher fakes but must never appear in list/detail/error
   responses or logs.
7. **Account response denylist.** Both `/account` and `/users` current-account
   aliases must remove the same internal fields as new profile/export/deletion
   fields are added.
8. **Feature cleanup invariant.** Any new mutable Feature workflow must prove its
   cleanup uses a public endpoint in `finally`; if no safe cleanup route exists,
   use a dedicated pre-seeded fixture or do not add the Feature test.

## Areas intentionally left uncovered

- **Account, Identification, Stripe, hosted CSC, renderer, and provider success
  Feature paths:** excluded above. Their local decisions receive Unit coverage;
  the real provider ceremony belongs in a separately provisioned smoke/contract
  environment.
- **Already strong local billing controllers:** `billing/catalog.php`,
  `company_overview.php`, `entitlement_grants.php`, `entitlements.php`,
  `ledger_role_consumption.php`, `ledger_role_grants.php`, `me.php`, and most of
  `seats.php` already have broad Unit/Feature intent. Remaining one-line fallbacks
  and method-completion artifacts do not justify new tests by themselves.
- **Templates and library residual lines:** current Unit and Feature suites cover
  CRUD, versions, publish/archive, parties, smartfields, permissions, cloning,
  and library-to-template workflows. Add tests only for a concrete regression,
  not to move 65%/70% to an arbitrary target.
- **Existing CSC areas missing from this HTML attribution:** do not duplicate the
  current API-client, access, enrollment, hosted-enrollment, photo, credential
  issuance, and Checkin-catalog tests until the baseline gate explains the report
  mismatch.
- **Simple reference forwarding:** `counties.php`, `languages.php`,
  `localities.php`, and most of `legal_documents.php` are small model/static-data
  forwarders. A shared contract test is acceptable if a production regression is
  observed; individual exhaustive controller suites are not recommended.
- **Legacy commerce methods under `users.php`:** address, voucher, download,
  recurring, and transaction methods should be tested only after the API
  inventory/product owner confirms they remain supported. Coverage work should
  not silently legitimize obsolete routes.
- **Pure forwarding/defensive paths:** constructors, `sendResponse()` fallbacks,
  `headers_sent`, impossible upload error combinations, debug trace/file-log
  failures, and exception catches that require corrupting PHP/runtime state may
  remain uncovered when the meaningful caller behavior is tested.
- **Streaming implementation details:** test authorization, selected file,
  containment, lifecycle, content signature, and status transition. Do not force
  coverage of every `header()`, `readfile()`, and `exit()` line.
- **Human-readable email/response prose:** no exact-string assertions unless the
  text becomes an explicit API contract. Test application codes, envelope shape,
  queue parameters, and state instead.

## Expected outcome

After the baseline gate and prioritized phases:

- document owners, authenticated signers, guest signers, company admins, and
  integrators will have explicit non-crossing access tests;
- document group, cancellation, reminder, deletion, purge, completed-access, and
  evidence behavior will be protected by meaningful state-transition tests;
- account deletion, exports, sessions, tokens, certificates, TOTP, and saved
  signatures will have deterministic privacy/security Unit coverage without new
  Account or Identification Feature flows;
- CSC configuration, credential, signing-session, and delivery surfaces will be
  tenant-scoped and tested without real external calls;
- billing checkout/subscription/webhook business decisions will be covered while
  Stripe remains outside Feature tests;
- Feature coverage will remain small and workflow-oriented, with all state
  arranged through public endpoints and documented in reader-friendly Pest
  scenarios;
- intentionally uncovered external, trivial, defensive, and already-sufficient
  areas will remain explicit rather than being filled with low-value tests.

Success is increased confidence in business invariants and access control, plus a
coverage report whose attribution is understood. It is not an arbitrary global
coverage percentage.
