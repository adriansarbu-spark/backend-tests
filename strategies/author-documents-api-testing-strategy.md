# Author documents API testing strategy

## Base path

`/publicapi/v1/esign/author-documents` — `ControllerPublicAPIV1EsignAuthorDocuments`

Permission path: `publicapi/v1/esign/author-documents`

## Campaign + send flow

1. **POST** create draft (`name`, `content_snapshot` with `.signature-block-item` + `data-signature-party-code`).
2. **PUT** parties (`signing_order` required) and optional fields.
3. **POST** `/{uuid}/campaigns` → `campaign_uuid`.
4. **POST** `/{uuid}/campaigns/{campaign_uuid}/data` → `rows[]` with `party_values` / `field_values`.
5. **POST** `/{uuid}/send` → creates signing documents (needs PDF service + billing).
6. **GET** `/{uuid}/signing-documents` — linked signing UUIDs after send.

## Unit vs Feature

- **Unit** (`tests/Unit/Api/AuthorDocuments/`): routing, validation guards, campaign/upload guards, send guards before PDF (`AuthorDocumentsIndexRoutingTest`, `CreateDocumentTest`, `SaveDocumentTest`, `CreateCampaignTest`, `UploadCampaignDataTest`, `SendDocumentGuardsTest`; stubs in `_support/AuthorDocumentsTestDoubles.php`).
- **Feature Phase A**: campaign create + data upload + outsider read (`AuthorDocumentsCampaignFlowTest.php`).
- **Feature Phase B**: full send + signing-documents list; skip on 503 PDF or 409 billing (`AuthorDocumentsSendFlowTest.php`).
- **Feature permissions**: unauthenticated create, outsider PUT, double-send (`AuthorDocumentsPermissionsFlowTest.php`).

## Helpers

- `tests/Support/AuthorDocumentsApiHelper.php` — URLs, `createDraftWithSignerParty`, infra skip helpers.
- Owner bearer (personal role): `AccountCompaniesApiHelper::bearerForUser1Personal()` or `AuthorDocumentsApiHelper::bearerWithCompanyAdmin()` (alias).

## Content fixture

Use `AuthorDocumentsApiHelper::SAMPLE_CONTENT_WITH_SIGNATURE` so send validation finds signature blocks.
