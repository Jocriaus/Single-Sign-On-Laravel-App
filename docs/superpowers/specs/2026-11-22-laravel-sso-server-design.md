# BulSU SSO server in Laravel — Design

Date: 2026-11-22 · Source: `../Single-Sign-On-1` (Spring Boot, branch `feature/identity-and-app-access`)

Status: **draft.** The owner has decided four things: build to the latest approved design, document the API
with Scramble, use MariaDB, and build the OpenID Connect layer to pass the OpenID Foundation conformance suite. Every decision marked *(builder's call)* was made without asking and is open to correction.

## Intent

`Single-Sign-On-1` is BulSU's single sign-on server, written in Spring Boot. This project is the same server
written in Laravel: the one place where people sign in, to which every BulSU app redirects, and which decides
who may use which app. An app that works against the Spring Boot server must work against this one by changing
only the server address (`SSO_ISSUER`).

Why a Laravel version was wanted has not been stated. *(Builder's assumption: so a PHP/Laravel team can run and
maintain the SSO on the stack it already uses.)*

## What the source app is (evaluation)

An OpenID Connect provider on Spring Boot 3.5 / Java 21 / Spring Authorization Server 1.5 / PostgreSQL / Flyway
/ Thymeleaf: about 5,900 lines of Java in 120 files, 127 tests, built in three reviewed phases.

| Area | What it does |
|------|--------------|
| Sign-in | One login page on the SSO. Authorization code flow with PKCE, confidential clients only (`client_secret_basic` and `client_secret_post`), no consent screen. Silent sign-in to further apps while the SSO session lives (2 hours idle). |
| OpenID Connect | Discovery, RS256 ID tokens, `/userinfo`, RP-initiated logout at `/connect/logout`, JWKS at `/oauth2/jwks`. |
| Tokens | Access and ID token 15 minutes; refresh token 8 hours, rotated on use; one app sign-in ends 8 hours after it started whatever was refreshed. Roles and account state are re-read from the database on every issue. |
| Accounts | BCrypt passwords, 5 failures lock for 15 minutes, lock and disabled messages only after a correct password, forced password change, NIST-style password policy (10–128 characters, blocklist). |
| Single logout | Back-channel logout: signing out anywhere signs the same browser session out of every app, by signed `logout_token`. |
| Administration | Admin console at `/admin` (apps, users) and a JSON admin API that needs both an admin role and the `sso.admin` scope. Activity log and API audit trail with secrets redacted. |
| Operations | Health check, nightly cleanup, rate limits on `/login` and `/oauth2/token`, a production settings check that refuses unsafe configuration. |
| Joining | Starter kits for Laravel and Express, a guide for React and Vue. |

**Strengths.** Standards-based, so apps use ordinary OIDC libraries. Security decisions are deliberate and
written down. Good test coverage of the real flow. Clear design documents.

**Gaps and state.**

1. The latest design (identity-only users, four roles, per-app access) is **approved but not built**. The code
   still has the `students` and `employees` tables with sensitive personal data, a single `ADMIN` role, and no
   per-app access rule.
2. SSO sessions and rate-limit counters live in server memory: a restart signs everyone out, and two instances
   need sticky sessions.
3. Leftovers: an empty `EmployeeController`, query and route constants for removed features, `.idea` and a
   literal `${project.build.directory}` folder in the repository, `api_audit_logs.user_id` / `client_id` never
   filled.
4. No MFA, self-service password recovery, bulk import, or role management screens (all declared out of scope).

## Goals

1. Same protocol surface as the source: same endpoint paths, same claims, same token lifetimes, same messages.
   The existing Laravel and Express starter kits sign in, refresh and sign out against it unchanged.
2. Built to the **latest approved design**, not the code as it stands: identity-only `users`, roles
   `ICTO_ADMIN` / `ADMIN` / `STUDENT` / `EMPLOYEE`, per-app allowed roles. *(Owner's decision.)*
3. Admin console and admin API with the two admin levels.
4. Single logout, health check, nightly cleanup, rate limits, production settings check.
5. Runs on this machine as it is: PHP 8.2.12 and XAMPP's MariaDB.
6. An automated test for every behaviour the source tests.
7. The OpenID Connect layer passes the OpenID Foundation conformance suite (section 7).

## Non-goals

- Migrating data from the Spring Boot database. Nothing is deployed and it holds no real records.
- Sharing one database between the two servers. Their token tables differ; each has its own.
- A JavaScript build. Pages are Blade with inline CSS, as the source's are Thymeleaf with inline CSS. The API
  documentation page is the one exception: its viewer loads from a CDN, as Scramble ships it.
- MFA, password recovery, self-registration, bulk import, public (secret-less) clients, role management
  screens: out of scope in the source too.

## Anti-goals

- An app has to change code to move between the two servers.
- Any app other than the SSO sees a password.
- A token from an ordinary app can administer the SSO.
- An Admin can raise their own power, or the last ICTO admin can be removed.
- Hand-written OAuth grant, PKCE or refresh-rotation logic where a maintained library does it.

## Constraints

- PHP 8.2.12. Laravel 13 needs PHP 8.3, so **Laravel 12**. Verified: Passport 13.12 and league/oauth2-server
  9.5 install on PHP 8.2 with illuminate 12.x.
- MariaDB from XAMPP for development (port 3306, running). PostgreSQL 18 is also running locally but PHP's
  `pdo_pgsql` is switched off in `php.ini`. Migrations use only portable column types so PostgreSQL works by
  changing `.env`. Tests run on in-memory SQLite. *(MariaDB: owner's decision.)*
- The Spring Boot server uses port 8080. This server uses `php artisan serve` on port **8000**;
  `SSO_ISSUER=http://localhost:8000`.

## Approaches considered

**The OAuth engine**

| Approach | Trade-off | Decision |
|----------|-----------|----------|
| Laravel Passport 13, with an OpenID Connect layer on top | Passport supplies clients with hashed secrets, authorization code + PKCE, refresh rotation, RS256 access tokens, revocation and an API guard, all maintained and familiar to Laravel teams. It has no OpenID Connect: ID tokens, discovery, JWKS, `/userinfo`, logout and `sid` are ours to add through its extension points (custom access token entity, response type, repositories). | **Chosen** *(builder's call)* |
| league/oauth2-server directly | Full control of the schema, but re-does the glue Passport already provides and loses the guard and client model. | Rejected |
| Hand-written provider | Closest fit to Spring's behaviour, but puts security-critical grant logic in our own code. | Rejected (anti-goal) |
| A community OpenID package over Passport | Less code, but a small third-party package would own the ID token for the whole university, and none offers `sid`, back-channel logout or the 8-hour cap. | Rejected |

**Which version of the source to mirror:** the latest design (chosen by the owner) or the code as built
(rejected: it would build and then delete two tables and a role model).

**API documentation:** Scramble (chosen by the owner) generates the OpenAPI document from the routes and
request classes, where the source uses springdoc and Swagger UI.

**Database:** MariaDB with portable migrations (chosen) or PostgreSQL to match the source (works, needs
`pdo_pgsql` enabled in `php.ini`).

## Design

### 1. Protocol surface (identical to the source)

| Path | Purpose |
|------|---------|
| `GET /.well-known/openid-configuration` | Discovery, including `backchannel_logout_supported` and `backchannel_logout_session_supported` |
| `GET /oauth2/jwks` | Public signing key, with `kid` |
| `GET /oauth2/authorize` | Start sign-in to an app |
| `POST /oauth2/token` | Code exchange and refresh |
| `GET, POST /userinfo` | Claims for an access token |
| `GET, POST /connect/logout` | RP-initiated logout |
| `GET, POST /login`, `POST /logout`, `GET /`, `GET, POST /change-password` | SSO pages |
| `/admin/**` | Admin console |
| `/api/v1/**` | JSON API |
| `GET /actuator/health` | `{"status":"UP"}`; Laravel's `/up` also stays |

Claims, unchanged: `sub` (user id as a string), `preferred_username`, `name`, `email`, `roles`, `user_type`,
`sid`; `/userinfo` adds `user_id` and `user_profile` (`first_name`, `last_name`, `middle_name`,
`middle_initial`, `studentNo`, `employeeNo`). Scopes: `openid` (always), `profile`, `email`, `sso.admin`.
Access tokens also carry `iss`, `aud`, `scope` and `scopes`. Every JWT header carries `kid`.

Lifetimes: access 15 min, ID 15 min, refresh 8 h rotated, one app sign-in at most 8 h, logout token 2 min,
SSO session 2 h idle. Authorization code: 10 min, which Passport fixes (the source uses 5).

Usernames are compared without regard to letter case and with surrounding spaces removed, on every database.
*(Builder's call: MariaDB compares that way by default and SQLite and PostgreSQL do not; the server must behave
the same on all three.)*

### 2. How Passport is extended

- **Routes.** `Passport::ignoreRoutes()`; our routes use the paths above. The token route points at Passport's
  `AccessTokenController`. The authorize route is our own controller, because there is no consent screen and
  access rules are checked there.
- **App sign-ins.** A table `sso_sign_ins` holds one row per app sign-in: user, client, `sid`, `nonce`,
  `auth_time`, `started_at`. Authorization codes and access tokens carry `sign_in_id`. This is what makes ID
  tokens on refresh, the 8-hour cap and single logout possible, since Passport links none of them.
- **Token issue.** Our `AccessTokenRepository` (bound over Passport's) runs every time an access token is
  issued, after the library has checked the client, code and PKCE. It finds the sign-in, then refuses with
  `invalid_grant` if the user is gone, disabled, locked or must change password, if the sign-in is older than
  8 hours, or if the app's access rule no longer admits the user.
- **Claims.** A custom access-token entity writes the claims in section 1. A custom response type adds
  `id_token` when `openid` was granted.
- **Clients.** `App\Models\Client` extends Passport's with `scopes`, `post_logout_redirect_uris`,
  `backchannel_logout_uri`, `allowed_roles`; it always skips consent. `oauth_clients.id` is a string so ids
  are portable.
- **Keys.** Passport's RSA key pair in `storage/` (or `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`),
  created by `php artisan sso:install`. `kid` is the key's RFC 7638 thumbprint.

### 3. Sessions

Laravel's database session driver, 120 minutes idle, `HttpOnly`, `SameSite=Lax`, `Secure` from
`SESSION_SECURE_COOKIE`. Sessions therefore survive restarts and work across instances, which the source lists
as not done. `sid` is a random value created at sign-in and kept in the session (Laravel changes the session id
at sign-in, so the id itself can't be used). The session also records the account id and username; if the
username no longer belongs to that id, the session ends.

### 4. Accounts, roles and per-app access

Exactly as `../Single-Sign-On-1/docs/superpowers/specs/2026-11-22-identity-and-app-access-design.md`, sections
2, 3, 5 and 6, which this document adopts by reference. In short: one `users` table with identity only; user
types Admin, Employee, Student; `STUDENT` and `EMPLOYEE` roles follow the numbers a person holds; ICTO admins
do everything; Admins manage only ordinary accounts; an app may list allowed roles, enforced at
`/oauth2/authorize` with a 403 page and again at every token issue; changing a sign-in number signs the person
out everywhere.

Seed: user types, the four roles, and an `admin` account (password `password123`, must change at first
sign-in, role `ICTO_ADMIN`).

### 5. Administration

- **Console** (`/admin`, session sign-in, roles `ICTO_ADMIN` or `ADMIN`): overview; apps (ICTO only: list,
  register, edit, new secret, remove, "who can sign in", "may administer the SSO"); users (search, add,
  details, unlock, reset password with a generated 14-character temporary password, require new password,
  disable, enable, roles).
- **API** (`/api/v1`, access token): `GET user/me` for any token; `admin/user/**` for `ICTO_ADMIN` or `ADMIN`;
  `client/**`, `admin/role/**`, `admin/logs` for `ICTO_ADMIN`; all admin routes also need scope `sso.admin`.
  Responses use the source's envelope: `success`, `message`, `data`, `timestamp`, `status`, `errorCode`,
  `error`.
- **Records.** `activity_logs` for sign-ins, failures, lockouts, token issue, admin actions and refused app
  access. `api_audit_logs` for every `/api/**` call with secrets masked; unlike the source, `user_id` and
  `client_id` are filled from the token.
- Secrets and temporary passwords are shown once, on the POST response, with `Cache-Control: no-store`.
- **API documentation** (`dedoc/scramble`): the page at `/docs/api` and the OpenAPI document at
  `/docs/api.json`, for signed-in `ICTO_ADMIN` and `ADMIN` only *(builder's call; the source's Swagger UI is
  public)*. The document declares an OAuth2 authorization-code scheme pointing at this server, and the page
  uses Scramble's Scalar viewer so an administrator can sign in from it and try calls, as in Swagger UI.
- **First admin client.** As in the source: when `SSO_ADMIN_CLIENT_SECRET` is set, `php artisan sso:install`
  creates the client `sso-admin-console` (scopes `openid profile email sso.admin`, redirect address
  `{issuer}/docs/api`, roles `ICTO_ADMIN` and `ADMIN`) unless it exists. An existing client is never changed.

### 6. Operations

- Rate limits per address per minute: `POST /login` 30, `POST /oauth2/token` 600; 429 with `Retry-After` and
  "Too many attempts from your network. Wait a minute and try again."
- `sso:cleanup`, scheduled daily at 02:30: deletes app sign-ins whose tokens have all expired, expired codes
  and tokens, and API audit rows older than `SSO_API_AUDIT_RETENTION_DAYS` (365; 0 keeps them).
- `sso:check`: in production, lists every unsafe setting (issuer not `https` or on localhost, insecure session
  cookie, `APP_DEBUG` on, an `SSO_ADMIN_CLIENT_SECRET` that is a placeholder or shorter than 24 characters) and
  exits non-zero. Run at deploy. *(Builder's call: a deploy-time command, not a
  refusal to boot, which in PHP would run on every request.)*
- Back-channel logout notifications are sent after the response, 3 s connect and 5 s total timeout, no retry,
  failures logged as warnings.

### 7. Conformance with the OpenID Foundation test suite *(owner's decision)*

The OpenID Connect layer is written to pass the OpenID Foundation's conformance suite, so that it is checked
by an outside test and not only by ours. Facts below were read from the suite's source and the Foundation's
pages on 2026-11-22.

**Plans to pass**

| Profile | Test plan | Where it can run |
|---------|-----------|------------------|
| Basic OP | `oidcc-basic-certification-test-plan` (38 modules) | Locally, in Docker |
| Config OP | `oidcc-config-certification-test-plan` | Only against HTTPS: it fails any endpoint that is not `https` |
| RP-Initiated Logout OP | `oidcc-rp-initiated-logout-certification-test-plan` | HTTPS only, same reason |
| Back-Channel Logout OP | `oidcc-backchannel-rp-initiated-logout-certification-test-plan` | HTTPS only |

A plan passes when no module is FAILED or INTERRUPTED; WARNING, REVIEW and SKIPPED are accepted.

**What this requires beyond sections 1 to 6**

- **PKCE per app.** Only one of the 38 Basic modules sends PKCE, so a server that demands it everywhere fails
  the plan. Each app gets a "Require PKCE" setting, on by default; it is switched off only for the suite's test
  clients. The source has the same per-client setting (`requireProofKey`).
- **Authorize parameters.** `prompt=none` returns `login_required` to the app when nobody is signed in;
  `prompt=login` and an exceeded `max_age` ask for the password again and give a later `auth_time`; `POST` to
  `/oauth2/authorize` works; `id_token_hint`, `login_hint`, `ui_locales`, `claims_locales`, `acr_values`,
  `display`, `claims` and unknown parameters are accepted and ignored; `request` and `request_uri` are refused
  with `request_not_supported` and `request_uri_not_supported`.
- **Code replay.** A reused code is refused with `invalid_grant`, and the tokens its first use produced are
  revoked.
- **Standard claims.** With `profile`: `given_name`, `family_name`, `middle_name`, `updated_at` beside the
  existing `name` and `preferred_username`. `email_verified` is not sent, because the SSO does not verify
  addresses; the suite reports that as a warning, which is accepted.
- **Discovery.** Adds `claims_supported`, `request_parameter_supported: false`,
  `request_uri_parameter_supported: false`, `claims_parameter_supported: false`.
- **User info.** Also accepts the token in a POST form field `access_token`.
- **Logout.** A `post_logout_redirect_uri` is followed only on an exact match with a registered address and a
  valid `id_token_hint`. A forged, altered or unsigned hint gets an error page and the session is left alone.
  A request with no usable redirect address signs the user out and shows the signed-out page.

**Certification itself is not part of the build.** It is self-certification: results must be published from
the Foundation's hosted suite, so the server has to be deployed at a public HTTPS address first, and the fee is
USD 700 for members or USD 3,500 for non-members per deployment. Running the suite is free. The owner decides
about submitting after deployment.

## Differences from the source, on purpose

| Source | Here | Why |
|--------|------|-----|
| Sessions in memory | Sessions in the database | Laravel's default; closes a known gap |
| Rate-limit counters in memory | Laravel's cache-backed limiter | Same reason |
| `sid` is a hash of the session id | `sid` is a random value kept in the session | Laravel regenerates session ids |
| springdoc and Swagger UI, public | Scramble at `/docs/api`, administrators only | Owner's choice of tool; the API map of an SSO need not be public |
| Bootstrap client created at start-up | Created by `sso:install` | PHP has no single start-up |
| Refuses to start on unsafe settings | `sso:check` at deploy | PHP has no single start-up |
| `api_audit_logs.user_id` / `client_id` empty | Filled | A fix the source had deferred |
| Authorization code lives 5 minutes | 10 minutes | Fixed inside Passport |
| Admin API reads roles from the token | Reads roles from the database | A removed role stops working at once, not after 15 minutes |
| Flyway SQL, PostgreSQL only | Laravel migrations, MariaDB / PostgreSQL / SQLite | Portability |

## Testing

Feature tests (PHPUnit, in-memory SQLite) mirroring the source's suites: login page, full authorization code
flow, token policy, user info, password change, rate limits, back-channel logout, per-app access, accounts and
roles, console access per admin level, admin API, audit redaction, cleanup, health, settings check, JWKS.
Then an end-to-end run with the source's Laravel and Express starter kits pointed at this server.

## Left to the builder

Blade layout and CSS (carry the source's look), class and method names not fixed by the plan, wording of
messages the source does not fix, and the order of work inside a task.

## Open questions for the owner

1. Is the purpose to replace the Spring Boot server, or to keep both?
