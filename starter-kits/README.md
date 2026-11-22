# Starter kits

The starter kits for BulSU apps live in the Spring Boot repository, next to this one:
`../Single-Sign-On-1/starter-kits`. They work with this server unchanged.

| Your stack | Use |
|------------|-----|
| Laravel 10, 11, 12 (with or without Sanctum) | `../Single-Sign-On-1/starter-kits/laravel` |
| Express (Node 20.6+) | `../Single-Sign-On-1/starter-kits/express` |
| React or Vue in front of either | `../Single-Sign-On-1/starter-kits/frontend.md` |

Follow the kit's own `README.md`, with two differences:

1. Register the app in this server's admin console, at `/admin/apps` (**Register app**), with the kit's
   redirect URI, sign-out return address and single-logout address.
2. Set `SSO_ISSUER` to this server's address: `http://localhost:8000` in development, the public `https://`
   address in production. The Laravel kit's default is `http://localhost:8080`, the Spring Boot server, so
   the setting is needed even in development.

Nothing else changes: the paths, claims, token lifetimes and single logout are the same as on the Spring Boot
server.

## Checked end to end

On 2026-11-22 both kits ran against this server (the Express kit on port 3000, the Laravel kit installed in a
fresh Laravel 12 app on port 8001): sign-in with the password, sign-in to the second app without it, single
logout across both apps while the same person in another browser stayed signed in, and the "You don't have
access" page for an app limited to `EMPLOYEE`.

One thing to know when trying this on Windows with `php artisan serve`, which handles one request at a time:
the Express kit fetches this server's signing keys only when its first logout notice arrives, and that fetch
waits behind the notice itself, so the first notice times out (this server logs a warning) and that app stays
signed in. While the kit has the keys, notices work; it keeps them for 10 minutes and then fetches them again
at the next notice, with the same result. The Laravel kit fetches the keys at sign-in and keeps them for an
hour, so it is not affected in the same way. Production servers handle requests in parallel and are not
affected at all.
