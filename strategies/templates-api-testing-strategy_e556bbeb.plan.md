---
name: templates-api-testing-strategy
overview: Define a comprehensive testing strategy for the eSign templates and template library APIs, aligned with existing documents and signing tests.
todos: []
isProject: false
---

### Templates & Library API testing strategy

**Controllers**:

- `ControllerPublicAPIV1EsignTplTemplates` (`catalog/controller/publicapi/v1/Templates/templates.php`)
- `ControllerPublicAPIV1EsignTplLibrary` (`catalog/controller/publicapi/v1/Templates/library.php`)

**Related guidance**:

- General rules from `[tests/strategies/documents-testing-guidelines.md](tests/strategies/documents-testing-guidelines.md)`
- Structure and style from `[tests/strategies/signing-testing-strategy.md](tests/strategies/signing-testing-strategy.md)` (mirrored here for templates/library APIs)

The testing strategy document to add under `tests/strategies` (e.g. `templates-testing-strategy.md`) should follow the same style as the signing one: first list objectives and endpoints, then split into **unit tests** (controller-level with mocks) and **integration/feature tests** (HTTP-level), and finally suggest concrete test file names.

---

### 1. Scope and objectives

- **Functionality under test**:
  - Endpoints for **user templates** (create, list, get, update, delete, publish, archive, clone, edit, versions, parties, smartfields).
  - Endpoints for **template library** (list, get, versions, create, update, delete, publish, archive, clone, add-to-my-templates, versions, parties, smartfields).
- **Primary objectives**:
  - **Correct routing and permission checks** for HTTP method + path combinations, including library-specific `hasPermission` safeguards and `requireSimplifiRoForWrite` behavior.
  - **Data validation & normalization** for payloads: `name`, `content`, `language_id`, `category_code`, `visibility`, `status`, plus parties/smartfields JSON decoding and duplicate detection.
  - **Ownership and visibility rules** for templates (company + role ownership) and library (read-only for external users, write for `@simplifi.ro`).
  - **Versioning behavior** for both templates and libraries: creating draft versions, editing only drafts, publishing/archiving rules, pagination and sorting on version listings.
  - **Error handling**: unit tests must assert HTTP **status codes and optional error `code` fields**, but **must not assert on specific error message text**; where only free-text errors are present, assert that an `error` array exists and is non-empty.

---

### 2. Unit tests (controller-level, exhaustive)

Unit tests will mock:

- `model_esign_tpl_template`, `model_esign_tpl_library` and any related model calls (e.g. list, create, update, delete, publish, archive, versions, parties, smartfields, visibility helpers if any).
- `customer` (email, company id, role id), `permission`, `request`, `apiRequest`, and `response` (for headers).
- `Uuid::uuid4()` where deterministic UUIDs are needed.

#### 2.1 Templates controller `index()` routing and permissions

Cover routing decisions based on `$_SERVER['REQUEST_METHOD']`, `route`, `_route_`, `uuid`, `action`, and `version_uuid`:

- **Route parsing**:
  - `route` and `_route_` with `/api/v1/Templates/templates/{uuid}` and `/templates/{uuid}/{action}` formats are correctly parsed into `uuid` and `action`.
  - Fallback to `uuid` and `action` from query string when no route segments are present.
- **Permissions matrix** (no message text inspection, only status code + presence of error):
  - Each combination of method + (uuid, action, version_uuid) dispatches to the expected private method when the corresponding `$permission->{get,post,put,delete}` contains the path.
  - When permission is missing, controller returns **403** with a non-empty `error` array.
- **Method not allowed**:
  - Invalid combinations fall through to **405** with `allowedHeaders = ["GET","POST","PUT","DELETE"]`.

#### 2.2 Templates controller create & list

- `**createTemplate()` (POST /templates):
  - Missing `name` → **422** with `error.code = 'VALIDATION_ERROR'` and `field = 'name'` (assert `code` and `field`, not `message`).
  - Missing `content` → **422**, `code = 'VALIDATION_ERROR'`, `field = 'content'`.
  - Invalid `category_code` (not in allowed list) → **422**, `code = 'VALIDATION_ERROR'`, `field = 'category_code'`.
  - Missing `company_id` or `customer_role_id` from `customer` → **403**.
  - Parties/smartfields normalization:
    - When sent as raw arrays → `normalizePartiesAndSmartfields` passes arrays through.
    - When sent as HTML-encoded JSON strings → decoded & parsed into arrays.
  - Parties validation (`validateParties`) paths:
    - Non-array parties → **422**, `code = 'VALIDATION_ERROR'`, `field = 'parties'`.
    - Missing `code`, `label`, or `signing_order` entries.
    - Duplicate `code` values.
  - Smartfields validation (`validateSmartfields`) paths:
    - Non-array smartfields → **422**, `code = 'VALIDATION_ERROR'`, `field = 'smartfields'`.
    - Missing `field_key`, `label`, or `type`.
  - Successful creation:
    - Model `createTemplate` called with expected payload (including default `category_code = 'general'`, `visibility = 'private'`, `status = 'draft'`, `version` default 1, optional `source_library_id`).
    - Optional calls to `replaceVersionParties`/`replaceVersionSmartfields` when arrays are provided.
    - Response includes `data.uuid` and **200** (or implied default success status) with no error entries.
- `**listTemplates()` (GET /templates):
  - Validates and rejects unsupported `sort` and `order` values with **422**.
  - For allowed sorts (`date_added`, `date_modified`, `name`, `status`, `visibility`, `version`) and orders (`ASC`, `DESC`), passes parameters to model including company and role scopes.
  - Applies optional filters: `status`, `visibility`, `category_code` from query.
  - Responds with `data` array of templates; each item has normalized types (e.g. `version`, `parties_count`, `smartfields_count` as integers) and default `category_code = 'general'` when missing.
  - Sets pagination headers (`X-Total-Count`, `X-Pagination-*`) based on model result.

#### 2.3 Templates controller single template, update, delete, publish, archive, clone, edit

- `**getTemplate($uuid)`:
  - Template not found → **404**.
  - `created_by_customer_role_id` mismatch with current `customer_role_id` → **403**.
  - Successful path returns `data` including parties and smartfields arrays formatted via `formatPartiesForResponse` and `formatSmartfieldsForResponse`.
- `**updateTemplate($uuid)`:
  - Template not found → **404**.
  - Ownership mismatch → **403**.
  - Non-draft status → **422** with non-empty `error`.
  - Category code normalization + validation similar to create.
  - Parties/smartfields JSON normalization and validation (same cases as `createTemplate`).
  - No fields to update (no basic fields and `parties`/`smartfields` null) → **422**.
  - Successful update calls `updateTemplate`, optionally `replaceVersionParties`/`replaceVersionSmartfields`, and returns empty `data` with success status.
- `**deleteTemplate($uuid)`:
  - Not found → **404**.
  - Ownership mismatch → **403**.
  - Status `published` → **422**, cannot delete.
  - Successful delete calls model and returns `data.deleted = true`.
- `**publishTemplate($uuid)` & `archiveTemplate($uuid)`:
  - Not found → **404**.
  - Ownership mismatch → **403**.
  - Invalid status transitions (publishing non-draft, archiving non-published) → **422**.
  - Successful paths call respective model methods and return minimal `data` (`uuid`, `status`, `version` when applicable).
- **Version-group helpers (`copyPartiesAndSmartfieldsToTemplate`, `cloneTemplate`, `editPublishedTemplate`)**:
  - `copyPartiesAndSmartfieldsToTemplate`:
    - Fails when `replaceVersionParties` or `replaceVersionSmartfields` returns false → **500** and appropriate `error` entry (assert non-empty error only, not message text).
    - Successful path clones fields correctly, preserving required flags, optional `party_code`, `default_value`.
  - `cloneTemplate`:
    - Not found, ownership mismatch → same behaviors as above.
    - On success, calls `cloneTemplateToNewGroup` then `copyPartiesAndSmartfieldsToTemplate`, returning new template data.
  - `editPublishedTemplate`:
    - Only allows editing when template status is `published`; other statuses give **422**.
    - Creates draft version in same group and clones parties/smartfields.

#### 2.4 Templates controller versions, parties, smartfields

- `**getTemplateVersions($template_uuid)`:
  - Template not found → **404**.
  - Ownership mismatch → **403**.
  - Missing company → **403**.
  - Invalid `sort` or `order` → **422**.
  - When `getTemplateGroupVersions` returns empty `versions` and `total = 0` → **404**.
  - Successful path returns normalized version data and pagination headers.
- `**createVersion($template_uuid)`:
  - Template not found → **404**.
  - Ownership mismatch → **403**.
  - Missing `content` → **422**, `code = 'VALIDATION_ERROR'`, `field = 'content'`.
  - Successful path:
    - Calculates next version number (mock `getLatestVersionNumber`).
    - Calls `createVersion` with expected data.
    - Returns version metadata from `getVersionByUuid`.
- `**updateVersion($template_uuid, $version_uuid)`:
  - Template not found → **404**.
  - Ownership mismatch → **403**.
  - Version not found or wrong `template_uuid` → **404**.
  - Version not `draft` → **422**.
  - No fields to update → **422**.
  - Successful path calls `updateVersion` and returns latest version info.
- `**replaceParties` & `replaceSmartfields` for versions:
  - Common checks: template not found, ownership mismatch, version not found/wrong template, non-draft version → **404** or **422**.
  - Payload parsing:
    - Accept both application/json body and form-data, with JSON-string fields decoded.
    - Invalid JSON decoding for parties/smartfields → **422** with `code = 'VALIDATION_ERROR'` and `field` set appropriately.
  - Structural validation:
    - Parties: require `code`, `label`, `signing_order`; reject duplicates.
    - Smartfields: require `field_key`, `label`, `type`; reject duplicate `field_key`.
  - On success, models are called with provided arrays and responses include normalized arrays via `format*ForResponse`.
- `**publishVersion($template_uuid, $version_uuid)`:
  - Template or version not found, or mismatch group → **404**.
  - Version not `draft` → **422**.
  - Successful publish calls model and returns latest version status and `published_at`.

#### 2.5 Library controller index() routing, permission, and Simplifi-only gates

- **Routing & permission dispatch**:
  - For GET without `uuid`, with `uuid` only, and `uuid` + `versions` → dispatch to list/get/version methods when `hasPermission('get', path)` is true; otherwise **403**.
  - POST `add-to-my-templates` always allowed for users with POST permission (no `@simplifi.ro` restriction) and correctly dispatches to `addLibraryToMyTemplates`.
- **Write-ops gate** (`requireSimplifiRoForWrite` + permission):
  - For each write operation (create, update, delete, publish, archive, edit, versions, replace parties/smartfields, publish version, library-clone):
    - If email is not `@simplifi.ro`, respond **403** and do not call underlying method; assert only status and presence of `error`, not message.
    - If `@simplifi.ro` and permission is missing, respond **403**.
    - If both gate and permission pass, dispatch to underlying method.
  - `permission` null or missing properties falls back to empty arrays via `index` setup; `hasPermission` must not throw.

#### 2.6 Library list/get/versions

- `**listLibraries()`:
  - Reads query parameters (`category_code`, `language_id`, `page`, `per_page`, `sort`, `order`, `status`) and passes to model.
  - For non-`@simplifi.ro` email, forces `status = 'published'` unless explicitly overridden for internal accounts.
  - Normalizes output: `version`, `parties_count`, `smartfields_count`, `language_id` as integers, `status` default `draft`, `category_code` default `general`.
  - Computes pagination headers based on returned list count.
- `**getLibrary($uuid)`\*\*:
  - Not found → **404**.
  - Successful path loads parties and smartfields and returns them via formatters, plus `can_archive` flag based on email domain and status.
- `**getLibraryVersions($library_uuid)`\*\*:
  - Not found → **404**.
  - Applies filters and pagination details to model.
  - Normalizes version objects into the standard `uuid`, `library_uuid`, `version_number`, `status`, `published_at`, `date_added` format.

#### 2.7 Library create/update/delete/publish/archive/edit/version operations

- `**createLibrary()`\*\*:
  - Missing `name` or `language_id` → **422**, with `code = 'VALIDATION_ERROR'` and `field` set.
  - Invalid `category_code` → **422**, `code = 'VALIDATION_ERROR'`, `field = 'category_code'`.
  - Accepts `content` as string or encoded JSON; stores string form.
  - On success, `createLibrary` called and response contains `data.uuid`.
- `**updateLibrary($uuid)`\*\*:
  - Not found → **404**.
  - Non-draft status → 422`.
  - Category and language validation similar to create.
  - Parties/smartfields normalization and validation using library-specific helpers (with single-error-message behavior) and mapping to **422** with `code = 'VALIDATION_ERROR'`, `field` set.
  - No fields to update and no parties/smartfields → 422`.
  - Successful path calls `updateLibrary` (when needed), `replaceVersionParties`/`replaceVersionSmartfields`, and returns empty `data`.
- `**deleteLibrary($uuid)`\*\*:
  - Not found → **404**.
  - Model delete failure → **500**.
  - Success → `data.deleted = true`.
- `**publishLibrary($uuid)`\*\*:
  - Library not found → **404**.
  - No draft version available (after examining base row + draft versions) → 422`.
  - Successful publish calls model and returns standard version metadata including group UUID.
- `**archiveLibrary($uuid)`:
  - Library not found → **404**.
  - No published version to archive → 422`.
  - Successful archive returns `data.archived = true`.
- `**editPublishedLibrary($uuid)`\*\*:
  - Library not found or no latest published version → **404 `or **422` as appropriate.
  - Successful path creates a new draft version in same group and returns its identifiers and status.
- `**createLibraryVersion`, `updateLibraryVersion`:
  - Library or version not found, mismatched group, or non-draft version → **404**/422`.
  - Missing or absent `content` → 422`with`code`/`field`for create;`update`requires at least`content` change.
  - Successful paths use model APIs to create/update and then return standardized version metadata.
- `**replaceLibraryParties`, `replaceLibrarySmartfields`, `publishLibraryVersion`:
  - Common checks: library not found, version not found or mismatch group, non-draft version → **404**/422`.
  - Parties/smartfields parsing and structural checks similar to templates versions (must be arrays, required keys, etc.).
  - Successful replacements read back rows and return formatted arrays; `publishLibraryVersion` returns updated version metadata.

#### 2.8 `addLibraryToMyTemplates` flow

- Library or latest published version missing → **404** or **422**.
- Customer missing company or role → **403**.
- Successful creation:
  - Creates a template belonging to caller's company/role.
  - Copies parties/smartfields from latest published version, falling back to base library row when version arrays are empty.
  - Normalizes booleans and JSON-encoded `validation`/`rendering` where present.
  - Returns new template identifiers including `version_uuid` and `version_number = 1`.

**Note**: In all error scenarios, unit tests should verify **HTTP status codes**, `error` array presence, and, when structured validation errors are returned, the `code` and `field` values, but **never assert exact `message` strings**.

---

### 3. Integration / feature tests (HTTP-level main flows and permissions)

Integration tests should reuse patterns and helpers described in `[tests/strategies/documents-testing-guidelines.md](tests/strategies/documents-testing-guidelines.md)` and mirror the structure of the signing feature tests:

- Use helpers for auth (e.g., `ApiAuthHelper`) and for arranging companies, customers, and permissions.
- Use transaction rollbacks or test DB fixtures per test where available.
- Focus on **end-to-end behavior and security**, not on internal model calls.

#### 3.1 Templates: CRUD + versioning flows

Main flows to cover:

- **Create & list user templates**:
  - Happy path: authenticated customer with `post` permission on `publicapi/v1/Templates/templates` creates a template with name/content/category/visibility; list endpoint returns it with correct pagination metadata and counts, using defaults when optional fields are omitted.
  - Validation failures (
    missing name/content, invalid category, invalid parties/smartfields) yield **422** with validation `code` and `field` (assert those; not message text).
- **Read, update, delete**:
  - Owner can:
    - GET own template including parties and smartfields.
    - PUT updates for draft templates only (content, category, visibility, parties, smartfields).
    - DELETE draft templates; DELETE for published returns **422** and leaves record intact.
  - Non-owner with valid permission path but different `created_by_customer_role_id` receives **403** for GET/PUT/DELETE.
- **Publish & archive lifecycle**:
  - Owner can:
    - Publish draft templates.
    - Archive published templates.
  - Invalid transitions (publish non-draft, archive non-published) yield **422**.
  - List endpoint should reflect updated statuses and filter by `status` correctly (e.g. excluding archived by default).
- **Clone & edit published**:
  - `POST /templates/{uuid}/clone` creates new template group with same content and copied parties/smartfields.
  - `POST /templates/{uuid}/edit` on published template generates a new draft version within the same group, with cloned parties/smartfields.
  - Permissions: only owner with `post` permission can invoke these; others get **403**.
- **Template versions listing and operations**:
  - `GET /templates/{uuid}/versions` returns only versions of that group, with pagination and sort behavior.
  - `POST /templates/{uuid}/versions` creates a draft version with incremented version number and content.
  - `PUT /templates/{uuid}/versions/{version_uuid}` updates only draft versions.
  - `POST /templates/{uuid}/versions/{version_uuid}/publish` publishes draft versions and marks them as latest; listing reflects the change.
  - `PUT /templates/{uuid}/versions/{version_uuid}/parties` and `/smartfields` replace respective arrays and return updated normalized payloads.

#### 3.2 Permissions and security for templates

- **Permission-based access**:
  - Users without `get` permission cannot list or get templates (**403**), independent of ownership.
  - Users without `post`/`put`/`delete` permission cannot perform create/update/delete/publish/archive/clone/edit/version operations.
- **Owner vs non-owner behavior**:
  - Non-owners with GET permission but wrong `created_by_customer_role_id` should receive **403** for reading/editing/deleting templates, avoiding access to other companies/roles templates.
- **Multi-tenant safety**:
  - Tests should assert that templates created for one company/role do not appear in `listTemplates` for other companies/roles, even when permission path is shared.

#### 3.3 Template library flows

- **Public library browsing (external or non-Simplifi users)**:
  - `GET /library` lists only `published` status entries, with filters by `category_code` and `language_id`.
  - `GET /library/{uuid}` returns library template details including formatted parties and smartfields, with `can_archive = false` for external users.
  - `GET /library/{uuid}/versions` lists versions, respecting `status` filter for internal users; external users by default see only published variants.
- **Internal library management (`@simplifi.ro` accounts)**:
  - Full lifecycle for library templates (create, update, delete, publish, archive, edit, create version, update version, publish version, replace parties/smartfields, clone library) is available only when:
    - Authenticated customer has `@simplifi.ro` email; and
    - Has appropriate permission for the `publicapi/v1/Templates/library` path.
  - Tests should verify that external users always receive **403** for these operations, even when permissions are accidentally configured.
- **Add-to-my-templates flow**:
  - Any user with POST permission on library path can call `POST /library/{uuid}/add-to-my-templates`:
    - When library has at least one published version, a new draft user template is created with content, description, category, and copied parties/smartfields.
    - When no published version but base library row has parties/smartfields, they are used as fallback.
  - Permissions and company/role checks:
    - Missing company or customer role for the caller → **403**.
  - End-to-end verification:
    - After calling add-to-my-templates, subsequent `GET /templates` and `GET /templates/{uuid}` show the created template properly.

#### 3.4 Error-handling and robustness (integration)

- **Bad payloads and JSON decoding**:
  - Multipart/form-data requests where `parties`/`smartfields` are malformed JSON should result in **422** with validation `code` and correct `field`.
  - Application/json requests with incompatible structures (non-array for parties/smartfields) also return **422**.
- **Pagination and sorting**:
  - List endpoints for templates and libraries respect `page`, `per_page`, `sort`, and `order` options, including boundary conditions (empty pages, max page > total pages).
- **Method not allowed**:
  - Direct access with unsupported HTTP methods returns **405** and correct `Allow` header contents.

**For all integration tests**, follow the guidance from `documents-testing-guidelines.md`:

- Use helpers for arranging documents, templates, and permissions.
- Assert HTTP status codes and high-level response shape (`data` vs `error` presence).
- For validation errors, assert `code` and `field`, but **never rely on human-readable `message` text**.

---

### 4. Suggested test file layout

Mirror the layout used in the signing strategy, under `tests/Unit/Api/Templates` and `tests/Feature/Api/Templates` (or the closest existing folder structure):

- **Unit tests (templates)**
  - `tests/Unit/Api/Templates/TemplatesIndexTest.php`
  - `tests/Unit/Api/Templates/CreateTemplateTest.php`
  - `tests/Unit/Api/Templates/ListTemplatesTest.php`
  - `tests/Unit/Api/Templates/GetTemplateTest.php`
  - `tests/Unit/Api/Templates/UpdateTemplateTest.php`
  - `tests/Unit/Api/Templates/DeleteTemplateTest.php`
  - `tests/Unit/Api/Templates/PublishTemplateTest.php`
  - `tests/Unit/Api/Templates/ArchiveTemplateTest.php`
  - `tests/Unit/Api/Templates/CloneTemplateTest.php`
  - `tests/Unit/Api/Templates/EditPublishedTemplateTest.php`
  - `tests/Unit/Api/Templates/GetTemplateVersionsTest.php`
  - `tests/Unit/Api/Templates/CreateTemplateVersionTest.php`
  - `tests/Unit/Api/Templates/UpdateTemplateVersionTest.php`
  - `tests/Unit/Api/Templates/ReplaceTemplatePartiesTest.php`
  - `tests/Unit/Api/Templates/ReplaceTemplateSmartfieldsTest.php`
  - `tests/Unit/Api/Templates/PublishTemplateVersionTest.php`
- **Unit tests (library)**
  - `tests/Unit/Api/TemplateLibrary/LibraryIndexTest.php`
  - `tests/Unit/Api/TemplateLibrary/ListLibrariesTest.php`
  - `tests/Unit/Api/TemplateLibrary/GetLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/GetLibraryVersionsTest.php`
  - `tests/Unit/Api/TemplateLibrary/CreateLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/UpdateLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/DeleteLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/PublishLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/ArchiveLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/EditPublishedLibraryTest.php`
  - `tests/Unit/Api/TemplateLibrary/CreateLibraryVersionTest.php`
  - `tests/Unit/Api/TemplateLibrary/UpdateLibraryVersionTest.php`
  - `tests/Unit/Api/TemplateLibrary/ReplaceLibraryPartiesTest.php`
  - `tests/Unit/Api/TemplateLibrary/ReplaceLibrarySmartfieldsTest.php`
  - `tests/Unit/Api/TemplateLibrary/PublishLibraryVersionTest.php`
  - `tests/Unit/Api/TemplateLibrary/AddLibraryToMyTemplatesTest.php`
  - `tests/Unit/Api/TemplateLibrary/CloneLibraryToNewTest.php`
- **Feature / integration tests**
  - `tests/Feature/Api/Templates/TemplatesCrudFlowTest.php`
  - `tests/Feature/Api/Templates/TemplatesVersioningFlowTest.php`
  - `tests/Feature/Api/Templates/TemplatesPermissionsTest.php`
  - `tests/Feature/Api/Templates/LibraryPublicBrowsingTest.php`
  - `tests/Feature/Api/Templates/LibraryManagementFlowTest.php`
  - `tests/Feature/Api/Templates/LibraryPermissionsTest.php`
  - `tests/Feature/Api/Templates/AddLibraryToMyTemplatesFlowTest.php`
