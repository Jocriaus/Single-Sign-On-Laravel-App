# BulSU Single Sign-On (Laravel)

The single sign-on (SSO) server for Bulacan State University apps, written in Laravel 12. People sign in once
here; every BulSU app sends them here to sign in and gets them back already signed in. The server also decides
who may use which app.

It is the Laravel version of the Spring Boot server in the `Single-Sign-On-1` repository. Both use the same
addresses, claims and token lifetimes, so an app moves from one to the other by changing only `SSO_ISSUER`. The two servers do not share a database. This one is built to the latest approved design:
identity-only accounts, four standard roles, and a "who can sign in" rule per app.

It is an OpenID Connect provider built on Laravel Passport 13. Pages are plain Blade with inline CSS; there is
no JavaScript build.

## Requirements

- PHP 8.2 and Composer.
- MariaDB (XAMPP's works). PostgreSQL should work by changing the `DB_` settings in `.env` (with
  `DB_CONNECTION=pgsql`), but needs `pdo_pgsql` switched on in `php.ini` and has not been tried yet.
- For the tests: `pdo_sqlite` (they run on an in-memory SQLite database).

## Running locally

1. Clone the repository and install the PHP packages:

   ```bash
   composer install
   ```

2. Copy `.env.example` to `.env` (Windows: `copy .env.example .env`) and create the application key:

   ```bash
   php artisan key:generate
   ```

   The defaults suit XAMPP: MariaDB on `127.0.0.1:3306`, user `root`, no password, database `sso_laravel`,
   and `SSO_ISSUER=http://localhost:8000`.
3. Create the database, for example:

   ```bash
   mysql -u root -e "CREATE DATABASE sso_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

   (on XAMPP for Windows the client is `C:\xampp\mysql\bin\mysql`).
4. Create the tables and the starting data (the user types, the four roles and the `admin` account):

   ```bash
   php artisan migrate --seed
   ```

5. Create the token signing key:

   ```bash
   php artisan sso:install
   ```

   It writes `storage/oauth-private.key` and `storage/oauth-public.key` and never overwrites an existing key.
   When `SSO_ADMIN_CLIENT_SECRET` is set it also creates the client for the API documentation page (see
   [The admin API](#the-admin-api)); otherwise it says "Admin console client not created:
   SSO_ADMIN_CLIENT_SECRET is not set." and that is fine.
6. Start the server:

   ```bash
   php artisan serve
   ```

   It runs at `http://localhost:8000`.
7. Sign in at `http://localhost:8000/login` as `admin` with the password `password123`. You must choose a new
   password before going on (10 to 128 characters, not a common password).
8. Open the admin console at `http://localhost:8000/admin` to register apps and manage accounts.

Run the tests with `php artisan test`. They need no database server.

## Accounts and roles

The SSO keeps identity only: one `users` table with the sign-in name, email, name (with the middle initial as
the person writes it), student number, employee number, account type (Admin, Employee or Student) and account
state. Personal records stay with the registrar and HR systems. A person can hold both a student and an
employee number.

People sign in with their **student or employee number**: when an account is added, the number that matches
its type becomes the sign-in name, unless the administrator types another. Admin-type accounts, such as the
built-in `admin`, always have a typed sign-in name. Sign-in names are compared without regard to letter case
and surrounding spaces.

Five wrong passwords in a row lock an account for 15 minutes. An administrator who resets a password gets a
14-character temporary password, shown once, which the person must change at their next sign-in.

| Role | Given by | In the SSO |
|------|----------|------------|
| `ICTO_ADMIN` | An ICTO admin | Runs the SSO: apps, roles and every account |
| `ADMIN` | An ICTO admin | Manages ordinary accounts (people whose only roles are `STUDENT` and `EMPLOYEE`) |
| `STUDENT` | Automatic, from a student number | Nothing; apps receive it |
| `EMPLOYEE` | Automatic, from an employee number | Nothing; apps receive it |

`STUDENT` and `EMPLOYEE` follow the numbers on the account: add a number and the role appears, remove it and
the role goes. ICTO admins can create further roles (such as `HR_STAFF`) through the admin API
(`POST /api/v1/admin/role`) and assign them to people in the console.

### The two admin levels

An **ICTO admin** can do everything: register and change apps, assign roles, and manage every account,
including other administrators.

An **Admin** works only on ordinary accounts. In the console they can search, add, edit, unlock, reset the
password of, require a new password from, disable and enable people whose only roles are `STUDENT` and
`EMPLOYEE`. An Admin cannot:

- see or change apps,
- change anyone's roles,
- touch an account that holds any other role (another Admin, an ICTO admin, a custom role),
- create an Admin-type account: only ICTO admins create admin-type accounts.

Nobody can disable their own account or remove their own `ICTO_ADMIN` role, and at least one active ICTO admin
must always remain.

## Connecting an app

1. An ICTO admin registers the app in the console at `/admin/apps` (**Register app**) with:
   - its name (shown on the login page),
   - its redirect URIs (where the SSO sends people back after sign-in),
   - its sign-out return addresses (where people may land after signing out),
   - its single-logout address (where the SSO tells the app that someone signed out),
   - **who can sign in**: everyone with an active account, or only people with chosen roles,
   - **Require PKCE** (on by default; switch it off only for an app that cannot use it),
   - **May administer the SSO** (`sso.admin`): only for admin tools.

   The console then shows the client id and the client secret; the secret is shown only this once. It belongs
   in the app's environment, never in git.
2. The app uses a starter kit. The kits in the Spring Boot repository (`Single-Sign-On-1/starter-kits`: Laravel,
   Express, and a guide for React and Vue) work with this server unchanged: set their `SSO_ISSUER` to this
   server's address. See [starter-kits/README.md](starter-kits/README.md).

What an app gets, for an app not using a kit:

- OpenID Connect authorization code flow with PKCE (`S256`), required unless switched off for the app.
  Discovery is at `/.well-known/openid-configuration`, the signing keys at `/oauth2/jwks`.
- Every app is a confidential client. The secret goes in the `Authorization: Basic` header or in the request
  body (`client_secret_basic` or `client_secret_post`).
- Scopes: `openid` (always), `profile`, `email`; a new app gets `openid profile email`. `sso.admin` only for
  apps allowed to administer the SSO.
- Claims: `sub` (the user id as a string; never changes, even if the sign-in number does),
  `preferred_username` (the sign-in number), `name`, `email`, `roles`, `user_type` (Admin, Employee or
  Student), `sid` (the SSO session, for single logout). `/userinfo` also returns `user_id` and `user_profile`
  (`first_name`, `last_name`, `middle_name`, `middle_initial`, and `studentNo` / `employeeNo` when the person
  has them).
- Lifetimes: access token and ID token 15 minutes; refresh token 8 hours, replaced at every use; one app
  sign-in ends 8 hours after it started however often it is refreshed; authorization code 10 minutes. The SSO
  session ends after 120 minutes without activity.
- Roles and account state are read from the database every time a token is issued. A disabled or locked
  account, or one that must change its password, gets no tokens.

**Who can sign in.** People without an allowed role see "You don't have access to (app name)" at the SSO
(HTTP 403) and never reach the app. The rule is checked again at every token refresh.

**Single logout.** Signing out of any app, or on the SSO home page, ends the SSO session in that browser and
tells every app opened from that browser (OpenID Connect back-channel logout, for apps that registered a
single-logout address). The same person in another browser stays signed in. Changing someone's sign-in number
or disabling their account signs them out of every app in every browser.

## The admin API

A JSON API under `/api/v1`, called with an access token from this server (`Authorization: Bearer ...`):

| Path | Who |
|------|-----|
| `GET /api/v1/user/me` | Any valid token |
| `/api/v1/admin/user/...` | `ICTO_ADMIN` or `ADMIN` |
| `/api/v1/client/...`, `/api/v1/admin/role/...`, `/api/v1/admin/logs` | `ICTO_ADMIN` |

The admin paths also need the `sso.admin` scope on the token, so a token from an ordinary app cannot
administer the SSO. Roles are read from the database on every call: a removed role stops working at once.
Answers use the envelope `success`, `message`, `data`, `timestamp`, `status`, `errorCode`, `error`. Every call
to `/api` is recorded in `api_audit_logs` with passwords, secrets and tokens masked, along with the calling
user and app.

**Documentation.** The API is described at `/docs/api` (the OpenAPI document is at `/docs/api.json`). Both are
only for someone signed in to the SSO as an `ICTO_ADMIN` or `ADMIN`. The viewer (Scalar) loads from
cdn.jsdelivr.net, so the browser needs internet access.

**The first admin client.** To call the API from the documentation page, set `SSO_ADMIN_CLIENT_SECRET` in
`.env` to a random value of at least 24 characters, then run `php artisan sso:install`. It creates the client
`sso-admin-console` (scopes `openid profile email sso.admin`, redirect URI `{SSO_ISSUER}/docs/api`, only
`ICTO_ADMIN` and `ADMIN` may sign in, PKCE required). An existing client is never changed; give it a new
secret in the console instead.

Signing in from the documentation page (client id `sso-admin-console` and that secret) **has not been tested
in a browser yet**: it is not known whether the viewer sends PKCE or which return address it uses. If it does
not work, paste an access token that has the `sso.admin` scope into the viewer instead, or call the API
directly with `Authorization: Bearer`.

## Operations

- **Health:** `GET /actuator/health` answers `{"status":"UP"}`, or `{"status":"DOWN"}` with HTTP 503 when
  the database cannot be reached. It is public and shows nothing else. Laravel's own `/up` also works.
- **Nightly cleanup:** `php artisan sso:cleanup` deletes expired authorization codes, expired access and
  refresh tokens, app sign-ins older than 8 hours that have no code or token left, and API audit rows older
  than `SSO_API_AUDIT_RETENTION_DAYS` (365 by default; 0 keeps them forever). The activity log (sign-ins,
  failures, lockouts, tokens issued, admin actions, refused app access) is never deleted. The scheduler runs
  the cleanup daily at 02:30; for that, add this cron line on the server:

  ```
  * * * * * php /path/to/artisan schedule:run
  ```

  (On Windows, a Task Scheduler task that runs `php artisan schedule:run` every minute does the same.)
- **Rate limits,** per client address per minute: `POST /login` 30 (`SSO_RATE_LIMIT_LOGIN`) and
  `POST /oauth2/token` 600 (`SSO_RATE_LIMIT_TOKEN`). Above that the server answers 429 with `Retry-After`
  and "Too many attempts from your network. Wait a minute and try again." Successful sign-ins count too, and
  everyone behind one shared address (a campus NAT) shares one limit; raise the settings if that is a problem.
  The account lockout (5 wrong passwords, 15 minutes) is separate.
- **Logs:** `storage/logs/laravel.log`, including a warning for every single-logout notice an app did not
  accept.

## Production checklist

- `APP_ENV=production` and `APP_DEBUG=false`.
- `SSO_ISSUER` is the public `https://` address of this server, exactly as apps reach it. It is written into
  every token and apps check it, so choose it once. Set `APP_URL` to the same address.
- `SESSION_SECURE_COOKIE=true`, so the SSO session cookie only travels over HTTPS.
- The signing key: either the files `storage/oauth-private.key` and `storage/oauth-public.key` created by
  `php artisan sso:install`, or their contents in `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`. Keep the
  private key secret and backed up; every app trusts tokens signed with it.
- `SSO_TRUSTED_PROXIES` when the server is behind a reverse proxy or load balancer: its addresses (comma
  separated, or `*`). Without it the server sees every visitor as the proxy's address, so everyone shares one
  rate limit and the audit logs show only the proxy.
- `SSO_ADMIN_CLIENT_SECRET` is either empty or a random value of at least 24 characters.
- On each deploy: `php artisan migrate --force`; on the first deploy also `php artisan db:seed --force` and
  `php artisan sso:install`, then sign in as `admin` straight away to replace `password123`.
- `php artisan sso:check` must print "Production settings look safe." Otherwise it lists each unsafe setting
  and exits with an error; run it in the deploy script.
- `php artisan optimize` to cache configuration and routes. Run it again after every change to `.env`.
- The cron line above, for the nightly cleanup.
- Prefer PHP-FPM (see [Known limits](#known-limits)).

## Known limits

- **Single-logout notices can make the browser wait.** They are sent after the response only under PHP-FPM.
  Under Apache mod_php or `php artisan serve`, the browser that is signing out waits until every app has
  answered, up to about 8 seconds for each app that does not answer (3 seconds to connect, 5 in all). A failed
  notice is not retried; it is logged as a warning.
- **`php artisan serve` on Windows handles one request at a time.** An app that fetches this server's signing
  keys while it is handling a logout notice waits for this server, which is still waiting for the app, and the
  notice times out. This was seen in development with the Express kit, which fetches the keys only when its
  first logout notice arrives; once it had the keys, notices worked. Real deployments serve requests in
  parallel and are not affected.
- **Authorization codes last 10 minutes** (the Spring Boot server uses 5). Passport fixes this value.
- **Signing in from the API documentation page** has not been tested in a browser (see
  [The admin API](#the-admin-api)).
- **PostgreSQL** has not been tried; development uses MariaDB and the tests use SQLite.
- As in the Spring Boot server: no two-factor sign-in, password recovery, self-registration, bulk import, apps
  without a client secret, or screens for managing roles.

## Differences from the Spring Boot server

| Spring Boot server | This server | Why |
|--------------------|-------------|-----|
| SSO sessions in memory | Sessions in the database (`SESSION_DRIVER=database`) | Laravel's default; a restart no longer signs everyone out, and several instances work without sticky sessions |
| Rate-limit counters in memory | Laravel's limiter, kept in the cache (the database by default) | Same reason |
| `sid` is a hash of the session id | `sid` is a random value kept in the session | Laravel changes the session id at sign-in |
| springdoc and Swagger UI, public | Scramble with the Scalar viewer at `/docs/api`, signed-in administrators only | The owner's choice of tool; the API map of an SSO need not be public |
| Admin client created at start-up | Created by `php artisan sso:install` | PHP has no single start-up |
| Refuses to start with unsafe settings | `php artisan sso:check`, run at deploy | Same reason |
| `api_audit_logs.user_id` / `client_id` left empty | Filled from the token | A fix the Spring Boot server had deferred |
| Authorization codes last 5 minutes | 10 minutes | Fixed inside Passport |
| Admin API reads roles from the token | Reads roles from the database on every call | A removed role stops working at once, not after 15 minutes |
| Flyway SQL, PostgreSQL only | Laravel migrations with portable column types; MariaDB in development, SQLite for tests | Portability (PostgreSQL not yet tried) |

## OpenID conformance

Work to pass the OpenID Foundation's conformance tests is in progress (Milestone 5) and will be documented in
`docs/conformance.md`.
