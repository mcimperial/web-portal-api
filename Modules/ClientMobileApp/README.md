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
  --path=Modules/ClientMobileApp/Database/migrations \
  --path=Modules/ClientMobileData/Database/migrations
```

These migrations target the application's `mysql` connection
(`llibi_web_portal_3`) only. The mobile API uses existing tables on
`mysql_sync` and `mysql_masterlist` without adding or altering their schema.
Each mobile account has a unique UUID used as its public Account ID; the
numeric primary key remains internal to mobile authentication and relations.
Mobile-only LOA details are stored in the `mobile_loa_request_details` table
on `mysql`. The consultation and laboratory request forms mirror the client
portal fields; they do not resubmit the mobile account email. Alternate email
and contact number are optional. Contact numbers display as
`+63 000-000-0000` and are submitted as local digits (for example,
`09989829829`). An optional number entered during sign-up is stored with the
mobile account and prefilled in both LOA forms. Laboratory request attachments
are stored on the private local disk and their path is written to the existing
`mysql_masterlist.app_portal_requests.loa_attachment` column. Consultation
complaints are searched from active `mysql_masterlist.app_portal_complaints`
records without modifying that table.
Both consultation and laboratory requests can also select an optional doctor
affiliated with the chosen hospital or clinic.

LOA submissions can be routed using the `loa_request_backend` row in the
app-owned `mysql.mobile_configuration` key/value table. The migration seeds
this setting as `system` to preserve current behavior. Set it to `third_party`
to also submit consultation and laboratory requests to ClientCare; the existing
portal write remains enabled. The same row's `api_url`, `api_header`, and
`api_key` columns hold the ClientCare base URL and credentials. The key is stored as plaintext and hidden from
model serialization; restrict database access accordingly. The migration that
converts an existing encrypted key to plaintext cannot be rolled back automatically.
The migration and seeder use `CLIENTCARE_API_BASE_URL` as the initial URL;
afterwards, change `api_url` in the database to update the endpoint. To seed the
header and an initial API key, supply
`CLIENTCARE_SEED_API_KEY` in the seeder process environment (not the cached
Laravel config or a committed file) and run:

```sh
php artisan db:seed --class='Modules\ClientMobileApp\Database\Seeders\ClientMobileAppDatabaseSeeder'
```

The seeder leaves an existing backend selection, URL, header, and key unchanged. It
warns if no key exists or is supplied. For example, to switch the backend:

```sql
UPDATE mobile_configuration
SET config_value = 'third_party', updated_at = NOW()
WHERE config_key = 'loa_request_backend';
```

Missing ClientCare configuration is reported before saving the request. If
ClientCare is unavailable after the portal write succeeds, the API reports
that the portal copy was saved and instructs the user not to resubmit. An
`Unauthorized` response is treated as an authentication failure even when
ClientCare returns HTTP 200.

Member-specific data is linked to the mobile account through
`POST /api/v1/mobile/member/link`, which verifies the member ID and birth date
against `mysql_sync.masterlist`. The mobile app links an activated member
after login or account registration.

The insurer LOA monitor (`app_loa_monitor`) is not exposed to mobile accounts
until its insurer-side `inscode` can be safely mapped to a masterlist member;
company-wide results would expose other members' medical data.
