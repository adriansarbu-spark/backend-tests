# Billing Public API: two-phase test plan

This plan covers the non-Stripe behavior in
`catalog/controller/publicapi/v1/billing/`. It proposes tests only; it does not
change controllers, models, or database data.

The two phases are:

1. **Unit/controller tests**: fast, deterministic Pest tests with controller,
   customer, permission, request, model, database, and clock test doubles.
2. **Feature/integration tests**: real HTTP routing, authentication, active-role
   selection, permissions, and database models.

The plan follows `tests/strategies/testing-guidelines.md`: Unit and Feature
concerns remain separate, Feature tests reuse `ApiAuthHelper`, repeated setup
belongs in helpers, security is first-class, responses are checked beyond their
HTTP status, unstable environments skip with a useful reason, and every Pest
`test()` receives an immediately preceding PHPDoc block with
**Prerequisites** and numbered **Steps**.

## 1. Scope

### Included routes

| Method | Path | Controller | Behavior covered |
|---|---|---|---|
| GET | `/publicapi/v1/billing/catalog` | `catalog.php` | Authentication, role audience, billing currency, catalog model delegation, and response mapping. |
| GET | `/publicapi/v1/billing/company_overview` | `company_overview.php` | Authentication, company/admin authorization, method handling, model delegation, and response envelope. |
| GET | `/publicapi/v1/billing/entitlement_grants` | `entitlement_grants.php` | Admin-only filtering, pagination, search, summary, row mapping, and tenant isolation. |
| POST | `/publicapi/v1/billing/entitlement_grants/assign` | `entitlement_grants.php` | Admin-only local top-up grant assignment, validation, conflict handling, and tenant isolation. |
| GET | `/publicapi/v1/billing/entitlements` | `entitlements.php` | Role limits, usage, optional period, company pool authorization, cross-role spendability, and response mapping. |
| GET | `/publicapi/v1/billing/ledger_role_consumption` | `ledger_role_consumption.php` | Role-scoped consumption ledger filters, dates, sorts, pagination, and response mapping. |
| GET | `/publicapi/v1/billing/ledger_role_grants` | `ledger_role_grants.php` | Role-scoped grant ledger filters, activity state, sorts, pagination, and response mapping. |
| GET | `/publicapi/v1/billing/me` | `me.php` | Effective role entitlements, authentication rights, local assigned-plan snapshot, and cross-role spendability. |
| GET | `/publicapi/v1/billing/seats` | `seats.php` | Admin-only local seat listing, filters, summary, allowance inclusion, and tenant isolation. |
| POST | `/publicapi/v1/billing/seats/assign` | `seats.php` | Admin-only local seat assignment, role/slot validation, capacity, and duplicate protection. |
| POST | `/publicapi/v1/billing/seats/release` | `seats.php` | Admin-only local seat release and response contract. |

### Explicitly excluded Stripe-related routes and behavior

No tests are proposed for:

- `POST /publicapi/v1/billing/checkout`
- `POST /publicapi/v1/billing/portal`
- `POST /publicapi/v1/billing/seats/cancel`
- `POST /publicapi/v1/billing/subscription_items/preview`
- `POST /publicapi/v1/billing/subscription_items`
- `POST /publicapi/v1/billing/subscription_items/cancel`
- `POST /publicapi/v1/billing/subscriptions/cancel`
- Stripe SDK or configuration guards
- Checkout or Billing Portal sessions
- Stripe Customer creation, synchronization, addresses, tax IDs, or locale
- Payments, payment methods, invoices, or proration
- Remote subscription quantity changes, schedules, or cancellation
- Live Stripe test-mode setup, calls, fixtures, polling, or cleanup

Webhook ingestion and remote billing reconciliation are also outside this plan.

### Existing coverage

At the time this plan was written, there were no billing-specific Unit or
Feature files under `tests/Unit/Api/Billing` or
`tests/Feature/Api/Billing`. A few CSC, referrals, and signing tests stub
billing models incidentally, but they do not cover these controllers.

## 2. Shared test architecture

### Proposed Unit support

Suggested file:
`tests/Unit/Api/Billing/_support/BillingTestDoubles.php`

- Lightweight registry, request, customer, permission, loader, response, and DB
  result doubles.
- Stubs for the billing and account models used by included controller paths.
- A testable controller response seam that captures status, JSON, and allowed
  methods without emitting output.
- Call recorders so rejected requests can assert that no read or write model was
  called.
- Helpers that preserve and restore `$_SERVER['REQUEST_METHOD']` and route query
  values in `beforeEach`/`afterEach`.
- Time-relative grant fixtures far enough in the past/future to avoid boundary
  flakes.

### Proposed Feature support

Suggested file:
`tests/Support/BillingApiHelper.php`

- Base URLs and JSON request wrappers for every included route.
- Reuse of `ApiAuthHelper::bearerTokenFor(...)` and
  `ApiAuthHelper::apiRequest(...)`.
- Helpers to switch explicitly between personal, company-admin, company
  employee/representative, and second-user roles.
- Anonymous requests, `joinedErrors()`, and compact sanitized debug output.
- Query builders for grant/consumption ledgers, entitlement grants, and seats.
- Assertions for common billing envelopes, pagination, UTC dates, and
  non-empty errors.
- Configuration guard for test users and required role UUIDs.

Suggested file:
`tests/Support/BillingFixtureHelper.php`

- Creates uniquely labelled local grant and seat fixtures with
  `gmdate('YmdHis')` plus a random suffix.
- Resolves local product, price, company, role, entitlement, grant, and seat IDs
  without hard-coding numeric primary keys.
- Tracks only rows created by the current test and cleans them up narrowly.
- Refuses write scenarios unless the configured company is the dedicated test
  company.
- Exposes read helpers for before/after assertions without putting SQL in
  Feature test bodies.

### Integration configuration

Use the existing `SKIP_INTEGRATION_TESTS` guard. Feature tests require:

- `AUTH_URL`, `CLIENT_ID`, `CLIENT_SECRET`, and `API_URL`
- TEST_USER_1 and TEST_USER_2 credentials
- Personal role UUIDs for both users
- A TEST_USER_1 company-admin role in a dedicated test company
- Optionally, an employee/representative role for negative authorization tests
- Deterministic local price, grant, entitlement, and seat fixtures for write
  workflows

No Stripe configuration or credentials are required by this plan.

## 3. Phase 1 — Unit/controller tests

### 3.1 Common guards and routing

Suggested file:
`tests/Unit/Api/Billing/BillingEndpointGuardsTest.php`

- `included_billing_get_routes_reject_an_unauthenticated_customer` — dataset
  company overview, entitlements, both ledgers, me, entitlement grants, and
  seats; assert **HTTP 401**, non-empty error, and no model call.
- `included_billing_routes_reject_unsupported_methods` — dataset only included
  paths and safe route/method combinations; assert **HTTP 405**, exact
  `allowedHeaders`, and no write.
- `company_scoped_routes_require_company_context` — company ID zero returns the
  controller's exact **HTTP 400** response.
- `role_scoped_routes_require_role_context` — role ID zero returns the exact
  **HTTP 400** response for controllers that require it.
- `included_billing_routes_enforce_public_api_permissions` — deny the required
  GET/POST permission and assert that the permission response wins before model
  reads or writes.
- `included_admin_routes_reject_non_admin_roles` — dataset company overview,
  entitlement grant list/assign, seat list/assign/release; assert **HTTP 403**
  and no downstream mutation.
- `entitlement_grant_assign_dispatches_only_the_exact_subroute` — valid assign
  route reaches assignment; base-route POST and unknown action return
  **HTTP 405**.
- `seat_assign_and_release_dispatch_only_exact_subroutes` — valid actions
  dispatch; base POST and unknown actions return the current method/action error.
- `included_controller_guard_order_is_characterized` — preserve current
  ordering differences for catalog, company overview, entitlement grants, and
  seats so future refactors do not accidentally bypass authorization.

### 3.2 Catalog

Suggested file:
`tests/Unit/Api/Billing/BillingCatalogApiTest.php`

- `catalog_accepts_get_only` — unsupported method returns **HTTP 405** with
  `GET, OPTIONS`.
- `catalog_enforces_route_permission` — denied GET permission returns the
  standard permission response before currency or catalog lookup.
- `catalog_maps_personal_role_to_personal_audience` — assert role code, company
  ID, and customer ID passed to currency resolution and `personal` passed to
  catalog lookup.
- `catalog_maps_admin_role_to_admin_audience` — assert the same contract for
  `admin`.
- `catalog_rejects_unsupported_role_codes` — dataset employee, representative,
  blank, null, and unknown values; assert **HTTP 403** and no catalog lookup.
- `catalog_returns_billing_currency_and_products` — assert the successful
  envelope includes `data.billing_currency` and a products array.
- `catalog_normalizes_product_scalar_types` — tier and sort order become
  integers while recurring/one-time indicators become booleans.
- `catalog_preserves_product_content` — name, internal code, description,
  audience, feature bullets, entitlement summary, and prices match the model.
- `catalog_applies_defaults_for_optional_product_fields` — absent audience,
  tier, sort order, flags, bullets, and entitlement summary map to the
  controller defaults.
- `catalog_returns_an_empty_products_array` — empty model data is successful and
  never becomes null.

### 3.3 Company overview

Suggested file:
`tests/Unit/Api/Billing/BillingCompanyOverviewApiTest.php`

- `company_overview_requires_authentication` — customer ID zero returns
  **HTTP 401**.
- `company_overview_requires_company_and_role_context` — either missing context
  value returns **HTTP 400**.
- `company_overview_enforces_get_permission` — denied route permission returns
  before admin or snapshot lookup.
- `company_overview_requires_company_admin` — non-admin returns **HTTP 403** and
  snapshot model is untouched.
- `company_overview_accepts_get_only_after_admin_check` — characterize current
  guard ordering and exact `allowedHeaders`.
- `company_overview_delegates_once_for_active_company` — assert the exact
  company ID passed to the model.
- `company_overview_preserves_the_model_payload` — a full local overview is
  returned unchanged under `data`.
- `company_overview_preserves_an_empty_payload` — empty model shape remains a
  stable successful response.

### 3.4 Entitlements

Suggested file:
`tests/Unit/Api/Billing/BillingEntitlementsApiTest.php`

- `entitlements_requires_authentication` — **HTTP 401** and no resolver call.
- `entitlements_accepts_get_only` — unsupported method returns **HTTP 405**.
- `entitlements_requires_company_context` — **HTTP 400** before permission or
  resolver access.
- `entitlements_enforces_get_permission` — denied permission returns before
  models are loaded.
- `entitlements_returns_role_limits_usage_and_remaining` — combine resolver
  metadata with role grant/usage values and assert integer types.
- `entitlements_defaults_unknown_metadata` — missing metadata uses
  `unit=count` and nullable limit period.
- `entitlements_sorts_role_entries_by_code` — unordered model values become a
  deterministic code order.
- `entitlements_enriches_codes_found_only_in_role_grants` — assert resolver
  enrichment receives the role codes and role ID.
- `entitlements_normalizes_a_complete_usage_period` — ISO inputs become SQL UTC
  model arguments and are echoed under `data.usage_period`.
- `entitlements_rejects_an_invalid_complete_usage_period` — either invalid
  bound returns **HTTP 400** before snapshot lookup.
- `entitlements_characterizes_an_incomplete_usage_period` — only one bound is
  currently ignored and produces the all-time response.
- `entitlements_characterizes_equal_or_reversed_period_bounds` — syntactically
  valid timestamps currently reach the model without chronological validation.
- `entitlements_adds_used_in_period_only_for_a_complete_period` — period usage
  appears conditionally while all-time usage remains present.
- `entitlements_computes_cross_role_spendability` — assert current role,
  non-negative other roles, and total for every pooled code.
- `entitlements_includes_codes_owned_only_by_other_roles_in_spendability` —
  pooled-only codes are not dropped.
- `entitlements_omits_company_pool_by_default` — source-grant aggregates do not
  leak into a normal role response.
- `entitlements_accepts_documented_truthy_pool_values` — dataset string `"1"`,
  integer `1`, boolean true, and string `"true"`.
- `entitlements_requires_admin_for_company_pool` — non-admin receives
  **HTTP 403** and no pool data.
- `entitlements_includes_company_pool_for_admin` — resolver company
  limits/entitlements appear without replacing role-scoped values.
- `entitlements_returns_a_stable_empty_role_snapshot` — no grants produces empty
  limits, entitlements, and spendability while retaining context fields.

### 3.5 Current-role snapshot (`me`)

Suggested file:
`tests/Unit/Api/Billing/BillingMeApiTest.php`

- `billing_me_requires_authentication` — **HTTP 401** and no model/DB access.
- `billing_me_requires_company_and_role_context` — either missing value returns
  **HTTP 400**.
- `billing_me_accepts_get_only` — unsupported method returns **HTTP 405** with
  exact allowed methods.
- `billing_me_enforces_get_permission` — denied permission returns before
  resolver access.
- `billing_me_builds_role_entitlements` — assert limit, all-time usage,
  remaining, unit, and limit period for each code.
- `billing_me_defaults_missing_entitlement_metadata` — default unit and null
  period are stable.
- `billing_me_sorts_entitlements_by_code` — includes the synthetic
  authentication-rights entry in sort order.
- `billing_me_adds_authentication_rights_when_enabled` — local assignment model
  true maps to remaining 1 with limit 1.
- `billing_me_adds_authentication_rights_when_disabled` — model false maps to
  remaining 0 without omitting the entry.
- `billing_me_computes_cross_role_spendability` — assert current/other/total
  invariants for all pooled codes.
- `billing_me_maps_local_assigned_plan_rows` — DB rows and price display data
  produce IDs, item/slot details, quantities, status, periods, and cancellation
  state.
- `billing_me_prefers_item_values_over_parent_values` — item price, quantity,
  and periods win when present; parent values are fallbacks.
- `billing_me_omits_rows_not_assigned_to_the_current_role` — query result
  fixture protects company and role isolation.
- `billing_me_derives_current_plan_period_from_first_assignment` — present
  assignments produce start/end; no assignments produce null.
- `billing_me_converts_nullable_dates_safely` — absent optional dates remain
  null.
- `billing_me_returns_a_stable_empty_snapshot` — no grants or assignments still
  includes company/role IDs, authentication rights, empty arrays, and null plan.

### 3.6 Role grant ledger

Suggested file:
`tests/Unit/Api/Billing/BillingRoleGrantLedgerApiTest.php`

- `grant_ledger_requires_authentication_company_and_role` — dataset each missing
  context and assert exact status/error with empty `data`.
- `grant_ledger_accepts_get_only_and_enforces_permission` — method and
  permission failures never query the ledger model.
- `grant_ledger_applies_default_pagination` — page 1 and per-page 20 reach the
  model.
- `grant_ledger_clamps_pagination` — page minimum 1 and per-page range 1–100.
- `grant_ledger_accepts_allowed_sorts` — dataset entitlement code, quantity,
  starts-at, and expires-at.
- `grant_ledger_normalizes_order` — lowercase input becomes `ASC` or `DESC`.
- `grant_ledger_accepts_allowed_states` — dataset all, active, expired, and
  future.
- `grant_ledger_normalizes_blank_search_and_state` — search becomes null and
  state becomes `all`.
- `grant_ledger_rejects_invalid_sort` — **HTTP 422**, exact error, empty data,
  and no model call.
- `grant_ledger_rejects_invalid_order` — same no-call contract.
- `grant_ledger_rejects_invalid_state` — same no-call contract.
- `grant_ledger_maps_rows` — assert code, integer quantity, nullable start,
  expiry, and Zulu timestamps.
- `grant_ledger_marks_current_rows_active` — already started and not expired is
  true.
- `grant_ledger_marks_future_or_expired_rows_inactive` — both cases are false.
- `grant_ledger_returns_top_level_pagination` — assert page, per-page, total, and
  ceiling total pages.
- `grant_ledger_returns_stable_empty_page` — empty rows and zero totals remain
  correctly typed.

### 3.7 Role consumption ledger

Suggested file:
`tests/Unit/Api/Billing/BillingRoleConsumptionLedgerApiTest.php`

- `consumption_ledger_requires_authentication_company_and_role` — dataset each
  missing context and assert exact response with empty data.
- `consumption_ledger_accepts_get_only_and_enforces_permission` — failures do
  not query usage.
- `consumption_ledger_applies_and_clamps_pagination` — cover defaults and
  min/max boundaries.
- `consumption_ledger_accepts_allowed_sorts` — dataset occurred-at,
  entitlement code, quantity, and reference type.
- `consumption_ledger_normalizes_order` — allowed order becomes uppercase.
- `consumption_ledger_normalizes_blank_search` — blank becomes null.
- `consumption_ledger_requires_both_date_bounds` — either bound alone returns
  **HTTP 422** `range_incomplete`.
- `consumption_ledger_rejects_invalid_date_bounds` — invalid complete range
  returns **HTTP 422** before model access.
- `consumption_ledger_normalizes_complete_range_to_utc` — exact SQL UTC values
  reach the model.
- `consumption_ledger_characterizes_equal_or_reversed_bounds` — valid
  timestamps reach the model even when start is not before end.
- `consumption_ledger_rejects_invalid_sort` — **HTTP 422**, empty data, no model
  call.
- `consumption_ledger_rejects_invalid_order` — same no-call contract.
- `consumption_ledger_maps_rows` — assert code, integer quantity, Zulu occurred
  time, and nullable reference type.
- `consumption_ledger_returns_top_level_pagination` — cover zero, one, and
  multiple pages.

### 3.8 Entitlement grant packages

Suggested file:
`tests/Unit/Api/Billing/BillingEntitlementGrantsApiTest.php`

List behavior:

- `entitlement_grants_list_requires_price_uuid` — missing/blank returns
  **HTTP 400** before list queries.
- `entitlement_grants_list_applies_and_clamps_pagination` — defaults plus page
  and per-page boundaries.
- `entitlement_grants_list_accepts_allowed_statuses` — all, assigned, and
  unassigned reach the model.
- `entitlement_grants_list_normalizes_blank_status` — blank becomes `all`.
- `entitlement_grants_list_rejects_invalid_status` — **HTTP 400** and no
  count/list/summary call.
- `entitlement_grants_list_passes_trimmed_search` — exact company, price,
  status, search, and pagination arguments.
- `entitlement_grants_list_maps_unassigned_row` — null role/person fields,
  false assignment, quantity, and dates.
- `entitlement_grants_list_maps_assigned_row` — role UUID, combined employee
  name, email, and true assignment.
- `entitlement_grants_list_normalizes_dates_to_zulu` — starts, expiry, and
  creation dates are correctly mapped.
- `entitlement_grants_list_returns_summary_and_pagination` — assert nested page,
  per-page, total, total pages, and model summary.
- `entitlement_grants_list_returns_stable_empty_results` — empty items remain an
  array with zero pagination.

Assignment behavior:

- `entitlement_grant_assignment_accepts_array_and_object_bodies` — both parsing
  forms reach identical validation.
- `entitlement_grant_assignment_requires_both_uuids` — either missing field
  returns **HTTP 400**.
- `entitlement_grant_assignment_hides_unknown_grant` — missing/invalid ID
  returns **HTTP 404**.
- `entitlement_grant_assignment_hides_foreign_company_grant` — **HTTP 404**
  prevents tenant existence disclosure.
- `entitlement_grant_assignment_hides_non_assignable_grant` — **HTTP 404** and
  no write.
- `entitlement_grant_assignment_rejects_already_assigned_grant` — **HTTP 409**
  and no duplicate assignment.
- `entitlement_grant_assignment_rejects_role_outside_company` — **HTTP 400**
  before write.
- `entitlement_grant_assignment_propagates_model_error` — model error is
  returned, with `assignment_failed` fallback.
- `entitlement_grant_assignment_returns_created_assignment` — assert exact
  grant ID, role ID, quantity, replacement flag, and complete success shape.

### 3.9 Seat listing

Suggested file:
`tests/Unit/Api/Billing/BillingSeatsListApiTest.php`

- `seats_list_requires_subscription_item_uuid` — **HTTP 400** before item
  lookup.
- `seats_list_hides_missing_item` — **HTTP 404**.
- `seats_list_hides_item_from_another_company` — foreign parent returns
  **HTTP 404** without seat queries.
- `seats_list_applies_and_clamps_pagination` — defaults and 1–100 boundaries.
- `seats_list_accepts_allowed_statuses` — all, assigned, unassigned, and
  cancel-pending filters.
- `seats_list_normalizes_blank_status` — blank becomes `all`.
- `seats_list_rejects_invalid_status` — **HTTP 400** before count/list/summary.
- `seats_list_passes_trimmed_search` — exact arguments reach all list models.
- `seats_list_maps_unassigned_row` — null role/person fields, item identity,
  slot state, and assigned date.
- `seats_list_maps_assigned_row` — role UUID, employee name/email, and
  assignment date.
- `seats_list_combines_local_cancellation_flags` — either slot or assignment
  pending flag maps to true.
- `seats_list_includes_allowance_for_assigned_filter` — assigned rows request
  local allowance details.
- `seats_list_includes_allowance_for_include_tokens` — `allowance` and `usage`
  request allowance details.
- `seats_list_omits_allowance_when_not_requested` — protects against an
  unnecessary per-row overview query.
- `seats_list_returns_summary_and_pagination` — stable populated response.
- `seats_list_returns_stable_empty_results` — empty array and zero totals.

### 3.10 Local seat assignment and release

Suggested file:
`tests/Unit/Api/Billing/BillingSeatsAssignmentApiTest.php`

- `seat_assign_and_release_require_slot_uuid` — missing/blank returns
  **HTTP 400**.
- `seat_assign_requires_target_role_uuid` — missing target returns the exact
  validation error.
- `seat_mutations_accept_array_and_object_bodies` — parsing is equivalent.
- `seat_assignment_rejects_unknown_target_role` — **HTTP 400** and no slot
  lookup/write.
- `seat_assignment_rejects_target_role_outside_company` — ownership model false
  returns **HTTP 400**.
- `seat_mutations_hide_unknown_slot` — **HTTP 404**.
- `seat_mutations_hide_foreign_company_slot` — **HTTP 404** and no item or
  assignment mutation.
- `seat_mutations_reject_missing_local_item_context` — characterize the exact
  local **HTTP 500/404** branches without writes.
- `seat_mutations_reject_foreign_parent` — parent outside active company is
  rejected.
- `seat_assignment_reconciles_slots_to_positive_item_quantity` — local slot
  reconciliation runs before capacity checks.
- `seat_assignment_accepts_only_available_local_slot_states` — invalid state
  returns `seat_slot_not_assignable`.
- `seat_assignment_prevents_duplicate_parent_assignment` — same role cannot
  receive another active seat in the parent plan.
- `seat_assignment_rejects_full_active_capacity` — active count at capacity
  returns `no_unassigned_seats`.
- `seat_assignment_rejects_when_no_free_slot_exists` — local free-slot count
  zero returns the same error.
- `seat_assignment_returns_assignment_id` — exact company, local item, role, and
  slot context reach the assignment model; response contains positive ID.
- `seat_assignment_maps_zero_assignment_id_to_500` — failed local insert returns
  `assignment_failed`.
- `seat_release_passes_exact_local_context` — release model receives item/slot/
  company identifiers and response returns `ok=true`.
- `seat_release_is_idempotent_at_controller_boundary` — repeated release keeps
  the documented success response and does not create assignments.

## 4. Phase 2 — Feature/integration tests

All files below use real HTTP, authentication, routing, permissions, and local
database models. Every test asserts status, JSON envelope, important data keys,
and non-empty errors on failure.

Titles should use reader-first wording such as
`Billing — an employee cannot assign company seats`.

### 4.1 Authentication, active roles, and routing

Suggested file:
`tests/Feature/Api/Billing/BillingAuthAndRoutingFlowTest.php`

- `Billing — anonymous callers cannot read local billing data` — call each
  included GET route without Authorization; assert no successful response or
  billing data.
- `Billing — anonymous callers cannot assign grants or seats` — included POST
  writes fail with no local state change.
- `Billing — personal active role sees only the personal catalog` — switch
  TEST_USER_1 explicitly and assert all products are personal/both.
- `Billing — company admin sees only the admin catalog` — switch to admin and
  assert all products are admin/both.
- `Billing — unsupported company role cannot use admin billing routes` —
  overview, grants, and seats return **HTTP 403**.
- `Billing — personal context cannot use company-admin routes` — assert the
  documented **HTTP 400/403** outcomes without data leakage.
- `Billing — missing route permission is enforced end to end` — a fixture role
  lacking access cannot read or mutate local billing data.
- `Billing — wrong verbs and unknown included actions are refused` — safe
  route/method matrix returns **HTTP 405** with no state change.

### 4.2 Catalog, overview, and current-role snapshot

Suggested file:
`tests/Feature/Api/Billing/BillingCatalogAndSnapshotFlowTest.php`

- `Billing — personal catalog matches account currency` — assert currency,
  product shape, active audience, price currency, flags, and scalar types.
- `Billing — admin catalog matches company currency` — same contract in company
  context.
- `Billing — catalog does not expose products for another audience` — compare
  personal and admin responses by stable product identifiers.
- `Billing — company admin can view local billing overview` — assert expected
  top-level sections and active-company identity.
- `Billing — non-admin cannot view company overview` — **HTTP 403** and no
  overview payload.
- `Billing — current role can view effective billing snapshot` — `/me` returns
  matching company/role IDs, sorted entitlements, authentication rights,
  spendability, local assignments, and current-plan shape.
- `Billing — switching active roles changes effective snapshot` — compare role
  IDs and ensure allowances/assignments do not bleed across roles.
- `Billing — second user cannot read first user role snapshot` — a foreign role
  header cannot expose first-user entitlements or assignments.

### 4.3 Entitlements and ledgers

Suggested file:
`tests/Feature/Api/Billing/BillingEntitlementsAndLedgersFlowTest.php`

- `Billing — role entitlements agree with local grant and usage data` — verify
  code set, non-negative remaining, and internally consistent limits/usage.
- `Billing — usage period adds period usage without changing all-time usage` —
  request a known UTC range and compare both fields.
- `Billing — malformed entitlement dates are rejected` — **HTTP 400** and
  non-empty error.
- `Billing — one-sided entitlement period follows documented current behavior`
  — assert the characterized all-time response.
- `Billing — only admin can include company entitlement pool` — compare admin
  success and non-admin **HTTP 403**.
- `Billing — grant ledger paginates filters sorts and searches` — deterministic
  active/future/expired rows and at least two sort orders.
- `Billing — grant ledger rejects invalid filters` — bad sort/order/state
  returns **HTTP 422** and empty data.
- `Billing — consumption ledger paginates filters sorts and searches` — use
  deterministic local usage rows and assert role scoping.
- `Billing — consumption ledger requires complete valid range` — one-sided and
  malformed ranges return **HTTP 422**.
- `Billing — ledger timestamps are returned in UTC` — assert Zulu format and
  compare instants.
- `Billing — one role cannot read another role ledger` — foreign role selection
  never exposes rows.

### 4.4 Top-up grant administration

Suggested file:
`tests/Feature/Api/Billing/BillingEntitlementGrantAssignmentFlowTest.php`

- `Billing — admin can list top-up packages for a price` — assert search,
  filters, summary, assignment identity, and nested pagination.
- `Billing — non-admin cannot list grant packages` — **HTTP 403**.
- `Billing — non-admin cannot assign grant package` — **HTTP 403** and unchanged
  assignment count.
- `Billing — admin assigns an unassigned grant to company role` — create a
  disposable local grant, assert success, then confirm assigned listing and
  target role allowance.
- `Billing — same grant cannot be assigned twice` — second request returns
  **HTTP 409** and only one active assignment exists.
- `Billing — admin cannot assign another company grant` — foreign grant is
  hidden as **HTTP 404**.
- `Billing — admin cannot assign grant to another company role` — **HTTP 400**
  and no write.
- `Billing — invalid filters and payloads cause no writes` — missing price, bad
  status, missing UUIDs, and unknown UUIDs.

### 4.5 Seat listing, assignment, and release

Suggested file:
`tests/Feature/Api/Billing/BillingSeatAssignmentFlowTest.php`

- `Billing — admin can list seats for local plan item` — assert state, employee
  identity, local pending flag, summary, and pagination.
- `Billing — assigned-seat list includes allowance details` — assigned filter or
  `include=allowance` returns expected allowance shape.
- `Billing — admin cannot see another company item` — **HTTP 404** with no item
  details.
- `Billing — non-admin cannot list seats` — **HTTP 403**.
- `Billing — non-admin cannot assign or release seats` — both writes return
  **HTTP 403** and local state is unchanged.
- `Billing — admin assigns free seat to company role` — assert assignment
  response, list transition, role UUID, `/me`, and effective allowance.
- `Billing — one role cannot receive duplicate seats from same plan` — second
  assignment is rejected and counts remain unchanged.
- `Billing — local capacity prevents over-assignment` — full item returns
  `no_unassigned_seats`.
- `Billing — admin releases assigned seat` — list and `/me` reflect release.
- `Billing — repeating release follows idempotent controller contract` — second
  call succeeds without creating or duplicating data.
- `Billing — foreign roles and slots are rejected` — **HTTP 400/404** plus
  tenant-isolation assertions.

### 4.6 Cross-endpoint consistency

Suggested file:
`tests/Feature/Api/Billing/BillingLocalConsistencyFlowTest.php`

- `Billing — assigned seat is consistent across overview seats me and entitlements`
  — compare company counts, seat row, role assignment, and effective allowance.
- `Billing — released seat disappears from role and remains locally available`
  — verify the same views after release.
- `Billing — assigned top-up grant appears in role allowance and grant ledger`
  — compare grant list, `/me`, `/entitlements`, and ledger output.
- `Billing — local consumption changes usage remaining and ledger together` —
  trigger a safe entitlement-consuming operation from another API, then assert
  debit-once and consistent totals.
- `Billing — switching between companies never mixes local billing resources`
  — compare overview, seats, grants, ledgers, and role IDs for two contexts.

## 5. Test data, cleanup, and isolation

1. Unit tests never access the real database, filesystem, network, cache, or
   clock.
2. Feature tests may read stable catalog/configuration rows but create uniquely
   identifiable local grant or seat fixtures for write scenarios.
3. Do not run local mutation tests in parallel against the same company/item.
   Parallel-safe cases require separate fixture companies/items.
4. Record pre-test local state and clean up only rows created by the current
   test. Never use broad deletes.
5. Prefer normal API cleanup when it exists. Use narrow fixture-helper cleanup
   only for test-owned rows that have no API cleanup route.
6. Skip with a precise reason when optional role or local fixture configuration
   is unavailable.
7. Do not turn genuine authorization, validation, tenant-isolation, or
   consistency failures into skips.

## 6. Required assertion and documentation standards

Every new Pest test must have the PHPDoc block immediately above `test()`:

```php
/**
 * Prerequisites:
 * - Integration tests are enabled and required test users/roles are configured.
 * - TEST_USER_1 is explicitly switched to the role required by this scenario.
 *
 * Steps:
 * 1. Arrange the local grant, entitlement, or seat state.
 * 2. Call the included billing endpoint through the real HTTP API.
 * 3. Assert status, response shape, authorization boundary, and local state.
 */
test('Billing — short behavior in everyday language', function () {
    // ...
});
```

For every case:

- Assert exact HTTP status and important `data`/`pagination` keys.
- Assert scalar types and meaningful equalities, not only key existence.
- On expected failure, assert a non-empty error and prove forbidden model writes
  did not occur.
- For tenant-security tests, verify both the response and the absence of foreign
  identifiers.
- For dates, assert UTC/Zulu shape and compare instants rather than display
  strings.
- For monetary catalog values, assert integer minor units and currency; never
  compare floating-point amounts.
- Keep repeated request payloads and fixture setup in helpers so the test body
  remains arrange/action/assert.

## 7. Recommended implementation order and merge gates

### Phase 1 merge gate

1. Add `BillingTestDoubles.php`.
2. Implement common authentication, method, context, permission, and route
   datasets for included paths.
3. Implement catalog, overview, entitlements, `/me`, and ledger tests.
4. Implement entitlement grant list/assignment tests.
5. Implement seat list/assignment/release tests.
6. Run the entire Unit suite with no network or external billing provider.

### Phase 2 merge gate

1. Add `BillingApiHelper` and non-mutating auth/catalog/snapshot Feature tests.
2. Add deterministic entitlements and ledger workflows.
3. Add grant assignment and seat assignment/release workflows with narrow
   cleanup.
4. Add cross-company security tests.
5. Add cross-endpoint local consistency workflows.
6. Run all billing Feature files alongside the existing integration suite.

The baseline CI gate should include all Unit and Feature tests in this revised
plan. There is no separate Stripe or external-provider job.

## 8. Product decisions to resolve while implementing

Tests should characterize current behavior first. Changing these contracts
requires an explicit product/API decision:

- `/entitlements` silently ignores a one-sided usage period, while the
  consumption ledger rejects a one-sided range.
- Entitlement and consumption ranges validate timestamp syntax but do not
  require start to be earlier than end.
- Seat release does not report whether a slot was assigned before release;
  confirm that repeated release should remain idempotent.
- `/me` derives `current_plan` from the first local assigned-plan row ordered by
  assignment recency; confirm this is the intended definition when a role has
  several active assignments.
- Seat-list allowance inclusion can cause one overview lookup per returned row;
  retain response coverage while deciding whether this needs bulk loading.
