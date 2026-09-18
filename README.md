# Task Manager

A small task manager built as a PHP REST API over MySQL with a React front end,
with JWT authentication and role-based access control.

- `api/` — PHP 7.4+ REST API, no framework, PDO for data access
- `web/` — React 19 single page app built with Vite and React Router
- `db/init/` — schema. Safe to load into any environment
- `db/seed/` — demo accounts and tasks. Development only
- `scripts/setup-db.sh` — creates the database and loads the schema
- `scripts/create-admin.php` — creates the first administrator
- `scripts/verify-api.sh` — end-to-end assertions against a running API
- `web/public/` — GOQii brand assets, stored locally rather than hotlinked

## Requirements

- PHP 7.4 or newer with the `pdo_mysql` extension
- MySQL 8.0 or newer
- Node 18 or newer

## Configuration

All configuration comes from the environment. Copy the template and fill it in:

```bash
cp .env.example .env
openssl rand -hex 32        # paste into JWT_SECRET
```

Docker Compose reads `.env` directly. The API also reads it when run outside a
container, so a CLI script does not need a dozen exported variables. Real
environment variables always win, so a file left in an image can never override
what the platform injected.

Anything that affects security has no fallback — the API refuses to start
rather than use a guessable default:

| Variable              | Required | Default     | Notes |
| --------------------- | -------- | ----------- | ----- |
| `DB_NAME`             | yes      | —           | |
| `DB_USER`             | yes      | —           | |
| `DB_PASS`             | yes      | —           | No default: a stock password is how an app connects somewhere nobody intended |
| `DB_HOST`             | no       | `127.0.0.1` | |
| `DB_PORT`             | no       | `3306`      | |
| `JWT_SECRET`          | yes      | —           | 32 characters minimum; rotating it signs everyone out |
| `CORS_ORIGIN`         | yes      | —           | Comma-separated allowlist of front end origins |
| `COOKIE_SECURE`       | no       | `true`      | Set `false` only for local plain HTTP |
| `COOKIE_SAMESITE`     | no       | `Lax`       | `None` requires `COOKIE_SECURE=true` |

A misconfiguration returns a generic `500` to the client and writes the precise
reason to the error log, so the fix is obvious to an operator without leaking
anything to a caller.

## Running it locally

### With Docker

Brings up MySQL and the API, loading the schema and the demo data on first
start. The front end still runs through Vite.

```bash
cp .env.example .env     # set JWT_SECRET and the passwords
docker compose up -d
cd web && cp .env.example .env && npm install && npm run dev
```

Open http://localhost:5173. MySQL is published on host port `3307` so it cannot
clash with a local server.

### Without Docker

```bash
cp .env.example .env
scripts/setup-db.sh --with-demo     # omit the flag for schema only
php -S localhost:8080 -t api/public api/public/index.php
cd web && cp .env.example .env && npm install && npm run dev
```

Run one backend or the other, not both. They both bind port `8080`, and if the
Compose stack and `php -S` are up at once, requests silently reach whichever
won the bind — against two different databases.

Check the API is up:

```bash
curl http://localhost:8080/api/health
```

### Demo accounts

Created by `db/seed/`, which is loaded by Docker Compose and by
`setup-db.sh --with-demo`. **Development only** — every account below shares
the password `Password123`, and a production database never loads this file.

| Email               | Role    |
| ------------------- | ------- |
| `saif@example.com`  | `admin` |
| `priya@example.com` | `user`  |
| `rahul@example.com` | `user`  |

## Deploying

`docker-compose.yml` is a development stack, not a deployment: it mounts the
source read-write and loads demo data. For a real environment:

1. **Load the schema only.** Every file in `db/init/` and nothing from
   `db/seed/`. The files carry no `USE` statement, so the target database is
   whatever the connection selects.

2. **Create the first administrator.** Registration through the API always
   produces a regular user — no request body can mint an admin, which is why
   this step exists at all:

   ```bash
   php scripts/create-admin.php
   ```

   It prompts, or reads `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` for an
   unattended deploy, and applies the same password rules as registration.

3. **Serve the API over HTTPS** with `COOKIE_SECURE=true`, which is the
   default. Over plain HTTP the browser discards the refresh cookie and nobody
   stays signed in. The document root is `api/public`; nothing above it is
   reachable, which is what keeps `.env` out of the web path.

4. **Build the front end** with the API URL baked in. It is inlined at build
   time, so it has to be present then, not at runtime:

   ```bash
   cd web && VITE_API_URL=https://api.example.com/api npm run build
   ```

   Serve `web/dist` with a history fallback: any unknown path must return
   `index.html`, or a reload on `/login` gives a 404.

5. **Set `CORS_ORIGIN`** to the front end's exact origin. Credentialed CORS
   forbids a wildcard, so the request origin is matched against this allowlist
   and echoed only on a hit.

6. **Prune expired refresh tokens** periodically.
   `RefreshTokenRepository::pruneExpired()` deletes rows that lapsed over 30
   days ago; nothing calls it on a schedule, so wire it to cron if the table
   growth matters.

## Verifying the API

```bash
scripts/verify-api.sh                        # defaults to http://localhost:8080/api
scripts/verify-api.sh http://host:8080/api   # or point it somewhere else
```

134 assertions against a running server, covering every route, status code, role
boundary and token behaviour: ownership isolation, privilege escalation
attempts, forged and expired JWTs, refresh rotation, concurrent refreshes,
replay inside and outside the grace window, `ORDER BY` injection, `LIKE`
wildcard escaping, pagination limits and CORS preflight. It prints a
per-assertion pass or fail and exits non-zero if anything is wrong, so it works
as a CI gate.

It takes about 15 seconds, most of which is waiting out the replay grace window
to prove that a stale token really is rejected.

It reads `.env`, so it needs no arguments: the forged-token checks sign their
own JWTs with the server's `JWT_SECRET`, and the cleanup step uses the database
credentials.

The script removes the tasks and the accounts it creates. Deleting the accounts
needs direct database access, because there is deliberately no delete-user
endpoint; if `mysql` is not reachable it says so and leaves them, and they can
be cleared with:

```sql
DELETE FROM users WHERE email LIKE 'verify-%';
```

## Authentication

Two tokens, each doing a different job.

The **access token** is a stateless HS256 JWT sent as `Authorization: Bearer`.
It carries the user id, email and role, and lives for 15 minutes. Because
nothing about it is stored server-side it cannot be revoked, so the short
lifetime is what limits the damage if one leaks. The client keeps it in memory
only, never in `localStorage`, so an XSS payload cannot read it out of storage.

The **refresh token** is the long-lived, revocable half: 32 random bytes, 30-day
lifetime, delivered in an `httpOnly` cookie scoped to `/api/auth`. Only a
SHA-256 hash of it is stored, so a dump of `refresh_tokens` cannot be replayed.
Every use rotates it — the old row is revoked and a new one issued in the same
`family_id` lineage.

Presenting an already-rotated token means a copy is circulating, so the **whole
family is revoked** and everyone holding it has to sign in again.

The exchange runs in one transaction that opens with `SELECT ... FOR UPDATE` on
the token row. That serialises concurrent refreshes: the second request waits for
the first to commit and then sees a finished rotation, rather than the two
interleaving and each having to guess whether the other is a legitimate client
or a thief. The family revocation is returned to the caller and raised as a
`401` *after* the commit — throwing inside the transaction would roll back the
revocation and discard the very evidence of the leak.

Strict rotation assumes one client holds one token, which a browser breaks
routinely: two tabs restoring a session on start-up both present the same
cookie, and a request retried over a flaky connection arrives twice. So a
just-rotated token keeps working for a short **grace window**
(`TokenService::REPLAY_GRACE`, 10 seconds), provided its lineage is still alive.
Replays outside the window, or against a family already burned, get the strict
treatment. The cost is a few seconds in which a stolen token would pass
undetected; the benefit is that ordinary browsing does not look like an attack.

Because the access token only lives in memory, a page reload starts with
nothing. The app trades the cookie for a fresh token on boot, which is what
keeps you signed in across a refresh.

### `POST /api/auth/register`

```json
{ "name": "Test Person", "email": "test@example.com", "password": "Password123" }
```

Returns `201` with the user and an access token, and sets the refresh cookie.
New accounts always get the `user` role — `role` in the body is ignored, so
self-registration cannot mint an admin.

### `POST /api/auth/login`

```json
{ "email": "saif@example.com", "password": "Password123" }
```

Returns `200` with the same shape. A wrong password and an unregistered email
produce the identical `401` and take the same time to answer, so the endpoint
cannot be used to discover which addresses have accounts.

### `POST /api/auth/refresh`

Takes no body — it reads the refresh cookie. Returns `200` with a new access
token and rotates the cookie.

### `POST /api/auth/logout`

Revokes the presented refresh token and clears the cookie. Returns `204`.

### `POST /api/auth/logout-all`

Revokes every refresh token for the caller, signing them out on all devices.
Requires a valid access token. Returns `204`.

### `GET /api/auth/me`

Returns the caller's own account. Requires a valid access token.

## Roles and permissions

| Action                    | `user`                | `admin`            |
| ------------------------- | --------------------- | ------------------ |
| List tasks                | Own tasks only        | All tasks          |
| Read, update, delete task | Own tasks only, `403` otherwise | Any task |
| Create task               | Owner forced to self  | May assign any owner |
| Reassign a task's owner    | `403`                 | Allowed            |
| `GET /api/users`          | `403`                 | Allowed            |

Scoping is applied in the `WHERE` clause rather than by filtering rows after
the fact, so another user's tasks are unreachable by any code path — including
`?user_id=`, which a regular user cannot use to widen their view.

## API reference

Base path: `/api`. All request and response bodies are JSON. Every `/api/tasks`
and `/api/users` route requires a valid access token.

### `GET /api/tasks`

| Query param | Notes |
| ----------- | ----- |
| `status`    | `todo`, `in-progress` or `done` |
| `priority`  | `low`, `medium` or `high` |
| `user_id`   | Positive integer |
| `search`    | Matches against title and description |
| `page`      | Defaults to `1` |
| `per_page`  | Defaults to `10`, capped at `50` |
| `sort`      | `created_at`, `updated_at`, `due_date`, `title`, `priority` or `status` |
| `order`     | `asc` or `desc`, defaults to `desc`. Tasks with no due date sort last either way |

```json
{
  "data": [
    {
      "id": 3,
      "title": "Build the list endpoint",
      "description": "Support filtering by status plus limit/offset pagination.",
      "status": "in-progress",
      "priority": "high",
      "due_date": "2026-09-20",
      "user_id": 1,
      "owner": { "id": 1, "name": "Saif Idrisi", "email": "saif@example.com" },
      "created_at": "2026-09-18 07:15:02",
      "updated_at": "2026-09-18 07:15:02"
    }
  ],
  "meta": { "page": 1, "per_page": 10, "total": 8, "total_pages": 1 }
}
```

### `POST /api/tasks`

Creates a task. Returns `201` with a `Location` header pointing at the new
resource.

```json
{
  "title": "Write the integration tests",
  "description": "Cover the happy path and the 422 responses.",
  "status": "todo",
  "priority": "high",
  "due_date": "2026-10-02",
  "user_id": 2
}
```

Only `title` and `user_id` are required. `status` defaults to `todo` and
`priority` to `medium`.

### `GET /api/tasks/{id}`

Returns a single task, or `404` if it does not exist.

### `PUT /api/tasks/{id}`

Replaces the task. Fields left out of the body are reset to their defaults, so
send the whole object.

### `PATCH /api/tasks/{id}`

Applies only the fields present in the body. This is what the status dropdown in
the UI uses:

```json
{ "status": "done" }
```

### `DELETE /api/tasks/{id}`

Returns `204` with an empty body, or `404` if the task does not exist.

### `GET /api/users`

Returns the seeded users, used to populate the owner dropdowns.

## Status codes

| Code | When |
| ---- | ---- |
| `200` | Successful read or update |
| `201` | Task created |
| `204` | Task deleted, or a CORS preflight |
| `400` | Body was not valid JSON |
| `401` | No token, an invalid or expired token, or bad login credentials |
| `403` | Authenticated, but the role or ownership does not permit the action |
| `404` | Unknown task or unknown route |
| `405` | Method not supported on a known path, with an `Allow` header |
| `422` | Validation failed |
| `500` | Unexpected server error |

A `401` also carries a machine-readable `code` so the client can tell "your
access token just aged out, go refresh" apart from "your credentials are wrong":

```json
{ "message": "Access token has expired.", "code": "token_expired" }
```

The codes are `no_token`, `token_invalid`, `token_expired`,
`invalid_credentials`, `refresh_missing`, `refresh_invalid`, `refresh_expired`
and `refresh_reused`.

Validation failures list one message per field:

```json
{
  "message": "The submitted data is invalid.",
  "errors": {
    "title": "Title must be at least 3 characters.",
    "user_id": "The selected owner does not exist."
  }
}
```

## Validation rules

Enforced on the server in `api/src/Validation/TaskValidator.php` and mirrored on
the client in `web/src/validation.js`. The client copy is only there to save a
round trip — the server never trusts it.

| Field | Rule |
| ----- | ---- |
| `title` | Required, 3–160 characters after trimming |
| `description` | Optional, up to 2000 characters, blank is stored as `NULL` |
| `status` | One of `todo`, `in-progress`, `done` |
| `priority` | One of `low`, `medium`, `high` |
| `due_date` | Optional, `YYYY-MM-DD`, must be a real calendar date |
| `user_id` | Required, must reference an existing user |

## Notes on the design

- **No framework.** The API is small enough that a front controller plus a
  regex router is clearer than a dependency tree. `api/public/index.php` wires
  everything together and is the place to start reading.
- **Controllers stay thin.** SQL lives in `api/src/Repositories`, validation in
  `api/src/Validation`, and HTTP concerns in `api/src/Http`.
- **Every value is bound.** `ORDER BY` cannot be parameterised, so sortable
  columns come from a whitelist in `TaskRepository::SORTABLE` and no user input
  reaches the SQL string.
- **Pagination counts separately.** The list endpoint runs a `COUNT(*)` with the
  same filters as the page query so `meta.total` stays accurate, and sorts with
  `id` as a tie-breaker so paging is stable when sort values repeat.
- **Errors are typed.** `HttpException` and its subclasses carry the status code,
  so handlers throw and the single catch block in the front controller decides
  the response shape. Unexpected exceptions are logged and answered with a
  generic `500`.
- **Auth is a route concern, not a controller one.** Routes declare
  `['auth' => true, 'role' => 'admin']` and the router enforces it before the
  handler runs. The resolver is called lazily, so an expired token cannot break
  a public route such as login.
- **Only HS256 is accepted.** `Jwt` checks the token's `alg` against a fixed
  expectation instead of using it to select an algorithm. That is what defeats
  `"alg": "none"` and algorithm-confusion forgeries. Signatures are compared
  with `hash_equals`, which is constant-time, so a wrong signature cannot be
  narrowed down by measuring response times.
- **Passwords use `password_hash`/`password_verify`** with `PASSWORD_DEFAULT`,
  which salts each hash automatically. `password_needs_rehash` on login upgrades
  stored hashes when PHP's default cost moves on. Passwords over 72 bytes are
  rejected rather than accepted, because bcrypt silently ignores the remainder.
- **Mass assignment is blocked twice.** Validation returns only clean fields,
  and `TaskRepository` intersects them against a `WRITABLE` whitelist, so a
  payload containing `id` or `created_at` cannot write those columns.

## Notes on the front end

- **Protected routes, not hidden buttons.** `ProtectedRoute` redirects anonymous
  visitors to `/login` and remembers where they were going. Hiding admin UI is
  cosmetic — the server enforces the same rules again, so a user who unhides a
  control still gets a `403`.
- **One shared refresh.** `refreshSession()` is the only caller of
  `/auth/refresh`, and every path through it — the boot-time restore and the
  `401` interceptor — shares one in-flight request plus a two-second cache of
  the last result. That is a correctness requirement, not an optimisation:
  spending the refresh cookie twice trips the server's reuse detection.
  `StrictMode` double-invokes effects, so without this a page reload would end
  the session.
- **Deploying the front end** needs a history fallback: any unknown path must
  serve `index.html`, or a reload on `/login` will 404. Vite's dev server and
  `vite preview` do this already.
# goqii-task
