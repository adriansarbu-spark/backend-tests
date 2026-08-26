# Coverage testing baseline — 2026-08-12

This note records the baseline-integrity gate and the first Phase 1 implementation
slice from `tests/strategies/coverage-testing-strategy.md`.

## Reproducible commands

Repository root: `/var/www/api01.dev.simplifi.ro`

Current test configuration: `SKIP_INTEGRATION_TESTS=false`.

Unit baseline:

```bash
PHPUNIT_RESULT_CACHE=/dev/null php vendor/bin/pest \
  --testsuite=Unit \
  --colors=never \
  --do-not-cache-result \
  --log-junit /tmp/coverage-baseline-unit-junit.xml
```

Feature baseline (the proxy variables must be removed for the dev API and auth
hosts, and the process needs network/DNS access outside the agent sandbox):

```bash
env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY \
  -u all_proxy -u ALL_PROXY \
  PHPUNIT_RESULT_CACHE=/dev/null \
  php vendor/bin/pest \
  --testsuite=Feature \
  --colors=never \
  --do-not-cache-result \
  --display-skipped \
  --log-junit /tmp/coverage-baseline-feature-junit.xml
```

Controller-only Unit coverage:

```bash
PHPUNIT_RESULT_CACHE=/dev/null php \
  -d pcov.enabled=1 \
  -d pcov.directory=/var/www/api01.dev.simplifi.ro/public/catalog/controller/publicapi/v1 \
  vendor/bin/pest \
  --testsuite=Unit \
  --colors=never \
  --do-not-cache-result \
  --no-progress \
  --coverage-html tests/coverage_report/publicapi-v1-current \
  --coverage-clover /tmp/coverage-after-documents-clover.xml \
  --coverage-filter public/catalog/controller/publicapi/v1 \
  --log-junit /tmp/coverage-after-documents-coverage-junit.xml
```

Coverage driver: PCOV 1.0.12, enabled explicitly for the coverage command. Pest
3.8.6 / PHP 8.2.29 were used.

## Results

| Run | Outcome |
|---|---|
| Unit before Phase 1 edits | 820 passed, 2,595 assertions; 3 warnings and 7 deprecations |
| Feature | 145 passed, 6,145 assertions; 2 intentionally skipped CRL scenarios |
| Documents focused suite | 8 passed, 44 assertions |
| Full Unit after Documents slice | 828 passed, 2,639 assertions; the same 3 warnings and 7 deprecations |

The Unit warnings are pre-existing read-only upload-directory warnings in CSC
access-evidence tests. The deprecations are pre-existing `client_ip.php` warnings
reached by signing failure-path tests. Pest also attempts to write its own result
cache below the read-only `vendor/` directory even with PHPUnit result caching
disabled; that warning does not change the exit status.

The successful controller report was generated at **2026-08-12 10:40:34 UTC**
and contains all 102 controller files.

| Scope | Lines/statements | Methods |
|---|---:|---:|
| Fresh pre-implementation Unit report | 4,067 / 21,866 | 46 / 644 |
| After first Documents slice | 4,279 / 21,807 | 48 / 644 |
| `documents.php` after first slice | 212 / 2,337 | 2 / 42 |

The `documents.php` executable-line denominator changed when the controller went
from merely included in the source filter to actually loaded by tests. Therefore
the report should be compared by newly attributed methods and branches, not by
subtracting the two headline percentages.

## Attribution finding

The CSC mismatch is explained. These test helpers copy production PHP into a
temporary file, remove selected unconditional dependency `require_once` lines,
execute the temporary copy, and delete it:

- `tests/Unit/Api/Csc/_support/CscApiTestDoubles.php`
- `tests/Unit/Api/Csc/_support/CscIntegratorTestDoubles.php`

PCOV correctly records execution against the temporary `/tmp/...` filename.
The report filter includes only the original controller directory, so the
following tested controllers remain at 0% in the source report and must be
treated as **not attributable**, not untested:

- `csc/api_client.php`
- `csc/enrollments.php`
- `csc/enrollment_photos.php`
- `csc/enrollments/credentials.php`
- `csc/enroll.php`

`csc/dev_pack.php` and `csc/access.php` are loaded from their original paths and
are attributed normally. A later reporting improvement may merge explicitly
mapped temporary-copy coverage, but it must not pretend the source file itself
executed or duplicate the existing CSC tests.

Feature workflows run in the web-server PHP process. CLI PCOV cannot observe
that process, so Feature coverage is **not attributable** to this HTML report.
The passing Feature JUnit result is the authoritative workflow matrix for this
baseline.

## Phase 1 item status after this slice

| Proposed item | Status | Evidence / remaining work |
|---|---|---|
| Documents UUID tenant/integrator disclosure | still missing | Owner, foreign role/CC, non-originating integrator, and originating integrator branches are now covered; add broader personal/company/group fixtures. |
| Documents group locks | still missing | Signer, annotation, and PUT/detach entry paths now assert `document_group_locked` and zero writes; add create/join and send behavior. |
| Documents CC normalization/access | covered | String/array normalization, case folding, dedupe, invalid entries, count, storage length, response splitting, and no-access behavior are covered. |
| Documents reminder reservation | still missing | No controller Unit coverage yet. |
| Documents delete/purge invariants | still missing | No controller Unit coverage yet. |
| Cancelling prevents signer action | covered | Existing Feature signing workflow passed (`after sender withdraws, the invitee cannot sign`). |
| Completion audit evidence | still missing | No owner/signer certificate workflow yet. |
| Authenticated signing envelope/pack/preflight/results/claim/delete | still missing | Existing single-document tests passed, but the proposed pack and claim branches remain. |
| Cross-pack Feature identity boundary | still missing | No grouped Feature scenario yet. |
| Guest signing access/commit/file/status | still missing | No direct suite yet. |
| Sign-hash guards/ownership/provider mapping | still missing | No direct suite yet. |
| Public verification controller | still missing | No direct suite yet. |
| Author document list/delete/preview/structured late failures | still missing | Existing routing/create/save/campaign/upload/send-guard tests passed, but the proposed residual branches remain. |

No production controller or model was changed. No migration was created or
applied.

## Phase 2–3 start slice — 2026-08-12 10:57 UTC

The first Phase 2 and Phase 3 Unit slices add the following tests without
changing production controllers or models:

- `tests/Unit/Api/Account/AccountDeletionTest.php`
  - unauthenticated/already-deleted handling;
  - personal-context and last-admin eligibility gates;
  - missing enrollment/secret/code and invalid-TOTP behavior;
  - failure auditing without codes or secrets and no closure callback on denial.
- `tests/Unit/Api/Account/CurrentAccountResponseTest.php`
  - missing/deleted account hiding;
  - internal-field, credential, raw-decline, and storage-path filtering;
  - safe duplicate-account, avatar, export, certificate, language, and deletion
    eligibility output.
- `tests/Unit/Api/Platform/PlatformRetentionTest.php`
  - authentication, company context, and company-admin gates;
  - method and retention-bound validation with zero denied writes;
  - exact active-company/customer scoping and one sanitized security event;
  - numeric policy updates and policy removal.

Focused result: **27 passed, 187 assertions**. Full Unit result: **855 passed,
2,826 assertions**, with the same pre-existing 3 warnings and 7 deprecations.

Controller-only PCOV was regenerated to a temporary report at **2026-08-12
10:56 UTC** using the command documented above, with the HTML destination
changed to `/tmp/coverage-phase2-3`. The report now records:

| Scope | Lines/statements | Methods |
|---|---:|---:|
| All 102 controllers | 4,401 / 21,807 | 50 / 644 |
| `account.php` | 64 / 102 | 0 / 2 complete |
| `platform/retention.php` | 58 / 59 | 2 / 2 complete |

Compared with the post-Documents baseline, these slices attribute 122 new
controller statements and 2 complete methods. `account.php` intentionally
remains below method-complete because provider-backed closure success/failure and
the full declined-verification catalog branch remain for a later Phase 2 slice.

### Phase 3 invitation contract gaps found before test implementation

The invitation action cases cannot all be encoded as green tests against the
current production contract without first resolving these mismatches:

1. `acceptInvitationByToken()` records the authenticated email but never compares
   it with `invited_email`; any authenticated account holding the token can accept
   the offered role. The strategy requires the token to bind the intended account.
2. The revoke controller checks company/admin/route scope but performs no TOTP
   verification. The strategy requires resend and revoke to enforce admin TOTP.
3. Resend verifies TOTP only for invitations whose offered role is `admin`, and
   skips proof when the caller has no enrolled secret. This is narrower than the
   strategy's stated privileged-action invariant.

No permissive tests were added for these gaps because they would legitimize the
opposite of the strategy's security contract. They remain **still missing** until
product behavior is clarified or corrected.

## Phase 4–5 interim completion — 2026-08-12 11:33 UTC

The remaining strategy phases were implemented without Stripe/provider network
calls and without modifying Public API controllers or models.

Phase 4 added deterministic Unit coverage for:

- checkout audience/currency/interval guards, personal/corporate customer
  normalization, bounded tax-ID retry, and checkout-session construction;
- subscription-item context, cancellation state, confirmation, amount/TOTP risk,
  TOTP-secret handling, success/payment-required/API-error mapping, terminal
  audit behavior, and local line synchronization;
- subscription cancellation ownership, provider failure, and mirror sync;
- billing portal ownership, payment-method flow parameters, and missing-customer
  handling;
- Stripe webhook method/payload/signature guards, sanitized handler errors,
  payment-pack idempotency keys, invoice subscription/line normalization,
  repeat-safe grant keys, and lower-tier/month-to-year cancellation selection.

Phase 5 added deterministic Unit coverage for:

- list filter flattening, row/header alignment, transpose columns, button sorting,
  localization, and conditional styles;
- CSC developer-pack manifest containment and personalized environment
  non-disclosure;
- referral invite/reminder queue success and failure recording, display-name/email
  normalization, owned pending state, and cooldown behavior.

An anonymous OpenAPI Feature contract was included in this interim run. It was
subsequently removed from the suite at the user's request; it is not part of the
final verification below.

### Interim verification (superseded)

| Run | Outcome |
|---|---|
| Full Unit + controller PCOV | 902 passed, 3,164 assertions; same 3 warnings and 7 deprecations |
| Full Feature | 146 passed, 6,186 assertions; 2 intentionally skipped CRL scenarios |
| Focused OpenAPI Feature | 1 passed, 11 assertions |

The final controller report was generated at **2026-08-12 11:32:50 UTC** with
PCOV 1.0.12 and the controller-only command documented above. Its artifacts are:

- HTML: `tests/coverage_report/publicapi-v1-current/index.html`
- Clover: `/tmp/coverage-publicapi-final-clover.xml`
- Unit JUnit: `/tmp/coverage-publicapi-final-junit.xml`
- Feature JUnit: `/tmp/coverage-final-feature-junit.xml`

### Interim baseline comparison

| Scope | Strategy snapshot | Phase 4–5 interim | Change |
|---|---:|---:|---:|
| Lines/statements | 4,115 / 21,866 (18.82%) | 5,798 / 21,776 (26.63%) | +1,683 covered, +7.81 pp |
| Complete methods | 46 / 644 (7.14%) | 71 / 644 (11.02%) | +25, +3.88 pp |
| Complete classes | 6 / 102 (5.88%) | 7 / 102 (6.86%) | +1, +0.98 pp |

Against the last recorded post-Phase-3 slice (4,401 / 21,807 lines and 50 / 644
methods), the final report adds **1,397 covered statements** and **21 complete
methods**. The executable-line denominator changed as previously unloaded
controllers became attributed, so covered counts and completed methods remain the
more reliable comparison.

| Phase 4–5 controller | Snapshot lines / methods | Final lines / methods |
|---|---:|---:|
| `billing/checkout.php` | 0 / 642; 0 / 25 | 356 / 635 (56.06%); 4 / 25 |
| `billing/subscription_items.php` | 0 / 430; 0 / 17 | 251 / 430 (58.37%); 5 / 17 |
| `billing/subscriptions.php` | 0 / 77; 0 / 2 | 50 / 77 (64.94%); 0 / 2 |
| `billing/portal.php` | 0 / 78; 0 / 1 | 47 / 78 (60.26%); 0 / 1 |
| `stripe/webhook.php` | 0 / 628; 0 / 18 | 368 / 611 (60.23%); 2 / 18 |
| `lists.php` | 0 / 362; 0 / 14 | 125 / 355 (35.21%); 7 / 14 |
| `csc/dev_pack.php` | 51 / 163; 1 / 7 | 79 / 163 (48.47%); 2 / 7 |
| `referrals/invite/send.php` | 24 / 134; 0 / 3 | 113 / 134 (84.33%); 1 / 3 |
| `referrals/remind.php` | 41 / 175; 0 / 3 | 124 / 175 (70.86%); 1 / 3 |
| `openapi.php` | 0 / 19; 0 / 1 | CLI PCOV remained 0 / 19; the temporary Feature test was later removed |

The temporary OpenAPI Feature result is historical and is not counted in the
final suite.

No migration was created or applied for Phases 4–5.

## Phase 1 residual completion and final verification — 2026-08-12 12:09 UTC

The remaining deterministic Phase 1 controller cases were implemented after
Phases 2–5. The OpenAPI Feature test was removed. This follow-up added coverage
for document reminders/deletion/evidence, authenticated and guest signing packs,
claims, signer deletion/evidence, hash signing, public verification, and author
document list/delete/preview/structured transformations.

### Phase 1 status after completion

| Proposed item | Final status | Evidence / future direction |
|---|---|---|
| Documents UUID tenant/integrator disclosure | covered | Personal/company access, foreign role/CC denial, group filtering, and originating/non-originating integrator secrets have controller tests. |
| Documents group locks and group send guards | covered | Draft mutation entry paths assert zero writes; multi-member single-send and grouped response filtering are covered. |
| Documents CC normalization/access | covered | Normalization, bounds, response splitting, and absence of access grant are covered. |
| Documents reminder reservation | covered | Lifecycle/cooldown guards plus queue release/commit behavior are covered. |
| Documents delete/purge invariants | covered | Owner/signer, lifecycle, stakeholder visibility, auto/explicit purge, and failure behavior are covered. |
| Cancelling prevents signer action | covered | Existing live Feature workflow passed. |
| Completion audit evidence | covered at controller boundary | Owner/signer access, lifecycle, visibility, and storage guards are deterministic Unit tests; retain a provisioned `%PDF` smoke workflow as future work. |
| Authenticated signing envelope/pack/preflight/results/claim/delete | covered | Same-principal filtering, payload/preflight, mixed result, claim, delete, and evidence cases are covered. |
| Cross-pack live HTTP identity boundary | future smoke | The controller boundary is covered deterministically; add the smallest public-API grouped flow when environment entitlement is provisioned. |
| Guest signing access/commit/file/status | covered at controller boundary | Guest/sponsored access, lifecycle/expiry, payload merge/validation, pre-provider commit, and safe decline reasons are covered; retain a read-only HTTP smoke workflow. |
| Sign-hash guards/ownership/provider mapping | covered | Routing/digest, scoped/current certificate, caller sanitization, and SAM error mapping are covered. |
| Public verification controller | covered | Non-disclosure, evidence normalization/digests, unsafe paths, and missing files are covered. |
| Author document list/delete/preview/structured late failures | covered at deterministic boundary | Scope, normalization, preview, and Chromium late failure are covered; adapter-backed upload/billing/signer/persistence rollback variants remain a useful extension. |

### Final results

| Run | Outcome |
|---|---|
| Full Unit + controller PCOV | 967 passed, 3 skipped, 3,484 assertions; same 3 warnings and 7 deprecations |
| Full Feature | 145 passed, 6,205 assertions; 2 intentionally skipped CRL scenarios |

The controller report was generated at **2026-08-12 12:09:01 UTC** with PCOV
1.0.12. Its artifacts are:

- HTML: `tests/coverage_report/publicapi-v1-current/index.html`
- Clover: `/tmp/coverage-publicapi-phase1-completion-clover.xml`
- Unit JUnit: `/tmp/coverage-publicapi-phase1-completion-junit.xml`
- Feature JUnit: `/tmp/coverage-phase1-completion-feature-junit.xml`

### Final baseline comparison

| Scope | Strategy snapshot | Final | Change |
|---|---:|---:|---:|
| Lines/statements | 4,115 / 21,866 (18.82%) | 7,397 / 21,762 (33.99%) | +3,282 covered, +15.17 pp |
| Complete methods | 46 / 644 (7.14%) | 86 / 644 (13.35%) | +40, +6.21 pp |
| Complete classes | 6 / 102 (5.88%) | 7 / 102 (6.86%) | +1, +0.98 pp |
| Passing Unit cases (CLI) | 820 | 967 | +147 |
| Passing Feature cases (CLI) | 145 | 145 | no net change after OpenAPI removal |

Compared with the immediate pre-follow-up report, this Phase 1 completion added
**65 Unit cases**, **1,599 covered statements**, and **15 complete methods**.
Feature cases decreased from 146 to 145 solely because the OpenAPI test was
removed.

No production controller/model change or migration was required. No migration
was applied.
