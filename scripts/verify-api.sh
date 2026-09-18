#!/usr/bin/env bash
#
# End-to-end verification of the Task Manager API.
#
#   scripts/verify-api.sh [base-url]
#
# Exercises every route, status code, role boundary and token behaviour against
# a running server. Exits non-zero if anything fails.
#
# The token-forgery checks sign their own JWTs, so they need the server's secret;
# override with JWT_SECRET if it is not the local development default.
#
set -uo pipefail

BASE="${1:-http://localhost:8080/api}"

# The token-forgery checks sign their own JWTs, so they need the API's secret.
# Reading .env means it does not have to be exported by hand.
if [ -f "$(dirname "$0")/../.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$(dirname "$0")/../.env"
    set +a
fi

SECRET="${JWT_SECRET:?JWT_SECRET is not set. Put it in .env or export it, so the forgery checks can sign tokens.}"
PASSWORD="Password123"

# Must match TokenService::REPLAY_GRACE; the theft test waits it out.
GRACE="${REFRESH_GRACE_SECONDS:-10}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

pass=0
fail=0

if [ -t 1 ]; then
    GREEN=$'\033[32m'; RED=$'\033[31m'; DIM=$'\033[2m'; OFF=$'\033[0m'
else
    GREEN=""; RED=""; DIM=""; OFF=""
fi

section() { printf '\n%s%s%s\n' "$DIM" "$1" "$OFF"; }

check() { # check <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        printf '  %sok%s   %-50s %s\n' "$GREEN" "$OFF" "$1" "$3"
        pass=$((pass + 1))
    else
        printf '  %sFAIL%s %-50s expected %s, got %s\n' "$RED" "$OFF" "$1" "$2" "$3"
        fail=$((fail + 1))
    fi
}

# Set before calling req() to send credentials with the request.
TOKEN=""
JAR=""

req() { # req <method> <path> [body-file]  -> echoes status, body lands in $WORK/out.json
    local method="$1" path="$2" body="${3:-}"
    local args=(-s --connect-timeout 3 --max-time 20 -o "$WORK/out.json"
                -w '%{http_code}' -X "$method" "$BASE$path")

    [ -n "$body" ] && args+=(-H 'Content-Type: application/json' --data-binary "@$body")
    [ -n "$TOKEN" ] && args+=(-H "Authorization: Bearer $TOKEN")
    [ -n "$JAR" ] && args+=(-b "$JAR" -c "$JAR")

    curl "${args[@]}"
}

field() { # field "['data']['id']" -> prints the value, or - if absent
    python3 -c "
import json, sys
try:
    d = json.load(open('$WORK/out.json'))
    print(eval(sys.argv[1]))
except Exception:
    print('-')
" "$1"
}

absent() { # absent <needle> -> 'absent' or 'present'
    grep -q "$1" "$WORK/out.json" && echo present || echo absent
}

# Registered accounts have to be unique per run. The PID alone is not enough:
# it is recycled, and a previous run's account may still exist, which turns
# "register valid" into a duplicate-email 422.
STAMP="$$-$(date +%s)"

# Removes the accounts this run registered. There is no delete-user endpoint by
# design, so this needs database access; it is skipped when unavailable.
cleanup_accounts() {
    command -v mysql > /dev/null 2>&1 || return 1
    [ -n "${DB_NAME:-}" ] && [ -n "${DB_USER:-}" ] && [ -n "${DB_PASS:-}" ] || return 1

    MYSQL_PWD="$DB_PASS" mysql \
        -u "$DB_USER" \
        -h "${DB_HOST:-127.0.0.1}" \
        -P "${DB_PORT:-3306}" \
        "$DB_NAME" \
        -e "DELETE FROM users WHERE email LIKE '%${STAMP}@example.com';" 2> /dev/null
}

# JSON bodies are written to files so the shell never has to escape quotes.
python3 - "$WORK" "$STAMP" "$PASSWORD" <<'PY'
import json, sys
work, stamp, pw = sys.argv[1], sys.argv[2], sys.argv[3]

def write(name, payload):
    with open(f"{work}/{name}.json", "w") as handle:
        json.dump(payload, handle)

write("login_admin", {"email": "saif@example.com", "password": pw})
write("login_user", {"email": "priya@example.com", "password": pw})
write("login_third", {"email": "rahul@example.com", "password": pw})
write("login_wrong_pw", {"email": "saif@example.com", "password": "definitely-not-it"})
write("login_unknown", {"email": "nobody@example.com", "password": "definitely-not-it"})
write("login_empty", {})

verify_email = f"verify-{stamp}@example.com"
write("reg_valid", {"name": "Verify Person", "email": verify_email, "password": pw})
write("reg_duplicate", {"name": "Duplicate", "email": verify_email, "password": pw})
write("reg_as_admin", {"name": "Sneaky", "email": f"verify-admin-{stamp}@example.com",
                       "password": pw, "role": "admin"})
write("reg_short_pw", {"name": "Weak", "email": f"verify-weak-{stamp}@example.com", "password": "ab1"})
write("reg_letters_pw", {"name": "Weak", "email": f"verify-letters-{stamp}@example.com",
                         "password": "abcdefghijk"})
write("reg_long_pw", {"name": "Long", "email": f"verify-long-{stamp}@example.com", "password": "a1" * 40})
write("reg_bad_email", {"name": "Bad", "email": "not-an-email", "password": pw})
write("reg_no_name", {"email": f"verify-noname-{stamp}@example.com", "password": pw})
write("login_new", {"email": verify_email, "password": pw})

write("task_valid", {"title": "Verification fixture", "description": "Created by verify-api.sh",
                     "status": "todo", "priority": "high", "due_date": "2026-12-01"})
write("task_minimal", {"title": "Minimal fixture"})
write("task_spoof_owner", {"title": "Spoofed owner attempt", "user_id": 1})
write("task_admin_assigns", {"title": "Assigned by admin", "user_id": 3})
write("task_reassign", {"user_id": 1})
write("task_status_only", {"status": "done"})
write("task_invalid", {"title": "ab", "status": "urgent", "priority": "nope",
                       "due_date": "2026-02-31", "user_id": 999})
write("task_put_partial", {"title": "Replaced by PUT"})
PY

printf 'Verifying %s\n' "$BASE"

# ---------------------------------------------------------------- availability
section "Server is reachable"
check "GET /health is public" 200 "$(req GET /health)"

# ------------------------------------------------------------- authentication
section "Unauthenticated requests are rejected"
for route in "/tasks" "/tasks/1" "/users" "/auth/me"; do
    check "GET $route" 401 "$(req GET "$route")"
done
check "  reason code is no_token" "no_token" "$(field "d['code']")"
check "POST /tasks" 401 "$(req POST /tasks "$WORK/task_valid.json")"
check "DELETE /tasks/1" 401 "$(req DELETE /tasks/1)"

section "Login failures reveal nothing about which emails exist"
check "wrong password" 401 "$(req POST /auth/login "$WORK/login_wrong_pw.json")"
wrong_message="$(field "d['message']")"
check "unregistered email" 401 "$(req POST /auth/login "$WORK/login_unknown.json")"
check "  identical message" "$wrong_message" "$(field "d['message']")"
check "missing credentials" 422 "$(req POST /auth/login "$WORK/login_empty.json")"

section "Login issues a token pair"
JAR="$WORK/admin.jar"
check "admin login" 200 "$(req POST /auth/login "$WORK/login_admin.json")"
check "  role is admin" "admin" "$(field "d['data']['user']['role']")"
check "  access token present" "True" "$(field "d['data']['access_token'].count('.') == 2")"
check "  expires in 15 minutes" "900" "$(field "d['data']['expires_in']")"
check "  password_hash not leaked" "absent" "$(absent password_hash)"
check "  refresh cookie is httpOnly" "yes" "$(grep -q '#HttpOnly' "$JAR" && echo yes || echo no)"
check "  cookie scoped to /api/auth" "yes" "$(grep -q '/api/auth' "$JAR" && echo yes || echo no)"
ADMIN_TOKEN="$(field "d['data']['access_token']")"

JAR="$WORK/user.jar"
check "user login" 200 "$(req POST /auth/login "$WORK/login_user.json")"
check "  role is user" "user" "$(field "d['data']['user']['role']")"
USER_TOKEN="$(field "d['data']['access_token']")"
USER_ID="$(field "d['data']['user']['id']")"
JAR=""

# ------------------------------------------------------------- authorisation
section "Task visibility follows role"
TOKEN="$ADMIN_TOKEN"
check "admin lists every task" 200 "$(req GET /tasks)"
ADMIN_TOTAL="$(field "d['meta']['total']")"
check "  sees more than one owner" "True" "$(field "len({t['user_id'] for t in d['data']}) > 1")"

TOKEN="$USER_TOKEN"
check "user lists only their own" 200 "$(req GET /tasks)"
USER_TOTAL="$(field "d['meta']['total']")"
check "  every row belongs to them" "True" "$(field "all(t['user_id'] == $USER_ID for t in d['data'])")"
check "  fewer rows than the admin" "True" "$([ "$USER_TOTAL" -lt "$ADMIN_TOTAL" ] && echo True || echo False)"
check "cannot widen scope with ?user_id" 200 "$(req GET "/tasks?user_id=1")"
check "  still only their own rows" "True" "$(field "all(t['user_id'] == $USER_ID for t in d['data'])")"

section "Ownership is enforced with 403"
TOKEN="$USER_TOKEN"
check "read another user's task" 403 "$(req GET /tasks/1)"
check "update another user's task" 403 "$(req PATCH /tasks/1 "$WORK/task_status_only.json")"
check "delete another user's task" 403 "$(req DELETE /tasks/1)"
check "list accounts (admin only)" 403 "$(req GET /users)"

TOKEN="$ADMIN_TOKEN"
check "admin reads any task" 200 "$(req GET /tasks/1)"
check "admin lists accounts" 200 "$(req GET /users)"
check "  no password_hash in the list" "absent" "$(absent password_hash)"
check "  roles are exposed" "True" "$(field "all('role' in u for u in d['data'])")"

section "Task owner cannot be spoofed"
TOKEN="$USER_TOKEN"
check "user creates claiming another owner" 201 "$(req POST /tasks "$WORK/task_spoof_owner.json")"
check "  owner forced to the caller" "$USER_ID" "$(field "d['data']['user_id']")"
SPOOF_ID="$(field "d['data']['id']")"
check "user tries to reassign own task" 403 "$(req PATCH "/tasks/$SPOOF_ID" "$WORK/task_reassign.json")"

TOKEN="$ADMIN_TOKEN"
check "admin assigns an owner" 201 "$(req POST /tasks "$WORK/task_admin_assigns.json")"
check "  owner honoured" "3" "$(field "d['data']['user_id']")"
ASSIGNED_ID="$(field "d['data']['id']")"

# ------------------------------------------------------------- registration
section "Registration"
check "valid registration" 201 "$(req POST /auth/register "$WORK/reg_valid.json")"
check "  role forced to user" "user" "$(field "d['data']['user']['role']")"
check "  signed in immediately" "True" "$(field "len(d['data']['access_token']) > 0")"
check "  password_hash not leaked" "absent" "$(absent password_hash)"
check "role=admin in the body" 201 "$(req POST /auth/register "$WORK/reg_as_admin.json")"
check "  privilege escalation blocked" "user" "$(field "d['data']['user']['role']")"
check "duplicate email" 422 "$(req POST /auth/register "$WORK/reg_duplicate.json")"
check "  names the email field" "True" "$(field "'email' in d['errors']")"
check "password too short" 422 "$(req POST /auth/register "$WORK/reg_short_pw.json")"
check "password without a digit" 422 "$(req POST /auth/register "$WORK/reg_letters_pw.json")"
check "password beyond bcrypt's 72 bytes" 422 "$(req POST /auth/register "$WORK/reg_long_pw.json")"
check "malformed email" 422 "$(req POST /auth/register "$WORK/reg_bad_email.json")"
check "missing name" 422 "$(req POST /auth/register "$WORK/reg_no_name.json")"

check "new account can log in" 200 "$(req POST /auth/login "$WORK/login_new.json")"
TOKEN="$(field "d['data']['access_token']")"
check "  starts with no tasks" "0" "$(req GET /tasks > /dev/null; field "d['meta']['total']")"

# ------------------------------------------------------------- token handling
section "Forged and expired tokens are rejected"
# Tamper with the first character of the signature, not the last. A 32-byte
# HMAC encodes to 43 base64url characters, so the final character holds two
# unused bits and four different characters decode to the same signature —
# editing it leaves the token genuinely valid about one run in sixteen.
signature="${ADMIN_TOKEN##*.}"
unsigned="${ADMIN_TOKEN%.*}"
swapped="A"
[ "${signature:0:1}" = "A" ] && swapped="B"
TOKEN="${unsigned}.${swapped}${signature:1}"
check "tampered signature" 401 "$(req GET /tasks)"
check "  reason is token_invalid" "token_invalid" "$(field "d['code']")"

TOKEN="$(python3 -c "
import base64, json
enc = lambda o: base64.urlsafe_b64encode(json.dumps(o).encode()).decode().rstrip('=')
print(enc({'alg': 'none', 'typ': 'JWT'}) + '.' +
      enc({'sub': 1, 'email': 'saif@example.com', 'role': 'admin', 'exp': 9999999999}) + '.')
")"
check "alg=none forgery" 401 "$(req GET /tasks)"

TOKEN="not.a.jwt"
check "structurally invalid token" 401 "$(req GET /tasks)"

TOKEN="$(python3 -c "
import base64, hashlib, hmac, json, time
enc = lambda b: base64.urlsafe_b64encode(b).decode().rstrip('=')
header = enc(json.dumps({'alg': 'HS256', 'typ': 'JWT'}).encode())
claims = enc(json.dumps({'sub': 1, 'email': 'saif@example.com', 'role': 'admin',
                         'iat': int(time.time()) - 3600, 'exp': int(time.time()) - 60}).encode())
sig = enc(hmac.new('$SECRET'.encode(), f'{header}.{claims}'.encode(), hashlib.sha256).digest())
print(f'{header}.{claims}.{sig}')
")"
check "correctly signed but expired" 401 "$(req GET /tasks)"
check "  reason tells the client to refresh" "token_expired" "$(field "d['code']")"
TOKEN=""

section "Refresh rotation"
JAR="$WORK/rotate.jar"
req POST /auth/login "$WORK/login_third.json" > /dev/null
check "refresh with a valid cookie" 200 "$(req POST /auth/refresh)"
check "  a new access token is issued" "True" "$(field "len(d['data']['access_token']) > 0")"
check "  the account comes back with it" "rahul@example.com" "$(field "d['data']['user']['email']")"
JAR=""
check "refresh without a cookie" 401 "$(req POST /auth/refresh)"
check "  reason is refresh_missing" "refresh_missing" "$(field "d['code']")"

# Rotation assumes one client holds one token, which a browser breaks routinely:
# two tabs restoring a session both present the same cookie. Within the grace
# window that is accepted rather than treated as theft.
section "A just-rotated token still works briefly (multi-tab, retries)"
JAR="$WORK/grace.jar"
req POST /auth/login "$WORK/login_third.json" > /dev/null
cp "$WORK/grace.jar" "$WORK/grace_old.jar"
check "first use rotates normally" 200 "$(req POST /auth/refresh)"
JAR="$WORK/grace_old.jar"
check "immediate replay is accepted" 200 "$(req POST /auth/refresh)"
check "  and returns a working session" "True" "$(field "len(d['data']['access_token']) > 0")"
JAR="$WORK/grace.jar"
check "the lineage is still alive" 200 "$(req POST /auth/refresh)"
JAR=""

section "Concurrent refreshes do not burn the session"
JAR="$WORK/race.jar"
req POST /auth/login "$WORK/login_third.json" > /dev/null
: > "$WORK/race_codes.txt"
for i in 1 2 3; do
    curl -s --connect-timeout 3 --max-time 20 -b "$JAR" -c "$WORK/race$i.jar" \
        -o "$WORK/race$i.json" -w "%{http_code}\n" -X POST "$BASE/auth/refresh" \
        >> "$WORK/race_codes.txt" &
done
wait
check "all three succeed" "3" "$(grep -c '^200$' "$WORK/race_codes.txt")"
check "none rejected as reuse" "0" "$(grep -c '^401$' "$WORK/race_codes.txt")"
JAR="$WORK/race1.jar"
check "a rotated cookie still works afterwards" 200 "$(req POST /auth/refresh)"
JAR=""

section "Replay after the grace window is treated as theft"
JAR="$WORK/theft.jar"
req POST /auth/login "$WORK/login_third.json" > /dev/null
cp "$WORK/theft.jar" "$WORK/theft_old.jar"
check "first use rotates normally" 200 "$(req POST /auth/refresh)"
printf '  %swaiting %ss for the grace window to close%s\n' "$DIM" "$((GRACE + 2))" "$OFF"
sleep "$((GRACE + 2))"
JAR="$WORK/theft_old.jar"
check "stale replay is rejected" 401 "$(req POST /auth/refresh)"
check "  detected as reuse" "refresh_reused" "$(field "d['code']")"
JAR="$WORK/theft.jar"
check "the whole family is burned" 401 "$(req POST /auth/refresh)"
JAR=""

section "Logout"
JAR="$WORK/logout.jar"
req POST /auth/login "$WORK/login_third.json" > /dev/null
check "logout" 204 "$(req POST /auth/logout)"
check "refresh after logout" 401 "$(req POST /auth/refresh)"
JAR=""

section "Current account"
TOKEN="$ADMIN_TOKEN"
check "GET /auth/me" 200 "$(req GET /auth/me)"
check "  returns the caller" "saif@example.com" "$(field "d['data']['email']")"
check "  no password_hash" "absent" "$(absent password_hash)"

# ------------------------------------------------------------------- CRUD
section "Task CRUD"
TOKEN="$ADMIN_TOKEN"
check "create with every field" 201 "$(req POST /tasks "$WORK/task_valid.json")"
CREATED_ID="$(field "d['data']['id']")"
check "  owner nested in the response" "True" "$(field "'owner' in d['data']")"
check "  id is an integer, not a string" "True" "$(field "isinstance(d['data']['id'], int)")"
check "create with defaults applied" 201 "$(req POST /tasks "$WORK/task_minimal.json")"
check "  status defaults to todo" "todo" "$(field "d['data']['status']")"
check "  priority defaults to medium" "medium" "$(field "d['data']['priority']")"
check "  due_date defaults to null" "None" "$(field "d['data']['due_date']")"
MINIMAL_ID="$(field "d['data']['id']")"

check "read one" 200 "$(req GET "/tasks/$CREATED_ID")"
check "PATCH changes only what is sent" 200 "$(req PATCH "/tasks/$CREATED_ID" "$WORK/task_status_only.json")"
check "  status updated" "done" "$(field "d['data']['status']")"
check "  title preserved" "Verification fixture" "$(field "d['data']['title']")"
check "  due date preserved" "2026-12-01" "$(field "d['data']['due_date']")"
check "PUT resets omitted fields" 200 "$(req PUT "/tasks/$CREATED_ID" "$WORK/task_put_partial.json")"
check "  description reset" "None" "$(field "d['data']['description']")"
check "  due date reset" "None" "$(field "d['data']['due_date']")"
check "  status reset to default" "todo" "$(field "d['data']['status']")"

check "delete" 204 "$(req DELETE "/tasks/$MINIMAL_ID")"
check "delete again is 404" 404 "$(req DELETE "/tasks/$MINIMAL_ID")"

section "Filtering, search and pagination"
check "filter by status" 200 "$(req GET "/tasks?status=todo")"
check "  every row matches" "True" "$(field "all(t['status'] == 'todo' for t in d['data'])")"
check "filter by priority" 200 "$(req GET "/tasks?priority=high")"
check "  every row matches" "True" "$(field "all(t['priority'] == 'high' for t in d['data'])")"
check "search matches a real term" 200 "$(req GET "/tasks?search=endpoint")"
check "  found something" "True" "$(field "d['meta']['total'] >= 1")"
check "literal % is not a wildcard" 200 "$(req GET "/tasks?search=%25")"
check "  matches nothing" "0" "$(field "d['meta']['total']")"
check "literal _ is not a wildcard" 200 "$(req GET "/tasks?search=_")"
check "  matches nothing" "0" "$(field "d['meta']['total']")"
check "pagination metadata" 200 "$(req GET "/tasks?per_page=3&page=2")"
check "  reports the requested page" "2" "$(field "d['meta']['page']")"
check "  honours per_page" "3" "$(field "d['meta']['per_page']")"
check "  total_pages is consistent" "True" \
    "$(field "d['meta']['total_pages'] == -(-d['meta']['total'] // d['meta']['per_page'])")"
check "per_page is capped" 200 "$(req GET "/tasks?per_page=9999")"
check "  clamped to 50" "50" "$(field "d['meta']['per_page']")"

section "Input validation and SQL injection defences"
check "invalid payload" 422 "$(req POST /tasks "$WORK/task_invalid.json")"
# A parenthesised tuple, not a set literal: a brace list containing commas is
# brace-expanded by the shell before python ever sees it.
check "  reports every bad field" "True" \
    "$(field "all(k in d['errors'] for k in ('title','status','priority','due_date','user_id'))")"
check "  rejects 2026-02-31 as a real date" "True" "$(field "'due_date' in d['errors']")"
check "unknown status filter" 422 "$(req GET "/tasks?status=urgent")"
check "page zero" 422 "$(req GET "/tasks?page=0")"
check "sort column outside the whitelist" 422 "$(req GET "/tasks?sort=password_hash")"
check "ORDER BY injection attempt" 422 "$(req GET "/tasks?sort=title;%20DROP%20TABLE%20tasks")"
check "  table survived the attempt" 200 "$(req GET /tasks)"

section "HTTP semantics"
check "unknown task" 404 "$(req GET /tasks/99999)"
check "non-numeric id" 404 "$(req GET /tasks/abc)"
check "unknown route" 404 "$(req GET /nope)"
check "wrong verb on a known path" 405 "$(req DELETE /tasks)"
check "  includes an Allow header" "yes" "$(curl -s --max-time 10 -D- -o /dev/null \
    -X DELETE "$BASE/tasks" -H "Authorization: Bearer $ADMIN_TOKEN" | grep -qi '^allow:' && echo yes || echo no)"
printf '{bad json' > "$WORK/broken.json"
check "malformed JSON body" 400 "$(req POST /tasks "$WORK/broken.json")"

section "CORS"
preflight="$(curl -s --max-time 10 -D- -o /dev/null -X OPTIONS "$BASE/tasks" \
    -H 'Origin: http://localhost:5173' -H 'Access-Control-Request-Method: PATCH')"
check "preflight is 204" "yes" "$(echo "$preflight" | grep -q '204' && echo yes || echo no)"
check "echoes a specific origin" "yes" \
    "$(echo "$preflight" | grep -qi 'access-control-allow-origin: http' && echo yes || echo no)"
check "allows credentials" "yes" \
    "$(echo "$preflight" | grep -qi 'access-control-allow-credentials: true' && echo yes || echo no)"

# ------------------------------------------------------------------ teardown
section "Cleaning up fixtures"
TOKEN="$ADMIN_TOKEN"
for id in "$CREATED_ID" "$SPOOF_ID" "$ASSIGNED_ID"; do
    [ -n "$id" ] && [ "$id" != "-" ] && req DELETE "/tasks/$id" > /dev/null
done
echo "  removed the tasks this run created"

if cleanup_accounts; then
    echo "  removed the accounts this run registered"
else
    echo "  accounts named verify-% remain; clear them with:"
    echo "    DELETE FROM users WHERE email LIKE 'verify-%';"
fi

printf '\n%s passed, %s failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
