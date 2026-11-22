# BulSU SSO Server in Laravel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel OpenID Connect server that apps built for the Spring Boot BulSU SSO can use by changing only `SSO_ISSUER`.

**Architecture:** Laravel 12 with Passport 13 as the OAuth engine (clients, authorization code + PKCE, refresh rotation, RS256 access tokens, API guard). Our code adds the OpenID Connect layer through Passport's extension points: a custom access-token entity, a response type that adds `id_token`, and repositories that tie codes and tokens to an `sso_sign_ins` row. Pages are Blade with inline CSS; sessions are in the database.

**Tech Stack:** PHP 8.2.12, Laravel 12, laravel/passport 13.12 (league/oauth2-server 9.5), firebase/php-jwt 7, phpseclib 3 (both come with Passport), dedoc/scramble for API documentation, MariaDB (XAMPP) in development, in-memory SQLite in tests, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-22-laravel-sso-server-design.md`. It adopts by reference sections 2, 3, 5 and 6 of `../Single-Sign-On-1/docs/superpowers/specs/2026-11-22-identity-and-app-access-design.md`; read both. The Spring Boot source in `../Single-Sign-On-1/src` is the reference for any wording or behaviour not fixed here.

<!-- progress:begin -->
## Progress

Last updated: 2026-11-22 12:50. **18 of 22 tasks complete**, each built by one agent and reviewed by another.
Task 19 is being built. The owner has asked to stop after Task 20. Suite at Task 18: 297 tests passing
(1820 assertions). Work is on branch `feature/laravel-sso-server`.

| Task | Status | Commits |
|------|--------|---------|
| 1. Project, configuration and health check | Done, review clean | `fddf7ea` |
| 2. Users, roles and the seeded administrator | Done, review clean | `7cb582d` |
| 3. Login page, lockout and the signed-in page | Done, review clean | `a7f9517` |
| 4. Password policy and forced password change | Done, review clean | `1adf14c` |
| 5. Signing key, discovery and JWKS | Done after one fix round | `b5971b9`, `8b695f9` |
| 6. Apps (clients) | Done after one fix round | `615c670`, `7f50518` |
| 7. Sign in to an app (authorize, code exchange, ID token) | Done after one fix round | `d6e01e4`, `7c650a3` |
| 8. Token policy | Done, review clean | `07c0d1c` |
| 9. User info and the API's own tokens | Done, review clean | `8c9e6d9` |
| 10. Per-app access | Done, review clean | `d08218f` |
| 11. Signing out everywhere | Done, review clean | `5ec232e` |
| 12. Account rules and admin levels | Done after one fix round | `73be513`, `621f11e` |
| 13. Admin console: access, overview and apps | Done, review clean | `fd253ba` |
| 14. Admin console: users | Done, review clean | `8aa4f65` |
| 15. Admin API and the audit trail | Done after one fix round | `ee53ef5`, `12f947c` |
| 16. API documentation with Scramble | Done after one fix round | `f56ae99`, `4c24b49` |
| 17. Rate limits, cleanup and the settings check | Done after one fix round | `b687a7b`, `cb83275` |
| 18. Documentation and end-to-end check | Done, review clean | `96d7503` |
| 19. Authorize parameters and optional PKCE | Being built | |
| 20. Standard claims, discovery and user info | Next; the last task before the requested stop | |
| 21. Logout strictness | Not started | |
| 22. Run the conformance suite | Not started; needs the owner's go-ahead | |

### What remains

**Tasks still to build**

1. **Task 19** (in progress): `prompt`, `max_age`, POST to the authorize endpoint, request objects refused,
   PKCE as a per-app setting, tokens revoked when a code is reused.
2. **Task 20**: standard name claims (`given_name`, `family_name`, `middle_name`, `updated_at`), a fuller
   discovery document, access token accepted in a POST body at `/userinfo`. Also the README corrections listed
   below.
3. **Task 21**: stricter sign-out. A forged or altered `id_token_hint`, or an unregistered return address,
   gets an error page and the session is left alone. This replaces Task 11's rule and its test.
4. **Task 22**: run the OpenID Foundation conformance suite (Basic OP plan, 38 modules) locally in Docker, fix
   what fails, and write `docs/conformance.md`. The Config and both logout plans need HTTPS, so they wait for
   a deployed server.

**After the tasks**

5. One review of the whole branch on the most capable model, then one round of fixes for what it finds. It
   should triage the deferred points below.
6. Decide how the branch is merged (pull request or direct merge). The owner pushed the branch at Task 7;
   commits since then are local only.

**Needs the owner**

- **Go-ahead for Task 22.** It adds `127.0.0.1 localhost.emobix.co.uk` to the Windows `hosts` file
  (administrator prompt) and pulls and runs the conformance suite's Docker images.
- **Browser check of `/docs/api`.** Nobody has signed in from the documentation page. Steps: set
  `SSO_ADMIN_CLIENT_SECRET` to 32 random characters, run `php artisan sso:install` and `php artisan serve`,
  sign in as an ICTO admin, open `/docs/api`, choose the OAuth2 authorization-code option, enter client id
  `sso-admin-console` and the secret, tick all scopes, authorize, then send `GET /v1/user/me`. Two things may
  fail: the viewer may use a redirect address other than `{issuer}/docs/api`, and it may not send PKCE, which
  the admin client requires. The fallback is pasting an access token.
- **Confirm a decision made during the build:** only an ICTO admin can create an admin-type account or change
  an account to that type.
- **Deployment choices:** PHP-FPM or Apache mod_php (under mod_php the browser waits up to about 5 seconds per
  app that does not answer a logout notice); the value of `SSO_TRUSTED_PROXIES`.
- **PostgreSQL** has never been run. The migrations use portable types, but this is untested.
- **Certification** (USD 700 for members, USD 3,500 for non-members) is the owner's decision after deployment.

**README corrections handed to Task 20**

- "up to about 8 seconds" per unanswering app should be about 5 seconds.
- Production checklist: add `APP_KEY`; terminate TLS in front of the server; keep the signing key file out of
  git with restricted permissions; use a dedicated database account; sign in as `admin` and change its
  password before the server is exposed.
- Replace the internal wording "latest approved design" and "Milestone 5".

**Deferred points for the whole-branch review** (raised by reviewers; none blocked a task)

*Security hardening*

- The change-password form does not limit guesses of the current password.
- `AccountService` checks permission and then writes without a row lock. Two ICTO admins acting on each other
  in the same instant could slip past the one-at-a-time rules; a backstop check exists but is tested only
  directly.
- `/userinfo` returns Laravel's debug JSON on an unexpected error when `APP_DEBUG` is true.
- The documentation page's viewer script is pinned to a version but has no integrity hash.
- `state` on sign-out has no length limit; an app's logout address is written to the log as registered.
- Passport logs every rejected bearer token as an error with a stack trace.

*Robustness*

- The token endpoint decrypts the code or refresh token a second time (about 91 ms). The ids could be captured
  from the library's own hooks instead, which would also remove the expiry reset in `AccessTokenRepository`.
- A sign-in record is created before its code is issued; abandoned ones are removed by cleanup after 8 hours.
- Logout notices are not undone if a later step in the same request fails.
- `refuse()` in `AuthorizeController` does not log the library's error.
- The seeder fails if the `admin` account was soft-deleted.
- A failed confirm checkbox in the console redirects to the previous page, not the user's page.
- Console and API search does not escape `%` and `_`.
- The rate limiter counts successful sign-ins too; a campus network with more than 30 sign-ins a minute from
  one address would be refused.

*Tests worth adding*

- An expired lock no longer blocks a refresh; the new refresh token's expiry after a rotation.
- `/userinfo` with `openid email` only and with `openid profile` only; locked and must-change-password
  accounts refused at `/userinfo` and the API.
- A user with no roles against an app with no rule; refusal by an access rule on the code exchange.
- A sign-out hint for one app with a return address registered only for another.
- The overview page's exact counts and its 20-row limit.
- Cleanup batching (the current test would pass without batching).

**Differences from the plan text, decided during the build**

- phpseclib is version 4 (`phpseclib4\...`), not 3.
- `sso_sign_ins.auth_time` and `started_at` are `dateTime` columns; MariaDB 10.4 refuses two required
  `timestamp` columns in one table.
- `SigningKey::decode` takes a second argument `bool $allowExpired = false` and rejects tokens without `exp`.
- Discovery, JWKS, the token endpoint and `/userinfo` run without session, cookie and CSRF middleware.
- Redirect addresses are refused if they contain credentials, whitespace or control characters.
- Client ids must match `[A-Za-z0-9-]{1,100}`; Passport's UUID-only check was relaxed to allow
  `sso-admin-console`.
- The API audit step runs for every request under `/api`, including addresses that match no route.
- Added `PUT /api/v1/admin/user/{id}/enable`, which the Spring Boot API lacks.
- Added the setting `SSO_TRUSTED_PROXIES`.
- Cleanup keeps any access-token record a live refresh token still depends on.
- Only ICTO admins may create or switch to admin-type accounts.
- The Scalar viewer's third-party proxy is switched off.
<!-- progress:end -->

## Global Constraints

- Laravel `^12.0` (PHP is 8.2.12; Laravel 13 needs 8.3). Do not add packages beyond `laravel/passport` and `dedoc/scramble`.
- Paths are fixed: `/.well-known/openid-configuration`, `/oauth2/jwks`, `/oauth2/authorize`, `/oauth2/token`, `/userinfo`, `/connect/logout`, `/login`, `/logout`, `/`, `/change-password`, `/admin/**`, `/api/v1/**`, `/actuator/health`.
- Lifetimes: access token 15 min, ID token 15 min, refresh token 8 h (rotated), app sign-in 8 h absolute, logout token 2 min, session 120 min idle.
- Lockout: 5 failed attempts, 15 minutes. Password: 10 to 128 characters.
- PKCE (S256) is required for every app unless its `require_pkce` setting is switched off, which is meant only for the conformance suite's test clients.
- Scopes: `openid`, `profile`, `email`, `sso.admin`. Default for a new app: `openid profile email`.
- Standard roles: `ICTO_ADMIN`, `ADMIN`, `STUDENT`, `EMPLOYEE`. User types: `Admin`, `Employee`, `Student`.
- `sub` is the user id as a string. Every JWT is RS256 with a `kid` header. `iss` is `config('sso.issuer')` exactly.
- Usernames: trimmed, compared case-insensitively (`LOWER(username) = ?`), on every database.
- Migrations use portable column types only (no `uuid`, `jsonb`, enum). JSON-like lists are stored as `text` with an array cast.
- No JavaScript build, no external assets. Blade views with one shared inline stylesheet partial. The Scramble page at `/docs/api` is the only exception.
- Secrets and temporary passwords appear once, on a POST response sent with `Cache-Control: no-store`.
- Every task ends with `php artisan test` fully green and one commit.
- Branch: `feature/laravel-sso-server`.

## Review Focus

Each line is pinned by a test in the task named.

1. **A `redirect_uri` that isn't registered for the app.** The SSO shows its own error page (HTTP 400) and never redirects to that address. Task 7.
2. **An authorization code used twice.** The second exchange fails with `invalid_grant`. Task 8.
3. **A username typed in different letter case or with spaces around it** (`" ADMIN "`). It signs in, counts toward the same lockout, and cannot be registered as a second account. Tasks 2 and 3.
4. **An app's back-channel logout address that is down or slow.** Sign-out still completes and redirects; the failure is only logged. Task 11.
5. **A `post_logout_redirect_uri` that isn't registered for the app.** The session still ends, and the browser lands on `/login?logout`, not on that address. Task 11.

## File Structure

```
config/sso.php                          issuer, lifetimes, lockout, rate limits, retention
app/Models/                             User, UserType, Role, Client, SignIn, ActivityLog, ApiAuditLog
app/Sso/Activity.php                    writes activity_logs
app/Sso/Accounts/                       Lockout, PasswordPolicy, AutomaticRoles, UserClaims, AccountService, TemporaryPassword
app/Sso/Apps/                           ClientRegistrar, AppAccess
app/Sso/OAuth/                          SigningKey, Scopes, SignInContext, SignInResolver, AccessToken,
                                        AccessTokenRepository, AuthCodeRepository, IdTokenFactory, IdTokenResponse
app/Sso/Logout/BackChannelLogout.php    ends app sign-ins, sends logout tokens
app/Sso/Api/                            ApiResponse (envelope), SensitiveDataMasker
app/Http/Controllers/Auth/              LoginController, PasswordChangeController, HomeController
app/Http/Controllers/OAuth/             DiscoveryController, AuthorizeController, UserInfoController, EndSessionController
app/Http/Controllers/Console/           OverviewController, AppsController, UsersController
app/Http/Controllers/Api/               MeController, AdminUserController, ClientController, RoleController, LogController
app/Http/Middleware/                    RequirePasswordChange, EnsureSessionOwner, RequireRole, RequireSsoAdmin, AuditApi
app/Console/Commands/                   SsoInstall, SsoCleanup, SsoCheck
resources/views/                        partials/style, login, home, change-password, no-access, oauth-error, admin/*
routes/web.php, routes/api.php, routes/console.php
tests/Support/OAuthFlow.php             drives the real flow for tests
tests/Feature/{Sso,Accounts,Console,Api,Ops}/
```

---

## Milestone 1: sign in to an app

### Task 1: Project, configuration and health check

**Files:**
- Create: the Laravel skeleton, `config/sso.php`, `tests/Feature/Ops/HealthCheckTest.php`
- Modify: `.env.example`, `phpunit.xml`, `config/session.php` (via env only), `config/auth.php`, `routes/web.php`, `tests/TestCase.php`

**Interfaces:**
- Produces: `config('sso.*')` keys below; `Tests\TestCase` sets Passport keys for every test; guard `api` uses driver `passport`.

- [x] **Step 1: Create the project in the existing folder** (it already holds `.git` and `docs/`)

```bash
cd /c/xampp/htdocs
composer create-project "laravel/laravel:^12.0" sso-laravel-tmp --no-interaction
cp -a sso-laravel-tmp/. Single-Sign-On-Laravel/ && rm -rf sso-laravel-tmp
cd Single-Sign-On-Laravel
composer require "laravel/passport:^13.12"
php artisan vendor:publish --tag=passport-migrations
git checkout -b feature/laravel-sso-server
```

- [x] **Step 2: Write the failing test** `tests/Feature/Ops/HealthCheckTest.php`

```php
public function test_health_check_is_public_and_shows_only_the_status(): void
{
    $this->getJson('/actuator/health')->assertOk()->assertExactJson(['status' => 'UP']);
}
public function test_health_check_reports_down_when_the_database_is_unreachable(): void
{
    $this->app->bind(\App\Sso\HealthProbe::class, fn () => new class extends \App\Sso\HealthProbe {
        public function databaseIsUp(): bool { return false; }
    });
    $this->getJson('/actuator/health')->assertStatus(503)->assertExactJson(['status' => 'DOWN']);
}
```

`App\Sso\HealthProbe::databaseIsUp(): bool` runs `DB::connection()->getPdo()` and returns false on any exception. (The `DB` facade itself is not mocked: `RefreshDatabase` needs it.)

- [x] **Step 3: Run** `php artisan test --filter=HealthCheckTest`. Expected: FAIL, 404.

- [x] **Step 4: Configure**

`config/sso.php` returns exactly these keys:

| Key | Value |
|-----|-------|
| `issuer` | `rtrim(env('SSO_ISSUER', 'http://localhost:8000'), '/')` |
| `access_token_minutes` | `15` |
| `id_token_minutes` | `15` |
| `refresh_token_hours` | `8` |
| `max_app_session_hours` | `8` |
| `logout_token_minutes` | `2` |
| `lockout.max_attempts` | `5` |
| `lockout.minutes` | `15` |
| `rate_limit.login_per_minute` | `(int) env('SSO_RATE_LIMIT_LOGIN', 30)` |
| `rate_limit.token_per_minute` | `(int) env('SSO_RATE_LIMIT_TOKEN', 600)` |
| `api_audit_retention_days` | `(int) env('SSO_API_AUDIT_RETENTION_DAYS', 365)` |

`.env.example`: `APP_NAME="BulSU SSO"`, `APP_URL=http://localhost:8000`, `SSO_ISSUER=http://localhost:8000`, `DB_CONNECTION=mariadb`, `DB_DATABASE=sso_laravel`, `DB_USERNAME=root`, `DB_PASSWORD=`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=120`, `SESSION_SECURE_COOKIE=false`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`. Copy to `.env`, run `php artisan key:generate`, create the database with `mysql -u root -e "CREATE DATABASE IF NOT EXISTS sso_laravel"`.

`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `SSO_ISSUER=http://localhost`.

`config/auth.php`: add guard `'api' => ['driver' => 'passport', 'provider' => 'users']`.

`tests/TestCase.php`: use `RefreshDatabase`; in `setUp`, set `config(['passport.private_key' => ..., 'passport.public_key' => ...])` from one RSA 2048 pair created once per process with `phpseclib3\Crypt\RSA::createKey(2048)` (kept in a static property). Do not call `openssl_pkey_new`; it fails on XAMPP without `OPENSSL_CONF`.

- [x] **Step 5: Implement** `app/Sso/HealthProbe.php` and `GET /actuator/health` in `routes/web.php`: `databaseIsUp()` true → 200 `{"status":"UP"}`; false → 503 `{"status":"DOWN"}`.

- [x] **Step 6: Run** `php artisan test`. Expected: PASS.

- [x] **Step 7: Commit** `git add -A && git commit -m "Laravel 12 skeleton, Passport, SSO settings and health check"`

### Task 2: Users, roles and the seeded administrator

**Files:**
- Create: `database/migrations/*_create_sso_account_tables.php`, `app/Models/{UserType,Role,ActivityLog,ApiAuditLog}.php`, `app/Sso/Accounts/AutomaticRoles.php`, `app/Sso/Activity.php`, `database/seeders/SsoSeeder.php`, `tests/Feature/Accounts/{SeedTest,AutomaticRolesTest}.php`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Produces:
  - `User` attributes: `username, email, password, user_type_id, status_flag, first_name, middle_name, middle_initial, last_name, suffix, student_no, employee_no, password_changed_at, failed_attempts, account_locked, locked_until, must_change_password`; soft deletes; uses `Laravel\Passport\HasApiTokens`.
  - `User::findByUsername(string $username): ?User` (trimmed, case-insensitive)
  - `User::roles(): BelongsToMany` (pivot `role_user`), `User::roleNames(): array` (upper case, distinct, sorted), `User::hasRole(string ...$names): bool`, `User::userType(): BelongsTo`
  - `User::hasAssignedRole(): bool` — true if the user holds any role other than `STUDENT` and `EMPLOYEE`
  - `Role::STANDARD = ['ICTO_ADMIN', 'ADMIN', 'STUDENT', 'EMPLOYEE']`, `Role::AUTOMATIC = ['STUDENT', 'EMPLOYEE']`; `Role` columns `role_name` (unique), `role_description`
  - `UserType` column `user_type_name`; constants `ADMIN = 'Admin'`, `EMPLOYEE = 'Employee'`, `STUDENT = 'Student'`
  - `AutomaticRoles::sync(User $user): void`
  - `Activity::log(?User $user, string $activity, string $type, ?string $module = null): void`; types `CREATE, UPDATE, DELETE, READ, AUTH`
  - `UserFactory` states: `student()`, `employee()`, `adminType()`, `withRole(string $name)`, `mustChangePassword()`, `disabled()`; default password `correct-horse-battery`.

- [x] **Step 1: Write the failing tests**

```php
// SeedTest
public function test_seed_creates_types_roles_and_an_admin_who_must_change_password(): void
{
    $this->seed();
    $this->assertSame(['Admin', 'Employee', 'Student'], UserType::orderBy('id')->pluck('user_type_name')->all());
    $this->assertEqualsCanonicalizing(Role::STANDARD, Role::pluck('role_name')->all());
    $admin = User::findByUsername('admin');
    $this->assertTrue(Hash::check('password123', $admin->password));
    $this->assertTrue($admin->must_change_password);
    $this->assertSame(['ICTO_ADMIN'], $admin->roleNames());
    $this->assertSame('Admin', $admin->userType->user_type_name);
}
public function test_usernames_are_found_whatever_the_letter_case_or_spaces(): void
{
    $this->seed();
    $this->assertNotNull(User::findByUsername('  ADMIN '));
}
// AutomaticRolesTest
public function test_roles_follow_the_numbers_a_person_holds(): void
{
    $this->seed();
    $user = User::factory()->student()->create(['employee_no' => null]);
    app(AutomaticRoles::class)->sync($user);
    $this->assertSame(['STUDENT'], $user->fresh()->roleNames());
    $user->update(['employee_no' => 'E-1']);
    app(AutomaticRoles::class)->sync($user);
    $this->assertSame(['EMPLOYEE', 'STUDENT'], $user->fresh()->roleNames());
    $user->update(['student_no' => null]);
    app(AutomaticRoles::class)->sync($user);
    $this->assertSame(['EMPLOYEE'], $user->fresh()->roleNames());
}
public function test_assigned_roles_are_left_alone(): void   // user with ADMIN + student_no keeps ADMIN, gains STUDENT
```

- [x] **Step 2: Run** `php artisan test --filter="SeedTest|AutomaticRolesTest"`. Expected: FAIL.

- [x] **Step 3: Write the migrations**

`users`: `id`, `username` string unique not null, `email` string unique, `password`, `user_type_id` foreign to `user_types`, `status_flag` boolean default true, `first_name`, `middle_name` nullable strings, `middle_initial` string(20) nullable, `last_name` nullable, `suffix` string(20) nullable, `student_no` string(50) nullable unique, `employee_no` string(50) nullable unique, `password_changed_at` timestamp nullable, `failed_attempts` unsigned int default 0, `account_locked` boolean default false, `locked_until` timestamp nullable, `must_change_password` boolean default false, `timestamps`, `softDeletes`. Remove `name`, `email_verified_at`, `remember_token`. Keep the skeleton's `sessions` table; drop `password_reset_tokens`.

Others: `user_types(id, user_type_name, user_type_description)`; `roles(id, role_name unique string(100), role_description text nullable, timestamps)`; `role_user(user_id, role_id, timestamps, unique[user_id, role_id])`; `activity_logs(id, user_id nullable, activity string, activity_type string(60), module nullable, created_at)`; `api_audit_logs(id, user_id nullable, client_id nullable string, endpoint, http_method string(10), ip_address string(50) nullable, user_agent nullable, status_code int, status string(20), error_code string(50) nullable, message text nullable, request_body text nullable, response_body text nullable, created_at indexed)`.

- [x] **Step 4: Implement** the models, `AutomaticRoles`, `Activity`, the factory states and `SsoSeeder` (called from `DatabaseSeeder`; idempotent with `firstOrCreate`). `User` stores `username` trimmed (mutator); a `saving` hook throws `ValidationException` with "This sign-in number is already used by another account." when another row has the same lower-cased username.

- [x] **Step 5: Run** `php artisan test`. Expected: PASS. Run `php artisan migrate:fresh --seed` against MariaDB. Expected: no errors.

- [x] **Step 6: Commit** `git commit -am "Identity-only users, four standard roles, seeded admin"`

### Task 3: Login page, lockout and the signed-in page

**Files:**
- Create: `app/Sso/Accounts/Lockout.php`, `app/Http/Controllers/Auth/{LoginController,HomeController}.php`, `resources/views/{partials/style,login,home}.blade.php`, `tests/Feature/Sso/LoginPageTest.php`
- Modify: `routes/web.php`, `bootstrap/app.php` (guests are redirected to `/login`)

**Interfaces:**
- Consumes: `User::findByUsername`, `Activity::log`.
- Produces:
  - `Lockout::isLocked(User): bool`, `Lockout::recordFailure(User): void`, `Lockout::clear(User): void`
  - Session keys written at sign-in: `sso.sid` (43-character base64url of 32 random bytes), `sso.user_id`, `sso.username`, `sso.auth_time` (unix seconds)
  - Routes named `login` (GET), `login.attempt` (POST), `logout` (POST), `home` (GET `/`)
  - The login view accepts `$appName` (nullable), `$error`, `$info`.

Copy the look and markup of `../Single-Sign-On-1/src/main/resources/templates/login.html` and `fragments/style.html`. The first field's label is "Student or employee number" with the hint "Administrators: use your admin username."

- [x] **Step 1: Write the failing tests** (each seeds, then posts to `/login` with `username`, `password`)

| Test | Asserts |
|------|---------|
| `test_correct_password_signs_in_and_goes_home` | redirect to `/`; `assertAuthenticatedAs`; session has `sso.sid` of length 43, `sso.user_id`, `sso.auth_time`; `failed_attempts` is 0; an `AUTH` activity row exists |
| `test_wrong_password_shows_the_generic_message` | redirect back to `/login`; following it shows "Wrong username or password."; `failed_attempts` is 1 |
| `test_unknown_username_shows_the_same_generic_message` | same text; no user row changed |
| `test_five_wrong_passwords_lock_the_account` | after 5: `account_locked` true, `locked_until` about now + 15 min; the 5th and later wrong attempts still show "Wrong username or password." |
| `test_locked_account_with_the_correct_password_is_told_it_is_locked` | "Too many failed attempts. Try again after 15 minutes."; not authenticated |
| `test_wrong_passwords_while_locked_do_not_extend_the_lock` | `locked_until` unchanged |
| `test_lock_ends_by_itself_after_fifteen_minutes` | `travel(16)->minutes()`; correct password signs in; `account_locked` false |
| `test_disabled_account_is_told_only_after_a_correct_password` | wrong password → generic; correct → "This account is disabled. Contact the system administrator." |
| `test_username_is_matched_whatever_the_case_or_spaces` | `" ADMIN "` signs in; wrong passwords under `"Admin"` and `"ADMIN"` count on the same account (Review Focus 3) |
| `test_home_redirects_guests_to_login_and_greets_signed_in_users` | guest → `/login`; signed in → 200 with "You're signed in as" and the display name |
| `test_sign_out_ends_the_session_and_says_so` | `POST /logout` → `/login?logout`; page shows "You've signed out."; guest |
| `test_after_sign_in_the_browser_returns_only_to_a_local_address` | intended URL `https://evil.example/` is ignored → `/` |

- [x] **Step 2: Run** `php artisan test --filter=LoginPageTest`. Expected: FAIL.

- [x] **Step 3: Implement.** Order inside `LoginController::attempt`: find the user; if missing, or the password is wrong, call `recordFailure` (when found and not already locked) and return the generic error; only then check locked, then disabled. On success: `Lockout::clear`, `Auth::login`, `session()->regenerate()`, write the four `sso.*` keys, log, `redirect()->intended('/')`. `LoginController::show` passes `$appName` from the client named in the intended `/oauth2/authorize` URL's `client_id`, when there is one.

- [x] **Step 4: Run** `php artisan test`. Expected: PASS.

- [x] **Step 5: Commit** `git commit -am "Login page with lockout and activity log"`

### Task 4: Password policy and forced password change

**Files:**
- Create: `app/Sso/Accounts/PasswordPolicy.php`, `app/Http/Controllers/Auth/PasswordChangeController.php`, `app/Http/Middleware/RequirePasswordChange.php`, `resources/views/change-password.blade.php`, `tests/Unit/PasswordPolicyTest.php`, `tests/Feature/Sso/PasswordChangeTest.php`
- Modify: `routes/web.php`, `bootstrap/app.php` (alias `password.changed`, appended to the `web` group)

**Interfaces:**
- Produces: `PasswordPolicy::problems(?string $password, ?string $username): array` (list of strings; empty means acceptable); `PasswordPolicy::MIN_LENGTH = 10`, `MAX_LENGTH = 128`; middleware alias `password.changed`.

Port `../Single-Sign-On-1/src/main/java/com/bulsu/single_sign_on/security/PasswordPolicy.java` exactly: same blocklist, same four messages, same order of checks.

- [x] **Step 1: Write the failing tests**

```php
// PasswordPolicyTest
$p = new PasswordPolicy;
$this->assertSame(['Use at least 10 characters.'], $p->problems('short', 'juan'));
$this->assertSame(['Use at most 128 characters.'], $p->problems(str_repeat('ab', 65), 'juan'));
$this->assertSame(["Don't use your username in your password."], $p->problems('xx-2021JUAN-xx', '2021juan'));
$this->assertSame(['This password is too common. Choose another one.'], $p->problems('BulSU12345', 'juan'));
$this->assertSame(['This password is too common. Choose another one.'], $p->problems('aaaaaaaaaaaa', 'juan'));
$this->assertSame([], $p->problems('correct-horse-battery', 'juan'));
```

| `PasswordChangeTest` | Asserts |
|------|---------|
| `test_user_who_must_change_password_is_kept_on_the_change_page` | signed in with the flag: `GET /` and `GET /admin` → redirect `/change-password` |
| `test_allowed_pages_stay_reachable` | `/change-password`, `POST /logout`, `/oauth2/jwks`, `/.well-known/openid-configuration` are not redirected |
| `test_wrong_current_password_is_refused` | error "Your current password is wrong."; password unchanged |
| `test_new_password_must_pass_the_policy_and_match_its_confirmation` | policy message shown; mismatch shows "The two new passwords don't match." |
| `test_new_password_must_differ_from_the_current_one` | "Choose a password you haven't just used." |
| `test_changing_the_password_clears_the_flag_and_continues_where_the_user_was_going` | flag false, `password_changed_at` set, activity row, redirect to the URL saved before the redirect |
| `test_anyone_signed_in_may_change_their_password` | no flag: `GET /change-password` is 200 |

- [x] **Step 2: Run** the two test classes. Expected: FAIL.
- [x] **Step 3: Implement.** The middleware applies to signed-in web requests only; it lets through paths starting with `/change-password`, `/login`, `/logout`, `/connect/logout`, `/.well-known/`, `/oauth2/jwks`, `/actuator/health`, `/up`; on GET it saves the URL as the intended URL before redirecting.
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Password policy and forced password change"`

### Task 5: Signing key, discovery and JWKS

**Files:**
- Create: `app/Sso/OAuth/{SigningKey,Scopes}.php`, `app/Http/Controllers/OAuth/DiscoveryController.php`, `app/Console/Commands/SsoInstall.php`, `tests/Feature/Sso/DiscoveryTest.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces:
  - `SigningKey::kid(): string` — RFC 7638 SHA-256 thumbprint of the public key, base64url
  - `SigningKey::jwk(): array` — `kty, alg: RS256, use: sig, kid, n, e`
  - `SigningKey::sign(array $claims, array $header = []): string` — RS256, always adds `kid`
  - `SigningKey::decode(string $jwt): array` — verifies signature and expiry with this server's public key
  - `Scopes::ALLOWED = ['openid','profile','email','sso.admin']`, `Scopes::DEFAULT = ['openid','profile','email']`, `Scopes::ADMIN = 'sso.admin'`
  - `php artisan sso:install` — runs `passport:keys` if no key exists, never overwrites.

Keys are read the way Passport reads them: `config('passport.private_key')` / `public_key` if set, else `storage/oauth-private.key` / `oauth-public.key`.

In `AppServiceProvider::boot`: `Passport::ignoreRoutes()` (in `register`), `Passport::tokensCan([...])` for the four scopes, `Passport::tokensExpireIn(15 minutes)`, `Passport::refreshTokensExpireIn(8 hours)`, both from `config('sso')`.

- [x] **Step 1: Write the failing tests**

```php
public function test_discovery_document_lists_this_servers_endpoints(): void
{
    $iss = config('sso.issuer');
    $this->getJson('/.well-known/openid-configuration')->assertOk()->assertJson([
        'issuer' => $iss,
        'authorization_endpoint' => "$iss/oauth2/authorize",
        'token_endpoint' => "$iss/oauth2/token",
        'userinfo_endpoint' => "$iss/userinfo",
        'jwks_uri' => "$iss/oauth2/jwks",
        'end_session_endpoint' => "$iss/connect/logout",
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        'scopes_supported' => ['openid', 'profile', 'email', 'sso.admin'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        'code_challenge_methods_supported' => ['S256'],
        'backchannel_logout_supported' => true,
        'backchannel_logout_session_supported' => true,
    ]);
}
public function test_jwks_publishes_the_key_that_signs_tokens(): void
{
    $key = app(SigningKey::class);
    $jwt = $key->sign(['iss' => 'x', 'exp' => time() + 60]);
    $jwks = $this->getJson('/oauth2/jwks')->assertOk()->json();
    $this->assertSame($key->kid(), $jwks['keys'][0]['kid']);
    $this->assertArrayNotHasKey('d', $jwks['keys'][0]);
    $decoded = \Firebase\JWT\JWT::decode($jwt, \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256'));
    $this->assertSame('x', $decoded->iss);
}
```

- [x] **Step 2: Run** `php artisan test --filter=DiscoveryTest`. Expected: FAIL.
- [x] **Step 3: Implement.** Derive `n` and `e` with `openssl_pkey_get_details(openssl_pkey_get_public($pem))['rsa']`.
- [x] **Step 4: Run** `php artisan test`, then `php artisan sso:install` twice. Expected: PASS; the second run reports the key already exists.
- [x] **Step 5: Commit** `git commit -am "Signing key, discovery document and JWKS"`

### Task 6: Apps (clients)

**Files:**
- Create: `app/Models/Client.php`, `app/Sso/Apps/ClientRegistrar.php`, `tests/Feature/Accounts/ClientRegistrarTest.php`
- Modify: the five published Passport migrations, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces:
  - `Client extends Laravel\Passport\Client`; casts `scopes`, `post_logout_redirect_uris`, `allowed_roles` as arrays; `backchannel_logout_uri` nullable string; `skipsAuthorization()` returns `true`.
  - `ClientRegistrar::register(array $data, ?string $id = null, ?string $secret = null): array` → `['client' => Client, 'secret' => string]` (a secret is generated when none is given)
  - `ClientRegistrar::update(Client $client, array $data): Client` (id and secret unchanged)
  - `ClientRegistrar::resetSecret(Client $client): string`
  - `ClientRegistrar::remove(Client $client): void` (deletes the client, its sign-ins, codes and tokens)
  - `$data` keys: `name`, `redirect_uris`, `post_logout_redirect_uris`, `scopes`, `backchannel_logout_uri`, `allowed_roles`, `require_pkce` (boolean, default true; cast as boolean on `Client`). List values may be arrays or strings separated by commas or spaces. Throws `ValidationException` keyed by those names.

Migrations: change `oauth_clients.id` to `string('id', 100)->primary()` and every `client_id` in the other Passport tables to `string('client_id', 100)->index()`. Add to `oauth_clients`: `scopes` text nullable, `post_logout_redirect_uris` text nullable, `backchannel_logout_uri` string nullable, `allowed_roles` text nullable, `require_pkce` boolean default true. Add `sign_in_id` string(36) nullable indexed to `oauth_auth_codes` and `oauth_access_tokens` (used in Task 7). `Passport::useClientModel(Client::class)`.

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_registering_an_app_returns_its_secret_once_and_stores_only_a_hash` | secret is 43+ characters; `Hash::check($secret, $client->secret)`; id is a UUID; `grant_types` is `['authorization_code','refresh_token']`; an activity row exists |
| `test_scopes_default_to_openid_profile_email` | `scopes` omitted → those three |
| `test_openid_is_always_added` | `scopes => 'email'` → `['openid','email']` |
| `test_unknown_scope_is_refused` | `ValidationException` on `scopes` |
| `test_lists_accept_commas_or_spaces` | `"https://a/cb, https://b/cb"` → two redirect URIs |
| `test_a_redirect_address_is_required_and_must_be_an_absolute_http_address_without_a_fragment` | empty, `ftp://x`, `https://a/cb#f` refused |
| `test_unknown_role_in_the_access_rule_is_refused` | `allowed_roles => 'NOPE'` → exception on `allowed_roles`; `'STUDENT, EMPLOYEE'` stored as two names |
| `test_empty_access_rule_means_everyone` | `allowed_roles` null |
| `test_updating_keeps_the_id_and_secret` | — |
| `test_a_new_secret_stops_the_old_one` | old fails `Hash::check`, new passes |
| `test_an_app_can_be_given_a_fixed_id_and_secret` | `register($data, 'sso-admin-console', 'given-secret')` → id as given; `Hash::check('given-secret', ...)` |

- [x] **Step 2: Run** `php artisan test --filter=ClientRegistrarTest`. Expected: FAIL.
- [x] **Step 3: Implement.** Secret: `Str::random(48)`. Assign `secret` in plain text; Passport's model hashes it.
- [x] **Step 4: Run** `php artisan test` and `php artisan migrate:fresh --seed`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "App registration with scopes, logout addresses and access rules"`

### Task 7: Sign in to an app (authorize, code exchange, ID token)

**Files:**
- Create: `database/migrations/*_create_sso_sign_ins_table.php`, `app/Models/SignIn.php`, `app/Sso/Accounts/UserClaims.php`, `app/Sso/OAuth/{SignInContext,SignInResolver,AccessToken,AccessTokenRepository,AuthCodeRepository,IdTokenFactory,IdTokenResponse}.php`, `app/Http/Controllers/OAuth/AuthorizeController.php`, `resources/views/oauth-error.blade.php`, `tests/Support/OAuthFlow.php`, `tests/Feature/Sso/SsoLoginFlowTest.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php` (`/oauth2/token` exempt from CSRF)

**Interfaces:**
- Consumes: `SigningKey`, `Client`, session keys from Task 3.
- Produces:
  - `sso_sign_ins(id string(36) primary, user_id, client_id string(100), sid string(64) indexed, nonce nullable, auth_time timestamp, started_at timestamp, timestamps)`; `SignIn` uses `HasUuids`; relations `user`, `client`.
  - `UserClaims::roles(User): array`; `displayName(User): string` (first + last name, trimmed; the username when both are empty); `userType(User): ?string`; `profile(User): array` with keys `first_name, last_name, middle_name, middle_initial, studentNo, employeeNo` (numbers omitted when null); `inactiveReason(User): ?string` returning, in this order, "This account is disabled.", "This account is locked.", "The user must change their password before signing in to apps.", or null.
  - `SignInContext` (container singleton): `set(?SignIn $signIn, bool $refresh = false): void`, `signIn(): ?SignIn`, `isRefresh(): bool`
  - `SignInResolver::fromTokenRequest(Illuminate\Http\Request $request): ?array` → `['signIn' => SignIn, 'refresh' => bool]`
  - `IdTokenFactory::make(User $user, string $clientId, SignIn $signIn, array $scopes, bool $withNonce): string`
  - `Tests\Support\OAuthFlow` trait: `registerApp(array $data = []): array`; `signInToSso(User $user): void`; `authorize(Client $client, array $params = []): TestResponse`; `exchange(Client $client, string $secret, string $code, string $verifier, string $method = 'post'): TestResponse`; `tokensFor(User $user, ?array $app = null, string $scope = 'openid profile email'): array` (drives authorize and exchange; returns the token JSON plus `client`, `secret`); `claims(string $jwt): array`.

How the pieces fit (all in one request each):

1. **`GET /oauth2/authorize`** (`auth` + `password.changed` middleware). Convert the request to PSR-7 and call `AuthorizationServer::validateAuthorizationRequest`. An unknown client or unregistered `redirect_uri` renders `oauth-error` with HTTP 400 and no redirect; any other `OAuthServerException` redirects to the app with `error` and `state` as the library builds it. If `scope` lacks `openid`, treat it as an error `invalid_scope`. Then create a `SignIn` (`sid`, `auth_time` from the session; `nonce` from the query; `started_at` now), `SignInContext::set($signIn)`, set the user and `setAuthorizationApproved(true)`, and return `completeAuthorizationRequest`.
2. **`AuthCodeRepository`** (extends Passport's Bridge class, bound over it in the container): after `parent::persistNewAuthCode`, writes `sign_in_id` from the context.
3. **`POST /oauth2/token`** → Passport's `AccessTokenController@issueToken`.
4. **`AccessTokenRepository::persistNewAccessToken`** (extends and is bound over Passport's): calls `SignInResolver::fromTokenRequest(request())`, sets the context (or clears it when null), calls the parent, then writes `sign_in_id`. Policy checks are added here in Task 8.
5. **`SignInResolver`**: for `grant_type=authorization_code`, decrypt `code` with `Defuse\Crypto\Crypto::decryptWithPassword($value, Passport::tokenEncryptionKey(app('encrypter')))`, read `auth_code_id`, load that code's `sign_in_id`. For `refresh_token`, decrypt `refresh_token`, read `access_token_id`, load that (revoked) token's `sign_in_id`. Any failure returns null.
6. **`AccessToken`** (set with `Passport::useAccessTokenEntity`): redefines `convertToJWT()` to add header `kid` and claims `iss`, `sub`, `aud`, `jti`, `iat`, `nbf`, `exp`, `scopes` (array, Passport's guard reads it), `scope` (space separated), `roles`, `user_type`, `preferred_username`. Logs activity "Access token issued to app {name}" (`CREATE`, module `OAuth2`).
7. **`IdTokenResponse`** (extends `League\OAuth2\Server\ResponseTypes\BearerTokenResponse`, set with `Passport::useAuthorizationServerResponseType`): `getExtraParams` returns `['id_token' => ...]` when the token's scopes include `openid` and the context has a sign-in; `withNonce` is `! isRefresh()`.
8. **ID token claims**: `iss`, `sub`, `aud` (client id), `iat`, `exp` (+15 min), `auth_time`, `sid`, `roles`, `user_type`; `nonce` when asked and present; with `profile`: `preferred_username`, `name`; with `email`: `email`.

- [x] **Step 1: Write `OAuthFlow`**, then the failing tests

```php
public function test_full_flow_browser_to_tokens(): void
{
    $this->seed();
    $app = $this->registerApp(['name' => 'Student Portal', 'redirect_uris' => 'https://portal.test/cb']);
    $user = User::factory()->student()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    app(AutomaticRoles::class)->sync($user);
    $verifier = Str::random(64);
    $query = ['nonce' => 'n-1', 'state' => 's-1', 'code_challenge' => $this->challenge($verifier)];

    $this->authorize($app['client'], $query)->assertRedirect('/login');            // no SSO session yet
    $this->get('/login')->assertSee('Student Portal');
    $this->post('/login', ['username' => $user->username, 'password' => 'correct-horse-battery'])
         ->assertRedirectContains('/oauth2/authorize');
    $back = $this->authorize($app['client'], $query);
    $back->assertRedirectContains('https://portal.test/cb?');
    parse_str(parse_url($back->headers->get('Location'), PHP_URL_QUERY), $q);
    $this->assertSame('s-1', $q['state']);

    $tokens = $this->exchange($app['client'], $app['secret'], $q['code'], $verifier)->assertOk()->json();
    $this->assertSame('Bearer', $tokens['token_type']);
    $this->assertSame(900, $tokens['expires_in']);
    $this->assertNotEmpty($tokens['refresh_token']);

    $id = $this->claims($tokens['id_token']);
    $this->assertSame(config('sso.issuer'), $id['iss']);
    $this->assertSame((string) $user->id, $id['sub']);
    $this->assertSame($app['client']->id, $id['aud']);
    $this->assertSame('n-1', $id['nonce']);
    $this->assertSame(session('sso.sid'), $id['sid']);
    $this->assertSame(900, $id['exp'] - $id['iat']);
    $this->assertSame($user->username, $id['preferred_username']);
    $this->assertSame('Juan Dela Cruz', $id['name']);
    $this->assertSame($user->email, $id['email']);
    $this->assertSame(['STUDENT'], $id['roles']);
    $this->assertSame('Student', $id['user_type']);

    $access = $this->claims($tokens['access_token']);
    $this->assertSame((string) $user->id, $access['sub']);
    $this->assertSame('openid profile email', $access['scope']);
    $this->assertSame($user->username, $access['preferred_username']);
    $this->assertArrayNotHasKey('email', $access);
}
```

| Test | Asserts |
|------|---------|
| `test_second_app_needs_no_password` | signed in to the SSO → authorize for another app redirects straight to that app with a code |
| `test_claims_follow_the_scopes_granted` | `scope=openid` → ID token has no `name`, `preferred_username`, `email` |
| `test_name_falls_back_to_the_username` | seeded `admin` → `name` is `admin` |
| `test_unregistered_redirect_address_gets_an_error_page_not_a_redirect` | HTTP 400, view `oauth-error`, no `Location` header (Review Focus 1) |
| `test_unknown_app_gets_an_error_page` | HTTP 400 |
| `test_scope_the_app_is_not_allowed_is_dropped` | app with `openid`, request `openid sso.admin` → access token `scope` is `openid` |
| `test_request_without_openid_scope_is_refused` | redirect to the app with `error=invalid_scope` |
| `test_request_without_pkce_is_refused` | redirect with `error=invalid_request` |
| `test_user_who_must_change_password_cannot_reach_an_app` | authorize → `/change-password` |
| `test_wrong_client_secret_is_refused` | 401 `invalid_client` |
| `test_wrong_code_verifier_is_refused` | 400 `invalid_grant` |
| `test_client_secret_basic_and_post_both_work` | `exchange(..., 'basic')` and `'post'` → 200 |
| `test_every_token_has_the_kid_of_the_published_key` | header `kid` of access and ID token equals `SigningKey::kid()` |

- [x] **Step 2: Run** `php artisan test --filter=SsoLoginFlowTest`. Expected: FAIL.
- [x] **Step 3: Implement** in the order of the list above. League's `AuthCodeGrant` accepts a request without `code_challenge` from a confidential client, so `AuthorizeController` rejects it before completing when the client's `require_pkce` is true (the default; Task 19 covers apps with it switched off).
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Authorization code flow with PKCE and ID tokens"`

### Task 8: Token policy (refresh, 8-hour cap, account state)

**Files:**
- Modify: `app/Sso/OAuth/AccessTokenRepository.php`
- Test: `tests/Feature/Sso/TokenPolicyTest.php`

**Interfaces:**
- Consumes: `SignInContext`, `UserClaims::inactiveReason`.
- Produces: `AccessTokenRepository::persistNewAccessToken` throws `League\OAuth2\Server\Exception\OAuthServerException::invalidGrant($hint)` before saving when: the sign-in can't be found; the user no longer exists ("This account no longer exists."); `inactiveReason` is not null (its text); on refresh, `started_at + 8 h` is past ("The sign-in has expired. Sign in again.").

- [x] **Step 1: Write the failing tests** (`refresh($app, $refreshToken)` posts `grant_type=refresh_token` with the client secret)

| Test | Asserts |
|------|---------|
| `test_refresh_returns_new_tokens_including_an_id_token_with_the_same_sid` | 200; new access, refresh and ID tokens; `sid` and `auth_time` equal the first ID token's; no `nonce` |
| `test_a_refresh_token_works_only_once` | second use → 400 `invalid_grant` |
| `test_an_authorization_code_works_only_once` | second exchange → 400 `invalid_grant` (Review Focus 2) |
| `test_refresh_stops_eight_hours_after_the_sign_in_started` | refresh at +4 h works; `travelTo(start + 8 h 1 min)` → 400 `invalid_grant`, hint "The sign-in has expired. Sign in again." |
| `test_refresh_picks_up_role_changes` | attach `ADMIN`, refresh → access token `roles` contains `ADMIN` |
| `test_disabled_user_cannot_refresh` | `status_flag` false → 400 `invalid_grant` |
| `test_locked_user_cannot_refresh` | — |
| `test_user_who_must_change_password_cannot_refresh` | — |
| `test_deleted_user_cannot_refresh` | soft-deleted → 400 `invalid_grant` |
| `test_refresh_token_expires_after_eight_hours` | `refresh_token` row `expires_at` is issue time + 8 h |

- [x] **Step 2: Run** `php artisan test --filter=TokenPolicyTest`. Expected: FAIL on the cap and account-state tests.
- [x] **Step 3: Implement** the checks.
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Token policy: rotation, absolute cap, account state at every issue"`

### Task 9: User info and the API's own tokens

**Files:**
- Create: `app/Http/Controllers/OAuth/UserInfoController.php`, `app/Http/Controllers/Api/MeController.php`, `app/Sso/Api/ApiResponse.php`, `tests/Feature/Sso/UserInfoTest.php`, `tests/Feature/Api/ApiAccessTest.php`
- Modify: `routes/web.php`, `routes/api.php`, `bootstrap/app.php` (API routes under `/api`, JSON 401/403/404/422/500 in the envelope; `/userinfo` exempt from CSRF)

**Interfaces:**
- Produces:
  - `UserClaims::forUserInfo(User $user, array $scopes): array` — always `sub` (string), `user_id` (int), `user_type`, `roles`; with `profile`: `preferred_username`, `name`, `user_profile`; with `email`: `email`.
  - `ApiResponse::success(string $message, mixed $data = null, int $status = 200): JsonResponse` and `ApiResponse::error(string $errorCode, string $message, int $status = 400, mixed $data = null): JsonResponse`, both producing `success, message, data, timestamp, status, errorCode, error`.
  - `GET|POST /userinfo` and `GET /api/v1/user/me`, both behind `auth:api`.

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_userinfo_returns_claims_for_the_scopes_granted` | full scopes: `sub` is a string, `user_id` an int, `user_profile` has `first_name`, `last_name`, `middle_name`, `middle_initial`, `studentNo`; with `scope=openid`: only `sub`, `user_id`, `user_type`, `roles` |
| `test_userinfo_shows_both_numbers_when_a_person_has_both` | `studentNo` and `employeeNo` present |
| `test_userinfo_without_a_token_is_401` | `WWW-Authenticate: Bearer` header |
| `test_userinfo_refuses_a_token_after_sign_out_revoked_it` | token row `revoked` → 401 |
| `test_me_returns_the_current_users_profile` | 200 envelope, `data.username`, `data.roles` |
| `test_api_without_a_token_is_401_json_in_the_envelope` | `success` false, `status` 401, `errorCode` `UNAUTHORIZED` |
| `test_an_id_token_is_not_accepted_as_an_access_token` | 401 |
| `test_unknown_api_address_is_404_json` | `errorCode` `NOT_FOUND`; body has no exception message |

- [x] **Step 2: Run** the two classes. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "User info endpoint and token-protected API"`

---

## Milestone 2: who may use which app, and signing out

### Task 10: Per-app access

**Files:**
- Create: `app/Sso/Apps/AppAccess.php`, `resources/views/no-access.blade.php`, `tests/Feature/Sso/AppAccessTest.php`
- Modify: `app/Http/Controllers/OAuth/AuthorizeController.php`, `app/Sso/OAuth/AccessTokenRepository.php`

**Interfaces:**
- Produces: `AppAccess::allows(Client $client, User $user): bool` — true when `allowed_roles` is empty, or the user holds any role in it.

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_app_without_a_rule_lets_every_active_account_in` | redirect to the app with a code |
| `test_holder_of_an_allowed_role_gets_in` | rule `['STUDENT','EMPLOYEE']`, a student → code |
| `test_others_see_the_no_access_page_and_the_app_gets_nothing` | rule `['EMPLOYEE']`, a student → HTTP 403; sees "You don't have access to {app name}", the display name, "If you need it, ask the app's administrator.", a Sign out button; no `Location` header; no row in `oauth_auth_codes` |
| `test_refusal_is_written_to_the_activity_log` | row naming the app |
| `test_rule_tightened_after_sign_in_fails_the_next_refresh` | 400 `invalid_grant` |
| `test_role_removed_after_sign_in_fails_the_next_refresh` | — |
| `test_the_admin_console_is_never_subject_to_app_rules` | an ICTO admin holding no role in any rule still opens `/` |

- [x] **Step 2: Run** `php artisan test --filter=AppAccessTest`. Expected: FAIL.
- [x] **Step 3: Implement.** In `AuthorizeController` the check comes after request validation and before the `SignIn` is created. In the repository it comes after the account-state checks; hint "This account does not have access to this app."
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Per-app access rules at sign-in and at every token issue"`

### Task 11: Signing out everywhere

**Files:**
- Create: `app/Sso/Logout/BackChannelLogout.php`, `app/Http/Controllers/OAuth/EndSessionController.php`, `app/Http/Middleware/EnsureSessionOwner.php`, `tests/Feature/Sso/BackChannelLogoutTest.php`
- Modify: `app/Http/Controllers/Auth/LoginController.php` (`logout`), `routes/web.php`, `bootstrap/app.php`

**Interfaces:**
- Consumes: `SignIn`, `SigningKey::sign`, `SigningKey::decode`, `Client`.
- Produces:
  - `BackChannelLogout::signOutSession(User $user, string $sid): void` — sign-ins of that user with that `sid`
  - `BackChannelLogout::signOutEverywhere(User $user): void` — every sign-in of that user
  - Both: revoke the sign-in's access tokens and their refresh tokens, revoke unused codes, delete the sign-in, and for each distinct app with a `backchannel_logout_uri` send one logout token per `sid`.
  - Middleware alias `session.owner` (appended to the `web` group): if the signed-in user's id or lower-cased username differs from `sso.user_id` / `sso.username`, or the account is disabled, end the session and redirect to `/login`.

Logout token: header `typ: logout+jwt` and `kid`; claims `iss`, `aud` (client id), `iat`, `exp` (+2 min), `jti` (UUID), `sub`, `sid`, `events: {"http://schemas.openid.net/event/backchannel-logout": {}}` (an empty JSON object, not an array); no `nonce`. Sent as `POST` form field `logout_token`, with `Http::connectTimeout(3)->timeout(5)`, inside `dispatch(...)->afterResponse()`; a status other than 200 or 204, or any exception, is logged with `Log::warning` and nothing else happens.

`GET|POST /connect/logout` accepts `id_token_hint`, `client_id`, `post_logout_redirect_uri`, `state`. Decode the hint with `SigningKey` ignoring expiry; the app is its `aud`. If there is an SSO session: `signOutSession`, then end the session. Redirect to `post_logout_redirect_uri` (with `state`) only if it is registered for that app; otherwise to `/login?logout`.

- [x] **Step 1: Write the failing tests** (use `Http::fake()`; `Http::assertSent` inspects the token with `claims()`)

| Test | Asserts |
|------|---------|
| `test_signing_out_through_an_app_notifies_the_other_apps_of_that_session` | two apps with logout addresses, one browser: each address receives one POST; token has `typ` `logout+jwt`, right `aud`, `sub`, `sid`, the event key, a `jti`, `exp - iat` = 120, no `nonce` |
| `test_the_logout_token_passes_the_starter_kits_checks` | decode with the JWKS endpoint's keys via `Firebase\JWT\JWK::parseKeySet`; `iss` equals the discovery `issuer` |
| `test_signing_out_on_the_sso_home_page_does_the_same` | `POST /logout` |
| `test_refresh_tokens_of_that_session_stop_working` | refresh → 400 `invalid_grant`; access token → 401 at `/userinfo` |
| `test_sign_ins_from_another_browser_session_are_left_alone` | a sign-in with a different `sid` still refreshes; its app is not notified |
| `test_apps_without_a_logout_address_are_not_called_but_lose_their_tokens` | — |
| `test_a_failing_app_does_not_stop_sign_out` | `Http::fake` returning 500 for one and throwing `ConnectionException` for another: response is still the redirect; a warning is logged (Review Focus 4) |
| `test_after_sign_out_the_next_authorize_asks_for_the_password_again` | authorize → `/login` |
| `test_registered_post_logout_address_is_followed_with_state` | redirect to it with `state` |
| `test_unregistered_post_logout_address_is_ignored` | redirect `/login?logout`; session ended (Review Focus 5) |
| `test_logout_without_an_sso_session_just_redirects` | no calls, no error |
| `test_a_logout_token_is_not_accepted_as_an_access_token` | `/userinfo` with it → 401 |
| `test_a_session_whose_username_now_belongs_to_someone_else_ends` | change the username in the database → next request redirects to `/login` |

- [x] **Step 2: Run** `php artisan test --filter=BackChannelLogoutTest`. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Verify by hand with the source's starter kit.** In `../Single-Sign-On-1/starter-kits/express`: set `SSO_ISSUER=http://localhost:8000` and the id and secret of an app registered with `php artisan tinker` through `ClientRegistrar`; run `php artisan serve` and the kit. Expected: sign in with the SSO password, see the claims, sign out, and the SSO asks for the password again. Note anything the kit rejects and fix it here before continuing.
- [x] **Step 6: Commit** `git commit -am "Single logout: RP-initiated and back-channel"`

---

## Milestone 3: administration

### Task 12: Account rules and admin levels

**Files:**
- Create: `app/Sso/Accounts/{AccountService,TemporaryPassword}.php`, `tests/Feature/Accounts/{AccountRulesTest,AdminLevelsTest}.php`

**Interfaces:**
- Consumes: `AutomaticRoles`, `BackChannelLogout::signOutEverywhere`, `Activity`, `PasswordPolicy`.
- Produces (`$actor` is the administrator acting; every method logs activity naming the actor; all throw `ValidationException` for bad data and `AuthorizationException` when the actor may not act):
  - `AccountService::create(User $actor, array $data): array` → `['user' => User, 'temporaryPassword' => string]`
  - `update(User $actor, User $user, array $data): User`
  - `unlock(User $actor, User $user): void`
  - `resetPassword(User $actor, User $user): string` (temporary password; sets `must_change_password`)
  - `requirePasswordChange(User $actor, User $user): void`
  - `setEnabled(User $actor, User $user, bool $enabled): void`
  - `setRoles(User $actor, User $user, array $roleNames): void` (assigned roles only)
  - `canManage(User $actor, User $user): bool`
  - `$data` keys: `username, email, user_type, first_name, middle_name, middle_initial, last_name, suffix, student_no, employee_no`
  - `TemporaryPassword::generate(): string` — 14 characters from `ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789`

Rules are those of the identity design, sections 2 and 3. In short: Student needs `student_no`, Employee needs `employee_no`, either may hold both; Admin type holds neither; first and last name required except for Admin type; numbers unique within their kind only; `username` defaults to the main number when empty; `sync` automatic roles after every create and update; a changed username calls `signOutEverywhere` before saving. An `ADMIN` actor may act only on users for whom `hasAssignedRole()` is false, and may never call `setRoles`. An `ICTO_ADMIN` may not remove their own `ICTO_ADMIN` role or disable themselves. `setRoles` ignores `STUDENT` and `EMPLOYEE` in its input.

- [x] **Step 1: Write the failing tests**

| `AccountRulesTest` | Asserts |
|------|---------|
| `test_student_needs_a_student_number_and_employee_an_employee_number` | exceptions on `student_no` / `employee_no` |
| `test_one_person_may_hold_both_numbers_and_gets_both_roles` | roles `['EMPLOYEE','STUDENT']` |
| `test_admin_type_accounts_carry_no_numbers` | refused on create and on update |
| `test_names_are_required_except_for_admin_type` | — |
| `test_numbers_are_unique_within_their_kind_but_may_repeat_across_kinds` | duplicate `student_no` refused; a `student_no` equal to someone's `employee_no` accepted |
| `test_sign_in_number_defaults_to_the_main_number` | Employee holding both → username is the employee number |
| `test_duplicate_sign_in_number_is_refused_with_the_agreed_message` | "This sign-in number is already used by another account." |
| `test_updating_keeps_the_persons_own_numbers_valid` | re-saving unchanged data passes |
| `test_middle_initial_is_kept_as_entered` | `'DC'` stays `'DC'` |
| `test_new_account_gets_a_temporary_password_and_must_change_it` | 14 characters; `Hash::check`; flag true |
| `test_changing_the_sign_in_number_signs_the_person_out_everywhere` | their refresh fails; `Http::assertSent` to the app's logout address |

| `AdminLevelsTest` | Asserts |
|------|---------|
| `test_admin_can_manage_an_ordinary_account` | every method succeeds on a student |
| `test_admin_cannot_act_on_an_account_with_an_assigned_role` | each of `update`, `unlock`, `resetPassword`, `requirePasswordChange`, `setEnabled` throws for targets holding `ADMIN`, `ICTO_ADMIN`, and a custom `HR_STAFF` |
| `test_admin_cannot_change_roles` | `setRoles` throws; accounts an Admin creates hold only automatic roles |
| `test_icto_admin_can_do_everything` | — |
| `test_icto_admin_cannot_remove_their_own_role_or_disable_themselves` | throws; role and state unchanged |
| `test_every_action_is_logged_with_who_did_it` | activity rows name the actor's username |

- [x] **Step 2: Run** both classes. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Account rules, automatic roles and the two admin levels"`

### Task 13: Admin console: access, overview and apps

**Files:**
- Create: `app/Http/Middleware/RequireRole.php`, `app/Http/Controllers/Console/{OverviewController,AppsController}.php`, `resources/views/admin/{layout,overview,apps,app-new,app,app-secret,not-found}.blade.php`, `tests/Feature/Console/{ConsoleAccessTest,ConsoleAppsTest}.php`
- Modify: `routes/web.php`, `bootstrap/app.php` (alias `role`)

**Interfaces:**
- Consumes: `ClientRegistrar`.
- Produces: middleware `role:ICTO_ADMIN,ADMIN` (403 page for others); routes `GET /admin`; `GET /admin/apps`, `GET /admin/apps/new`, `POST /admin/apps`, `GET /admin/apps/{client}`, `POST /admin/apps/{client}`, `POST /admin/apps/{client}/secret`, `POST /admin/apps/{client}/delete`.

Carry the layout and wording of `../Single-Sign-On-1/src/main/resources/templates/admin/`. Additions: on the app form, a "Who can sign in" section with "Everyone with an active account" or "Only people with these roles" and a checkbox per role, and a checkbox "May administer the SSO (sso.admin)". The apps list shows a summary: "Everyone", or the role names joined by ", ".

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_guests_go_to_login_and_ordinary_users_get_403` | — |
| `test_icto_admin_and_admin_can_open_the_console` | 200 |
| `test_admin_sees_account_counts_but_no_apps_section` | overview shows user counts and recent activity; no link to `/admin/apps`; `GET /admin/apps` → 403 |
| `test_icto_admin_registers_an_app_and_sees_the_secret_once` | POST → 200 page with client id and secret; header `Cache-Control` contains `no-store`; reloading the app page does not show the secret |
| `test_validation_errors_are_shown_on_the_form` | unknown scope → form with the message and the typed values |
| `test_editing_an_app_keeps_its_id_and_secret` | — |
| `test_access_rule_is_saved_and_summarised` | list shows "STUDENT, EMPLOYEE"; cleared → "Everyone" |
| `test_new_secret_replaces_the_old_one` | shown once, `no-store` |
| `test_removing_an_app_deletes_it_and_its_sign_ins` | — |
| `test_unknown_app_shows_the_console_not_found_page` | 404 |
| `test_forms_need_a_csrf_token` | POST without it → 419 |

- [x] **Step 2: Run** both classes. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Admin console: overview and apps"`

### Task 14: Admin console: users

**Files:**
- Create: `app/Http/Controllers/Console/UsersController.php`, `resources/views/admin/{users,user-new,user,user-password}.blade.php`, `tests/Feature/Console/ConsoleUsersTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `AccountService` (all methods).
- Produces: routes `GET /admin/users` (query `q`, 25 per page), `GET /admin/users/new`, `POST /admin/users`, `GET /admin/users/{user}`, `POST /admin/users/{user}` (details), and POST `.../unlock`, `.../require-password-change`, `.../reset-password`, `.../disable`, `.../enable`, `.../roles`.

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_list_shows_name_numbers_and_roles_and_can_be_searched` | search matches username, either number, email, first or last name |
| `test_adding_a_user_shows_the_temporary_password_once` | `no-store`; both number fields present on the form |
| `test_details_form_edits_identity_and_warns_about_the_sign_in_number` | page contains "Changing the sign-in number signs the person out of apps." |
| `test_unlock_reset_require_change_disable_enable_each_work` | state changes; reset shows a new temporary password once |
| `test_roles_show_student_and_employee_as_automatic` | no checkbox for them; text "automatic" |
| `test_only_icto_admins_see_and_can_post_roles` | Admin: no role checkboxes; `POST .../roles` → 403 |
| `test_admin_sees_protected_accounts_but_is_offered_no_actions` | page 200, no action forms; each POST → 403 |
| `test_icto_admin_cannot_disable_themselves_or_drop_their_own_role` | error message shown; unchanged |

- [x] **Step 2: Run** `php artisan test --filter=ConsoleUsersTest`. Expected: FAIL.
- [x] **Step 3: Implement.** Controllers translate `AuthorizationException` to 403 and `ValidationException` to the form with errors.
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Admin console: users"`

### Task 15: Admin API and the audit trail

**Files:**
- Create: `app/Http/Middleware/{RequireSsoAdmin,AuditApi}.php`, `app/Sso/Api/SensitiveDataMasker.php`, `app/Http/Controllers/Api/{AdminUserController,ClientController,RoleController,LogController}.php`, `tests/Feature/Api/{AdminApiTest,AuditLogRedactionTest}.php`
- Modify: `routes/api.php`, `bootstrap/app.php` (aliases `sso.admin`, `api.audit`; `api.audit` on the whole `api` group)

**Interfaces:**
- Consumes: `AccountService`, `ClientRegistrar`, `ApiResponse`.
- Produces:
  - Middleware `sso.admin:ROLE[,ROLE]` — 403 `FORBIDDEN` unless the token has scope `sso.admin` **and** the user (read from the database) holds one of the roles.
  - `SensitiveDataMasker::mask(?string $body): ?string` — in JSON, at any depth, replaces with `"***"` the values of keys that equal, ignoring case, `_` and `-`: `password, newpassword, currentpassword, oldpassword, confirmpassword, token, accesstoken, refreshtoken, idtoken, clientsecret, secret, authorization, temporarypassword`. Non-JSON bodies are stored as `[non-JSON body omitted]`. Stored bodies are cut at 10,000 characters.

Routes (prefix `/api/v1`, all `auth:api`):

| Route | Roles | Does |
|-------|-------|------|
| `POST admin/user`, `PUT admin/user/{id}` | ICTO_ADMIN, ADMIN | `create` / `update` |
| `GET admin/user/all` (`page`, `size`, `q`), `GET admin/user/{id}` | ICTO_ADMIN, ADMIN | list / show |
| `PUT admin/user/{id}/lock`, `/unlock`, `/force-password-reset` | ICTO_ADMIN, ADMIN | `setEnabled(false)` / `unlock` / `requirePasswordChange` |
| `POST client`, `GET client/`, `GET client/paginated`, `GET client/{id}`, `DELETE client/{id}` | ICTO_ADMIN | register / list / show / remove |
| `POST admin/role`, `GET admin/role/all`, `GET admin/role/paginated`, `PUT admin/role/{id}` | ICTO_ADMIN | roles |
| `GET admin/logs` | ICTO_ADMIN | activity log, newest first, paginated |

Request and response field names are camelCase as in the source (`redirectUris`, `postLogoutRedirectUris`, `backchannelLogoutUri`, `allowedRoles`, `studentNumber`, `employeeNumber`, `firstName`, `userTypeId`, …); controllers map them to the snake_case `$data` keys. `POST client` returns `clientId`, `clientSecret`, `clientName`. Standard roles cannot be renamed; renaming a custom role rewrites it in every client's `allowed_roles` in one transaction.

- [x] **Step 1: Write the failing tests**

| `AdminApiTest` | Asserts |
|------|---------|
| `test_icto_admin_with_the_admin_scope_gets_in` | 200 |
| `test_admin_user_whose_token_lacks_the_admin_scope_is_refused` | token from an ordinary app → 403 `FORBIDDEN` |
| `test_ordinary_user_with_the_admin_scope_is_refused` | 403 |
| `test_admin_level_reaches_user_routes_but_not_client_role_or_log_routes` | 200 vs 403 |
| `test_admin_level_cannot_act_on_a_protected_account_through_the_api` | 403 |
| `test_role_removed_since_the_token_was_issued_is_refused_at_once` | detach `ICTO_ADMIN` → 403 with the same token |
| `test_registering_an_app_returns_id_and_secret_and_validates_input` | 200 with the three fields; unknown scope → 400/422 envelope with field errors in `data` |
| `test_creating_a_user_validates_and_returns_the_user` | — |
| `test_standard_roles_cannot_be_renamed` | 400 |
| `test_renaming_a_custom_role_updates_app_rules` | client `allowed_roles` now has the new name |

| `AuditLogRedactionTest` | Asserts |
|------|---------|
| `test_every_api_call_is_recorded_with_user_and_app` | row has `endpoint`, `http_method`, `status_code`, `status` (`success`/`error`), `user_id`, `client_id`, `ip_address` |
| `test_secrets_never_reach_the_audit_trail` | after `POST client`: stored `response_body` contains `"clientSecret":"***"` and not the secret; a request body with `password` is masked |
| `test_unauthenticated_calls_are_recorded_too` | row with null `user_id`, `status_code` 401 |

- [x] **Step 2: Run** both classes. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "Admin API and API audit trail"`

### Task 16: API documentation with Scramble

**Files:**
- Create: `config/scramble.php` (published), `tests/Feature/Api/ApiDocsTest.php`
- Modify: `composer.json`, `config/sso.php`, `app/Providers/AppServiceProvider.php`, `app/Console/Commands/SsoInstall.php`

**Interfaces:**
- Consumes: `ClientRegistrar::register($data, $id, $secret)`, middleware `role`, `Scopes`.
- Produces: `GET /docs/api` (page), `GET /docs/api.json` (OpenAPI 3.1); `config('sso.admin_client_secret')` from `env('SSO_ADMIN_CLIENT_SECRET', '')`; client id constant `SsoInstall::ADMIN_CLIENT_ID = 'sso-admin-console'`.

Settings in `config/scramble.php`: `api_path` `api`; `info.version` `1.0.0`; `info.description` one paragraph saying every call needs an access token from this server and admin calls also need the `sso.admin` scope; `ui.title` `BulSU SSO API`; `renderer` `scalar`; `middleware` `['web', 'auth', 'role:ICTO_ADMIN,ADMIN']` (this replaces Scramble's `RestrictedDocsAccess`, so access is the same in every environment).

In `AppServiceProvider::boot`, declare the security scheme:

```php
Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
    $openApi->secure(SecurityScheme::oauth2()->flow('authorizationCode', function (OAuthFlow $flow) {
        $issuer = config('sso.issuer');
        $flow->authorizationUrl("$issuer/oauth2/authorize")->tokenUrl("$issuer/oauth2/token")
             ->refreshUrl("$issuer/oauth2/token");
        foreach (Scopes::ALLOWED as $scope) { $flow->addScope($scope); }
    }));
});
```

`sso:install`, after the key step: if `config('sso.admin_client_secret')` is empty, print "Admin console client not created: SSO_ADMIN_CLIENT_SECRET is not set." Otherwise, unless a client with id `sso-admin-console` exists, register it with that secret, name `SSO Admin Console`, redirect address `{issuer}/docs/api`, scopes `openid profile email sso.admin`, `allowed_roles` `ICTO_ADMIN, ADMIN`. An existing client is left exactly as it is.

- [x] **Step 1: Install** `composer require dedoc/scramble` and `php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider" --tag="scramble-config"`.

- [x] **Step 2: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_guests_are_sent_to_login_and_ordinary_users_get_403` | for both `/docs/api` and `/docs/api.json` |
| `test_administrators_can_open_the_page_and_the_document` | `ICTO_ADMIN` and `ADMIN` → 200 |
| `test_document_lists_the_api_and_how_to_sign_in` | JSON `openapi` starts with `3.1`; `paths` has `/v1/user/me`, `/v1/client`, `/v1/admin/user/{id}`; `components.securitySchemes.oauth2.flows.authorizationCode` has `authorizationUrl` `{issuer}/oauth2/authorize`, `tokenUrl` `{issuer}/oauth2/token`, and the four scopes |
| `test_document_does_not_list_the_sign_in_pages` | `paths` has no `/login`, `/oauth2/token`, `/admin` |
| `test_install_creates_the_admin_client_when_a_secret_is_set` | client `sso-admin-console` exists; `Hash::check` of the configured secret; scopes include `sso.admin`; redirect address `{issuer}/docs/api`; `allowed_roles` `['ICTO_ADMIN','ADMIN']` |
| `test_install_never_changes_an_existing_admin_client` | run again with another secret → the first secret still checks, the new one does not |
| `test_install_skips_the_admin_client_when_no_secret_is_set` | no client; the message above is printed |
| `test_a_token_from_the_admin_client_can_call_the_admin_api` | `tokensFor($ictoAdmin, $adminClient, 'openid sso.admin')` → `GET /api/v1/client/` is 200 |

- [x] **Step 3: Run** `php artisan test --filter=ApiDocsTest`. Expected: FAIL.
- [x] **Step 4: Implement.**
- [x] **Step 5: Run** `php artisan test`. Expected: PASS.
- [x] **Step 6: Check in a browser** (this has not been tried in advance). Set `SSO_ADMIN_CLIENT_SECRET` to a 32-character random value, run `php artisan sso:install` and `php artisan serve`, sign in as an ICTO admin, open `/docs/api`, choose the OAuth2 authorization-code option, enter client id `sso-admin-console` and the secret, tick all scopes, authorize, then send `GET /v1/user/me`. Expected: 200 with your profile. If the viewer sends a redirect address other than `{issuer}/docs/api`, register the address it sends in `sso:install` and in the test. If the viewer cannot complete the code flow at all, set `renderer` back to `elements`, add a README line that a token must be pasted in, and say so in the commit message.
- [x] **Step 7: Commit** `git commit -am "API documentation with Scramble and the first admin client"`

---

## Milestone 4: operate

### Task 17: Rate limits, cleanup and the settings check

**Files:**
- Create: `app/Console/Commands/{SsoCleanup,SsoCheck}.php`, `tests/Feature/Ops/{RateLimitTest,CleanupTest,SettingsCheckTest}.php`
- Modify: `app/Providers/AppServiceProvider.php` (limiters `sso-login`, `sso-token`), `routes/web.php` (`throttle:` on `POST /login` and `POST /oauth2/token`), `routes/console.php`, `bootstrap/app.php` (429 response)

**Interfaces:**
- Produces:
  - `php artisan sso:cleanup` — prints and logs "Nightly cleanup removed {n} expired app sign-ins and {m} old API audit rows"; scheduled `dailyAt('02:30')`.
  - `SsoCheck::problems(string $issuer, bool $secureCookie, bool $debug, string $adminClientSecret): array`; `php artisan sso:check` prints each problem and exits 1 when any exist, 0 otherwise; outside `production` it always exits 0.

Limits are per IP address per minute, keyed separately for the two paths. The 429 response is plain text "Too many attempts from your network. Wait a minute and try again." with a `Retry-After` header.

Cleanup deletes: access tokens past `expires_at` whose refresh token is also past `expires_at` (and those refresh tokens); authorization codes past `expires_at`; sign-ins that have no remaining code or token and started more than 8 hours ago; API audit rows older than the retention days (none when it is 0). Activity logs are never deleted.

Problems reported by `sso:check`: "SSO_ISSUER must be the public https:// address of this server (is {issuer})."; "SSO_ISSUER must be the public address of this server, not localhost." (also for `127.0.0.1`); "SESSION_SECURE_COOKIE must be true so the SSO session cookie is only sent over HTTPS."; "APP_DEBUG must be false in production."; "SSO_ADMIN_CLIENT_SECRET must be a random value of at least 24 characters (or unset)." (when it is set and is shorter than 24 characters or one of `change-me`, `changeme`, `secret`, `password`, ignoring case).

- [x] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `RateLimitTest::test_the_31st_login_attempt_in_a_minute_from_one_address_is_refused` | 429, the text, `Retry-After` between 1 and 60 |
| `test_another_address_is_not_affected` | `REMOTE_ADDR` differs → not 429 |
| `test_the_limit_resets_after_a_minute` | `travel(61)->seconds()` |
| `test_token_requests_have_their_own_limit` | with `sso.rate_limit.token_per_minute` set to 3: 4th → 429; GET `/login` is never limited |
| `CleanupTest::test_a_sign_in_whose_tokens_have_all_expired_is_removed` | rows gone |
| `test_a_sign_in_with_a_valid_refresh_token_is_kept` | access expired, refresh valid → kept, refresh still works |
| `test_old_api_audit_rows_are_removed_and_recent_ones_kept` | 366 days vs 364 days |
| `test_retention_of_zero_keeps_everything` | — |
| `test_activity_logs_are_never_removed` | — |
| `SettingsCheckTest` | one test per problem, one for a clean production setup (`[]`), one that the command exits 0 outside production |

- [x] **Step 2: Run** the three classes. Expected: FAIL.
- [x] **Step 3: Implement.**
- [x] **Step 4: Run** `php artisan test` and `php artisan schedule:list`. Expected: PASS; `sso:cleanup` listed at 02:30.
- [x] **Step 5: Commit** `git commit -am "Rate limits, nightly cleanup and production settings check"`

### Task 18: Documentation and end-to-end check

**Files:**
- Create: `README.md` (replace the skeleton's), `starter-kits/README.md`

- [x] **Step 1: Write `README.md`** with these sections, following the source's README: what this is and its relation to the Spring Boot server; requirements (PHP 8.2, Composer, MariaDB or PostgreSQL); running locally (`composer install`, `.env`, `php artisan migrate --seed`, `php artisan sso:install`, `php artisan serve`, sign in as `admin` / `password123` and choose a new password, open `/admin`); connecting an app (people sign in with their student or employee number; the claims; the four roles; per-app rules); the admin API and its documentation at `/docs/api` (sign in there with `sso-admin-console` and `SSO_ADMIN_CLIENT_SECRET`); operations (`/actuator/health`, `sso:cleanup` and the scheduler's cron line, the two rate-limit settings); production (`sso:check`, `SESSION_SECURE_COOKIE`, `SSO_ISSUER`, key files or `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`, `php artisan optimize`); differences from the Spring Boot server (the table in the spec).
- [x] **Step 2: Write `starter-kits/README.md`**: the kits live in `../Single-Sign-On-1/starter-kits` and work unchanged; set `SSO_ISSUER` to this server.
- [x] **Step 3: End-to-end run.** Start this server on port 8000. Register two apps in `/admin/apps` with redirect, post-logout and single-logout addresses for the Express kit (port 3000) and for the Laravel kit installed in a scratch Laravel app (port 8001). Expected, in order: sign in to the first with the password; open the second with no password; sign out of the second and the first is signed out too; a second browser profile signed in to the first stays signed in; an app limited to `EMPLOYEE` shows a student the no-access page. Record the result in the commit message; fix and re-run on any failure.
- [x] **Step 4: Run** `php artisan test`. Expected: PASS.
- [x] **Step 5: Commit** `git commit -am "README and end-to-end check with the starter kits"`

---

## Milestone 5: OpenID conformance

The requirements here come from the conformance suite's source, read on 2026-11-22 (spec section 7). Where the suite and this plan disagree when run, the suite wins: fix the server, add a test, and note it in `docs/conformance.md`.

### Task 19: Authorize parameters and optional PKCE

**Files:**
- Modify: `app/Http/Controllers/OAuth/AuthorizeController.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Sso/OAuth/AuthCodeRepository.php`, `routes/web.php`, `bootstrap/app.php` (`POST /oauth2/authorize` exempt from CSRF), `resources/views/admin/app.blade.php` and `app-new.blade.php` ("Require PKCE" checkbox), `app/Http/Controllers/Console/AppsController.php`, `app/Http/Controllers/Api/ClientController.php` (`requirePkce`)
- Test: `tests/Feature/Sso/ConformanceAuthorizeTest.php`

**Interfaces:**
- Consumes: `Client::require_pkce`, session keys `sso.auth_time`, `sso.sid`, `BackChannelLogout`.
- Produces: `GET|POST /oauth2/authorize` without the `auth` middleware (the controller decides what a guest gets); session key `sso.reauth` (a hash of the authorize request waiting for a fresh sign-in).

Order of checks in `AuthorizeController`, after the library has validated the client and `redirect_uri` (failures before that still render the error page):

1. `request` present → redirect to the app with `error=request_not_supported`; `request_uri` present → `request_uri_not_supported`.
2. Client has `require_pkce` and no `code_challenge` → `invalid_request`.
3. Nobody signed in: `prompt=none` → `error=login_required`; otherwise save the URL as intended and redirect to `/login`.
4. Signed in, and (`prompt=login`, or `max_age` given and `now - sso.auth_time > max_age`), and `sso.reauth` does not match this request → set `sso.reauth`, save the URL as intended, redirect to `/login`. With `prompt=none` instead return `login_required`. When `sso.reauth` matches, clear it and continue.
5. Must change password: `prompt=none` → `interaction_required`; otherwise redirect to `/change-password`.
6. App access rule refuses the user: `prompt=none` → `access_denied`; otherwise the no-access page.
7. Issue the code.

Every error redirect carries the request's `state` unchanged. `LoginController::show` shows the form to an already signed-in user when `sso.reauth` is set; a successful sign-in then sets a new `sso.auth_time` and keeps `sso.sid`.

`AuthCodeRepository::isAuthCodeRevoked`: when the code is already revoked, revoke every access and refresh token of that code's sign-in before returning true.

- [ ] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_prompt_none_without_a_session_returns_login_required_to_the_app` | redirect to the app's address with `error=login_required` and the same 300-character `state` |
| `test_prompt_none_with_a_session_signs_in_silently` | code issued; ID token `sub` and `auth_time` equal the first sign-in's |
| `test_prompt_login_asks_for_the_password_again_and_gives_a_later_auth_time` | signed in → redirect `/login`, form shown; after sign-in (`travel(5)->seconds()` first) the same request issues a code; ID token `auth_time` is later |
| `test_max_age_exceeded_asks_for_the_password_again` | `travel(10)->seconds()`, `max_age=1` → `/login`; afterwards `auth_time` is within the last few seconds |
| `test_max_age_not_exceeded_signs_in_silently` | `max_age=10000` → code; ID token has `auth_time` |
| `test_reauthentication_does_not_loop` | after the fresh sign-in the request completes even if 2 seconds pass with `max_age=1` |
| `test_post_to_the_authorize_endpoint_works` | form POST with the same parameters → code |
| `test_optional_parameters_are_accepted_and_ignored` | `id_token_hint`, `login_hint`, `ui_locales`, `claims_locales`, `acr_values`, `display=popup`, `claims`, `foo=bar` → code |
| `test_request_objects_are_refused_as_unsupported` | `request=x` → `error=request_not_supported`; `request_uri=https://x` → `error=request_uri_not_supported` |
| `test_missing_response_type_is_an_error_not_a_crash` | redirect with `error=unsupported_response_type` or `invalid_request` |
| `test_an_app_with_pkce_switched_off_can_sign_in_without_it` | `require_pkce` false → code; exchange without `code_verifier` → 200 |
| `test_an_app_with_pkce_switched_off_may_still_use_it` | challenge and verifier → 200; wrong verifier → `invalid_grant` |
| `test_pkce_stays_required_by_default` | a newly registered app has `require_pkce` true |
| `test_a_request_without_nonce_gets_an_id_token_without_nonce` | — |
| `test_reusing_a_code_revokes_the_tokens_it_produced` | second exchange 400 `invalid_grant`; the first access token → 401 at `/userinfo`; the first refresh token → `invalid_grant` |
| `test_prompt_none_for_a_user_who_must_change_password_returns_interaction_required` | — |
| `test_prompt_none_for_a_user_the_app_refuses_returns_access_denied` | — |

- [ ] **Step 2: Run** `php artisan test --filter=ConformanceAuthorizeTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: PASS, including Task 7's tests that guests are sent to `/login`.
- [ ] **Step 5: Commit** `git commit -am "Authorize parameters for conformance; PKCE per app"`

### Task 20: Standard claims, discovery and user info

**Files:**
- Modify: `app/Sso/Accounts/UserClaims.php`, `app/Sso/OAuth/IdTokenFactory.php`, `app/Http/Controllers/OAuth/DiscoveryController.php`, `app/Http/Controllers/OAuth/UserInfoController.php`, `routes/web.php`
- Test: `tests/Feature/Sso/ConformanceClaimsTest.php`

**Interfaces:**
- Produces: `UserClaims::standardProfile(User $user): array` — `name`, `preferred_username`, and when not empty `given_name` (first name), `family_name` (last name), `middle_name`; always `updated_at` (unix seconds of `users.updated_at`). Used by both the ID token and `/userinfo` for scope `profile`.

- [ ] **Step 1: Write the failing tests**

| Test | Asserts |
|------|---------|
| `test_profile_scope_adds_the_standard_name_claims` | ID token and `/userinfo` both have `given_name`, `family_name`, `middle_name`, `updated_at` (an integer), beside `name`, `preferred_username` |
| `test_empty_names_are_left_out_not_sent_as_null` | seeded `admin`: no `given_name` key; `name` is `admin` |
| `test_existing_claims_keep_their_names_and_shapes` | `user_profile`, `roles`, `user_type`, `user_id` unchanged from Task 9 |
| `test_email_verified_is_not_claimed` | key absent |
| `test_discovery_declares_claims_and_unsupported_features` | `claims_supported` equals `['sub','iss','aud','exp','iat','auth_time','nonce','sid','name','given_name','family_name','middle_name','preferred_username','updated_at','email','roles','user_type']`; `request_parameter_supported`, `request_uri_parameter_supported`, `claims_parameter_supported` are `false`; no `request_object_signing_alg_values_supported` key |
| `test_userinfo_accepts_the_token_in_a_post_body` | `POST /userinfo` with form field `access_token` → 200, same `sub` |
| `test_userinfo_sub_matches_the_id_token_sub` | — |

- [ ] **Step 2: Run** `php artisan test --filter=ConformanceClaimsTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** For the POST body token, a small middleware on `/userinfo` copies `access_token` from the form into the `Authorization` header when that header is absent.
- [ ] **Step 4: Run** `php artisan test`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -am "Standard claims, fuller discovery, token in POST body"`

### Task 21: Logout strictness

**Files:**
- Create: `resources/views/logout-error.blade.php`
- Modify: `app/Http/Controllers/OAuth/EndSessionController.php`
- Test: `tests/Feature/Sso/ConformanceLogoutTest.php`

**Interfaces:**
- Consumes: `SigningKey::decode` (signature must verify with this server's key and `alg` must be `RS256`; expiry ignored), `BackChannelLogout::signOutSession`.

Decision table for `GET|POST /connect/logout`:

| Request | Result |
|---------|--------|
| Valid hint, registered `post_logout_redirect_uri` (exact string match) | sign out, redirect there; `state` appended only if it was sent |
| Valid hint, no `post_logout_redirect_uri` | sign out, redirect to `/login?logout` |
| No parameters, or only `state` | sign out, redirect to `/login?logout` |
| `post_logout_redirect_uri` without a hint | HTTP 400 `logout-error` page; session untouched |
| Hint that is altered, signed by another key, or `alg: none` | HTTP 400 `logout-error` page; session untouched |
| Unregistered address, or a registered one with `?foo=bar` added | HTTP 400 `logout-error` page; session untouched |

This replaces Task 11's rule that an unregistered address lands on `/login?logout`; update that test to expect the error page. The `logout-error` page says "This sign-out request isn't valid." and offers a Sign out button that posts to `/logout`.

- [ ] **Step 1: Write the failing tests**, one per row of the table, plus:

| Test | Asserts |
|------|---------|
| `test_after_logout_a_prompt_none_request_returns_an_error` | `error=login_required` |
| `test_logout_without_state_redirects_without_a_state_parameter` | `Location` has no `state` |
| `test_an_expired_id_token_is_still_accepted_as_a_hint` | `travel(1)->hours()` → still signs out and redirects |
| `test_back_channel_notification_is_sent_before_the_redirect_is_followed` | `Http::assertSent` for the app's address; token `events` value serialises as `{}`; `iat` is not in the future |

- [ ] **Step 2: Run** `php artisan test --filter=ConformanceLogoutTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: PASS.
- [ ] **Step 5: Commit** `git commit -am "Stricter RP-initiated logout for conformance"`

### Task 22: Run the conformance suite

**Files:**
- Create: `docs/conformance.md`, `docs/conformance/basic-config.json`

This task needs the owner's go-ahead for two actions on this machine: adding a line to the Windows `hosts` file (needs an administrator prompt) and pulling and running the suite's Docker images.

- [ ] **Step 1: Start the suite.** Add `127.0.0.1 localhost.emobix.co.uk` to `C:\Windows\System32\drivers\etc\hosts`. In a scratch folder outside the repository:

```bash
curl -O https://gitlab.com/openid/conformance-suite/-/raw/master/docker-compose-prebuilt.yml
docker compose -f docker-compose-prebuilt.yml up
```

Expected: `https://localhost.emobix.co.uk:8443/` opens (self-signed certificate).

- [ ] **Step 2: Start the server where the suite can reach it.** The suite calls the server from inside a container and the browser calls it from Windows, so both must resolve one name. Set `SSO_ISSUER=http://host.docker.internal:8000` and `APP_URL` to the same, run `php artisan config:clear` and `php artisan serve --host=0.0.0.0 --port=8000`. Confirm `http://host.docker.internal:8000/.well-known/openid-configuration` opens in the browser; if the name does not resolve on Windows, add `127.0.0.1 host.docker.internal` to `hosts`.

- [ ] **Step 3: Register the test clients** in `/admin/apps`: two apps, each with "Require PKCE" off, scopes `openid profile email`, redirect address `https://localhost.emobix.co.uk:8443/test/a/bulsu-sso/callback`. Create a test account (a student with first, middle and last name) that has already changed its password. The suite's second client-authentication client may be the first app, since every app accepts both methods.

- [ ] **Step 4: Run the Basic plan.** Create plan `oidcc-basic-certification-test-plan` with server metadata `discovery` and client registration `static_client`. Save the configuration as `docs/conformance/basic-config.json` with the secrets replaced by `REPLACE_ME`:

```json
{
  "alias": "bulsu-sso",
  "server": { "discoveryUrl": "http://host.docker.internal:8000/.well-known/openid-configuration" },
  "client": { "client_id": "REPLACE_ME", "client_secret": "REPLACE_ME" },
  "client2": { "client_id": "REPLACE_ME", "client_secret": "REPLACE_ME" }
}
```

If the suite's form asks for a separate `client_secret_post` client, give it the first app's id and secret. Expected: every module ends PASSED, WARNING, REVIEW or SKIPPED; none FAILED or INTERRUPTED. Expected warnings: `email_verified` missing. Expected skips: the address and phone scope modules and the request-object modules.

- [ ] **Step 5: Fix every failure.** For each FAILED module: write a failing feature test that reproduces it, fix the server, re-run the module. If the redirect address the suite actually uses differs from Step 3, register the one it shows.

- [ ] **Step 6: Write `docs/conformance.md`**: how to repeat Steps 1 to 4; the date, the suite version shown in its footer, and the result of each module; each accepted warning with its reason; and the remaining plans, which need HTTPS and therefore a deployed server: `oidcc-config-certification-test-plan`, `oidcc-rp-initiated-logout-certification-test-plan`, `oidcc-backchannel-rp-initiated-logout-certification-test-plan`. For those, list what to register (logout address `.../test/a/<alias>/backchannel_logout`, post-logout address `.../test/a/<alias>/post_logout_redirect`) and that certification must be published from `www.certification.openid.net`, at USD 700 for members or USD 3,500 for non-members.

- [ ] **Step 7: Restore local settings.** Set `SSO_ISSUER` and `APP_URL` back to `http://localhost:8000`, stop the containers, run `php artisan test`. Expected: PASS.

- [ ] **Step 8: Commit** `git add docs && git commit -m "Conformance suite: Basic OP run and instructions for the HTTPS plans"`
