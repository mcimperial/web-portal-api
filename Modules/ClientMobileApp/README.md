# Mobile API authentication

Mobile accounts use the `mobile_users` table in the application's default
`mysql` connection (`llibi_web_portal_3`). The existing `users` table is not
used by the mobile login, registration, social login, or API authorization
flows. Sanctum bearer tokens resolve to `MobileUser`; protected mobile routes
use the dedicated `auth:mobile` guard and also require the `mobile.user`
middleware.

Create/update mobile-owned tables with:

```sh
php artisan migrate \
  --database=mysql \
  --path=Modules/ClientMobileApp/Database/migrations
```

The former `ClientMobileData` controllers, models, services, routes, and
migrations are now part of `ClientMobileApp`. Existing migration filenames,
mobile API URLs and route names are unchanged, so previously applied migrations
are not rerun. The `clientmobiledata` configuration key remains supported for
existing published settings; its defaults are in `config/mobile-data.php`.
Update deployment scripts that reference the former module or migration path.

These migrations target the application's `mysql` connection
(`llibi_web_portal_3`) only. The mobile API uses existing tables on
`mysql_sync` and `mysql_masterlist` without adding or altering their schema.
Each mobile account has a unique UUID used as its public Account ID; the
numeric primary key remains internal to mobile authentication and relations.
In `system` mode, mobile-only LOA details are stored in the
`mobile_loa_request_details` table on `mysql`. The consultation and laboratory request forms mirror the client
portal fields; they do not resubmit the mobile account email. Alternate email
and contact number are optional. Contact numbers display as
`+63 000-000-0000` and are submitted as local digits (for example,
`09989829829`). An optional number entered during sign-up is stored with the
mobile account and prefilled in both LOA forms. In `system` mode laboratory
request attachments are stored on the private local disk and their path is
written to the existing `mysql_masterlist.app_portal_requests.loa_attachment` column. Consultation
complaints are searched from active `mysql_masterlist.app_portal_complaints`
records without modifying that table. Mobile users may also create shared
complaints in `mysql.mobile_complaints`; the app searches both sources. These
entries are visible to all mobile users and should not contain personal details.
Run the mobile-owned migrations above before deploying this functionality.
Both consultation and laboratory requests can also select an optional doctor
affiliated with the chosen hospital or clinic. Both ClientCare request types
use `hospital_id||hospital_name--doctor_id||doctor_name` for the provider;
when no doctor is selected, the suffix is `--0||--`. Laboratory requests send
the doctor's request as `files[]` multipart data.

LOA submissions can be routed using the `loa_request_backend` row in the
app-owned `mysql.mobile_configuration` key/value table. The migration seeds
this setting as `system` to preserve current behavior. Set it to `third_party`
to submit consultation and laboratory requests only through ClientCare. The API
does not create a second local portal row or require an ID in ClientCare's
response. Mobile-only details are saved in `mysql.mobile_loa_request_submissions`
but cannot be linked to the portal request without its ID, so they will not
appear in LOA details returned by the mobile API. The same row's
`api_url`, `api_header`, and `api_key` columns hold the ClientCare base URL and
credentials. The key is stored as plaintext and hidden from model serialization;
restrict database access accordingly. The migration that converts an existing
encrypted key to plaintext cannot be rolled back automatically.
The migration and seeder use `CLIENTCARE_API_BASE_URL` as the initial URL;
afterwards, change `api_url` in the database to update the endpoint. To seed the
header and an initial API key, supply
`CLIENTCARE_SEED_API_KEY` in the seeder process environment (not the cached
Laravel config or a committed file) and run:

```sh
php artisan db:seed --class='Modules\ClientMobileApp\Database\Seeders\ClientMobileAppDatabaseSeeder'
```

The web portal's Mobile Administration page is available only to active portal
users with the `admin` role. It manages startup popups and their ordered
messages, Terms of Service and Privacy Policy wording, the LOA backend and
credentials, mobile accounts, and a catalog of
registered mobile endpoints. An existing API key is never returned to the
browser; leave the key field blank to retain it. Deactivating or deleting an
account revokes its mobile tokens, and changing its email clears email
verification. Deleting a mobile account is permanent. Apply the popup migration
and the message title migration before using the popup administration or the
public popup endpoint. Each popup message may have its own title; a blank
message title uses the current popup title in the mobile API response.

Terms of Service and Privacy Policy content is stored in
`mobile_legal_documents` on the app-owned `mysql` connection. Administrators can
read both records with `GET /api/v1/mobile-admin/legal-documents` and update
`terms` or `privacy` with `PUT /api/v1/mobile-admin/legal-documents/{key}`.
Every update increments the document version. The mobile app reads active
documents from `GET /api/v1/mobile/legal-documents` and records the accepted
terms and privacy versions when a traditional account is created.

Password recovery uses a random one-time token. Its SHA-256 hash is stored with
the verified OTP on the app-owned `mysql` connection, so reset sessions do not
depend on a specific API worker's encryption key. Apply
`2026_11_24_130000_add_reset_token_to_registration_otps_table.php` before
deploying the reset endpoint update.

### Callback requests

Authenticated active mobile accounts can `POST /api/v1/mobile/callback-requests`
and view their own paginated history with `GET /api/v1/mobile/callback-requests`.
Member activation is not required. Apply
`2026_11_26_000000_create_mobile_callback_requests_table.php` before using these
endpoints; the history table is on the app-owned `mysql` connection only.

The POST accepts required `email`, `mobile` (11-digit `09...`), and `request`
(up to 2,000 characters), plus optional `landline`, `alt_email`, `alt_landline`,
and `alt_mobile`. Contacts may be edited for this request without modifying the
account. The requestor name comes from the authenticated mobile account.
A UUID `Idempotency-Key` header is required; retries of an unchanged submission
must reuse it to prevent duplicate provider calls.

Callback submissions always use the existing `loa_request_backend` row's
`api_header` and `api_key`, independently of the LOA backend selection and URL.
`CLIENTCARE_CALLBACK_URL` defaults to
`https://provider-api.llibi.app/api/corporate/callback`. The backend adds
`platform: "mobile"`, `user.provider_id: null`, and `user.keyword` containing
the account's full name, with the request and alternate contacts under `data`.
No provider credentials are exposed to the mobile app.

An HTTP 2xx response counts as submitted unless it explicitly reports
`success: false`, a failed/error/rejected `status`, or an Unauthorized message.
Submitted means sent to the provider, not that a callback has been completed.
Rejected requests are recorded as `failed`; timeouts and provider server errors
are recorded as `unconfirmed` and must not be automatically resubmitted.
History also includes `processing` requests, and is ordered newest first.

The seeder leaves an existing backend selection, URL, header, and key unchanged. It
warns if no key exists or is supplied. For example, to switch the backend:

```sql
UPDATE mobile_configuration
SET config_value = 'third_party', updated_at = NOW()
WHERE config_key = 'loa_request_backend';
```

Missing ClientCare configuration is reported before submitting the request. If
ClientCare does not confirm the submission, the API instructs the user not to
resubmit. An `Unauthorized` response is treated as an authentication failure
even when ClientCare returns HTTP 200.

LOA API submissions require an `Idempotency-Key` UUID header. The app reuses
the same key when retrying an unchanged submission; the API records keys and
request results in the app-owned `mysql.mobile_loa_request_submissions` table,
so replaying a request does not submit another LOA. In `third_party` mode the
response's `request` is null, including on replay. Apply the mobile-owned
migrations before deploying the updated API.

Member-specific data is linked to the mobile account through
`POST /api/v1/mobile/member/link`, which verifies the member ID and birth date
against `mysql_sync.masterlist`. The mobile app links an activated member
after login or account registration. Accounts without a linked `member_id`
cannot resolve member-specific data; revoking an activation clears the link and
all mobile tokens.

The insurer LOA monitor (`app_loa_monitor`) is not exposed to mobile accounts
until its insurer-side `inscode` can be safely mapped to a masterlist member;
company-wide results would expose other members' medical data.
