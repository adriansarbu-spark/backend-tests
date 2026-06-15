# Referrals API testing strategy

## Endpoints

| Method | Path | Controller |
|--------|------|------------|
| GET | `/publicapi/v1/referrals` | `referrals.php` |
| GET | `/publicapi/v1/referrals/validate` | `referrals/validate.php` (anonymous) |
| GET | `/publicapi/v1/referrals/status` | `referrals/status.php` |
| GET | `/publicapi/v1/referrals/campaign` | `referrals/campaign.php` (anonymous) |
| GET/POST | `/publicapi/v1/referrals/invite` | `referrals/invite.php` |
| POST | `/publicapi/v1/referrals/invite/send` | `referrals/invite/send.php` |
| POST | `/publicapi/v1/referrals/remind` | `referrals/remind.php` |

## Unit vs Feature

- **Unit** (`tests/Unit/Api/Referrals/`): auth, validation, model delegation, rate-limit mapping. No Mailgun/`EmailQueue` — success paths for send/remind are Feature-only.
  - Files: `ReferralsListApiTest`, `ReferralsValidateApiTest`, `ReferralsStatusApiTest`, `ReferralsCampaignApiTest`, `ReferralsInviteApiTest`, `ReferralsInviteSendApiTest`, `ReferralsRemindApiTest`.
- **Feature** (`tests/Feature/Api/Referrals/`): real HTTP via `ReferralsApiHelper` + `ApiAuthHelper`. Skip on `invite_email_queue_failed` / `referrals_remind_queue_failed` (same pattern as team invitations).
  - Files: `ReferralsGetAndAuthFlowTest`, `ReferralsListValidationFlowTest`, `ReferralsPublicEndpointsFlowTest`, `ReferralsInviteFlowTest`, `ReferralsRemindFlowTest`.

## Helpers

- `tests/Support/ReferralsApiHelper.php` — URLs, `joinedErrors`, config guard.
- `tests/Unit/Api/Referrals/_support/ReferralsTestDoubles.php` — registry, model stubs, testable controllers.

## Docblocks

Follow `tests/strategies/documents-testing-guidelines.md`: Prerequisites + numbered Steps on every `test()`.
