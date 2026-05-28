### Testing Guidelines (Project-Wide)

This file contains general steps to write tests in this repository. It focuses on the conventions already used across `tests/Feature` and `tests/Unit`, and on helper reuse (e.g. `*FlowHelper`, `*ApiHelper`) to keep tests readable and consistent.

---

## Documents: reader-friendly descriptions (recommended)

Use **`tests/Feature/Api/Documents/DocumentsFlowTest.php`** as the canonical example when you want **Prerequisites + numbered Steps** that read well for **non-developers** (QA, product, operations) while still being accurate for engineers.

### What “reader-friendly” means here

1. **Say what a person would notice first** - e.g. “the owner can download their PDF”, “someone else cannot open your document” - instead of leading with URL paths, internal helper names, or low-level HTTP jargon.
2. **Keep the technical facts, but second** - when a step must mention HTTP status or a response field, put it in parentheses or at the end of the sentence (e.g. “access is refused (**HTTP 403** - forbidden)”, “the bytes look like a real PDF (**starts with `%PDF`**)”) so the story stays clear.
3. **Prerequisites stay short and human** - describe *who* and *what must be true* (“integration tests are enabled”, “two different test accounts can sign in”, “a document already exists for the main user”) and only point to `tests_config.php` / flags when someone needs to reproduce or skip the scenario.
4. **Steps are always numbered** - use `1.`, `2.`, `3.`, and add `4.`, `5.`, … whenever the scenario needs more beats. Each step should match one block of behavior in the test body so the docblock stays honest when code changes.
5. **Test titles use the same voice** - prefer a **product area prefix** and an em dash, e.g. `Documents - owner can download their PDF`, instead of router-style names like `documents flow: … /file`. Titles still appear in `pest --list-tests` and the admin Test Runner, so they should read like a short checklist item.

### Shared helpers

If a file uses a lazy singleton or “run once” setup (e.g. `getDocumentsFlowManager()`), document it with **numbered steps** too: what runs the first time, what is reused on later calls, and why that saves repetition - still in plain language, with technical names only where they disambiguate.

### Example in the repo

- **`tests/Feature/Api/Documents/DocumentsFlowTest.php`** - end-to-end documents scenarios with the style above on every `test(` and on skip branches.
- **`tests/Feature/Api/Signing/SigningFlowTest.php`** - same **Prerequisites + Steps** shape with a slightly more technical tone; use it when the audience is comfortable with signing-specific vocabulary.

---

## 1. Start by classifying the test

1. If you want to verify controller logic without relying on DB state, external services, or filesystem uploads, prefer a **Unit** test.
2. If you want to verify real HTTP endpoints end-to-end with the real database and filesystem behavior, prefer a **Feature/Integration** test.
3. Avoid mixing both styles in the same test.

---

## 2. Pick the endpoint(s) and map them to the controller method

1. Identify the route(s) you’re testing (HTTP method + path).
2. Find the matching controller and the internal method it calls.
3. Write tests that assert behavior at the boundary (HTTP status + response shape) even when the internal method is complex.

---

## 3. Use helpers wherever possible

1. Reuse existing helpers for auth and API calls:
   - `ApiAuthHelper::bearerTokenFor(...)`
   - `ApiAuthHelper::apiRequest(...)`
2. Reuse flow helpers for multi-step signing/document flows:
   - `SigningFlowHelper`
   - `DocumentsFlowManager`
   - `DocumentsApiHelper`
3. For document-signing behaviors, prefer helpers like:
   - `SigningFlowHelper::createDocument(...)`
   - `SigningFlowHelper::sendDocument(...)`
   - `SigningFlowHelper::signWithRetry(...)`

Rule of thumb: tests should describe _what_ the system must do, while helpers handle _how_ to do the required setup.

---

## 4. Centralize repeated HTTP payloads in helpers

If you see the same multipart payload, annotation/signers JSON structure, or the same polling/wait loops repeating across tests:

1. Create a new method in an existing helper (`SigningFlowHelper`, `DocumentsApiHelper`, etc.) OR
2. Add a new dedicated helper file under `tests/Support/`.

Keep the test file focused on assertions and flow:

- arrange (helpers)
- act (HTTP or helper “action”)
- assert (status and key response fields)

---

## 5. Handle environment instability explicitly

This project contains integration tests that depend on external configuration and filesystem upload directory behavior.

1. Ensure integration tests either:
   - skip when required config/fixtures are missing, or
   - gracefully skip when the environment fails in a known/expected way.
2. Prefer “guardrail” methods already present in helpers:
   - `SigningFlowHelper::assertRequiredConfigOrSkip()`
   - `DocumentsFlowManager::forConfiguredTestUsers()`
3. When upload folder issues occur, skip the test rather than failing it with a misleading assertion.

---

## 6. Write security checks as first-class test cases

When testing permissions/authorization:

1. Create the allowed resource in the expected owner context.
2. Attempt the same operation with a non-allowed user.
3. Assert the operation fails with an appropriate status code (or a “hide existence” 404, depending on controller design).
4. Assert the error message is present when JSON includes an `error` field.

Examples in this repo:

- owner vs non-owner file access
- signer endpoints vs UUID endpoints
- “not allowed to sign/reject” with wrong signer identity

---

## 7. Assert response shape, not just status code

For successful responses:

1. Assert expected keys exist under `data` (e.g. `data.uuid`, `data.sign_code`).
2. Assert any expected equality fields (e.g. sign_code matches what you used).

For unsuccessful responses:

1. Assert response status is not the success status.
2. If response JSON includes `error`, assert `error` is non-empty to avoid false positives.

---

## 8. Make tests deterministic where possible

1. Use `gmdate('YmdHis')` and/or random suffixes for unique document names.
2. Use helper polling (`waitFor...`) when the backend has eventual consistency.
3. For retries, prefer helper-provided retry wrappers (e.g. `signWithRetry`).

---

## 9. Keep tests readable

1. Avoid large inline JSON/multipart blocks in the feature test body.
2. Keep test bodies short by moving setup logic into helpers.
3. When debugging is needed, include a compact `$debug` string (status + JSON + first N bytes of raw response).

---

## 10. Add/extend helpers only when you have evidence of repetition

Use this decision rule:

1. If you’re about to copy/paste the same payload or setup across 2+ tests, refactor.
2. If it’s only one test, keep it inline unless it’s already inconsistent with existing helpers.

---

## 11. Do NOT update models or controllers outside of the tests

---

## 12. Scenario documentation on every new Pest test (required)

For **every** new `test('…', function () {` in Pest files under `tests/` (especially `tests/Feature`), add a **PHPDoc block immediately above** that `test(` call. Only whitespace may appear between the closing `*/` and `test(` - otherwise the admin Test Runner will not attach the text to the right test.

### Why

- The admin **Test Runner → Available tests** view parses these docblocks live (same naming as `pest --list-tests` via `Pest\Support\Str::evaluable`).
- Reviewers and QA can open **View description** without reading the whole closure.

### Format (copy this shape)

Use **Prerequisites** and **Steps** so expectations and setup are obvious:

```php
/**
 * Prerequisites:
 * - Integration tests enabled (or skip branch documented) and any helper guards satisfied (e.g. `beforeAll` / `assertRequiredConfigOrSkip`).
 * - Accounts or env vars this scenario depends on (e.g. `TEST_USER_1_*`, bearer + TOTP).
 *
 * Steps:
 * 1. …
 * 2. …
 * 3. Assert … (status codes, `data.*` shape, non-empty `error` when failure is expected).
 */
test('Documents - short behavior in everyday language', function () {
    // …
});
```

For **documents** flows specifically, prefer titles like `Documents - …` (see the “Documents: reader-friendly descriptions” section above).

### Rules

1. **One docblock per test** - the block must be the **last** `/** … */` before that `test(` (if you stack comments, only the one directly above `test(` is used).
2. **Keep Steps aligned with the code** - update the doc when the test changes.
3. **Security / negative tests** - state what must *not* happen (e.g. wrong user, wrong `sign_code`, non-200) in Steps.
4. **Skipped suites** - the `if (SKIP_…) { test('Skipping …'); return; }` branch should still have a short docblock explaining when it runs.
5. **PHPUnit class tests** - this automated extraction applies to **Pest** `test()` calls; for classic PHPUnit methods, keep a method docblock for humans even though the admin UI does not parse those today.

### Example in the repo

See **`tests/Feature/Api/Documents/DocumentsFlowTest.php`** for reader-first wording on titles and Steps, and **`tests/Feature/Api/Signing/SigningFlowTest.php`** for full-file examples of Prerequisites + Steps on each scenario with a more technical tone where appropriate.
