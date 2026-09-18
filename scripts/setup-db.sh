#!/usr/bin/env bash
#
# Creates the database and application user, then loads the schema.
#
#   scripts/setup-db.sh              # schema only, suitable for any environment
#   scripts/setup-db.sh --with-demo  # plus the development demo accounts
#
# Credentials come from .env; only the administrator password is prompted for,
# and nothing is stored. Use --with-demo for local development only: it creates
# accounts with a published password.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Reading .env keeps passwords off the command line, where they would be visible
# in the process list and the shell history.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . ./.env
    set +a
fi

DB_NAME="${DB_NAME:?DB_NAME is not set. Copy .env.example to .env and fill it in.}"
DB_USER="${DB_USER:?DB_USER is not set. Copy .env.example to .env and fill it in.}"
DB_PASS="${DB_PASS:?DB_PASS is not set. Copy .env.example to .env and fill it in.}"
ADMIN_USER="${ADMIN_USER:-root}"

WITH_DEMO=0
if [ "${1:-}" = "--with-demo" ]; then
    WITH_DEMO=1
fi

echo "Creating database '${DB_NAME}' and user '${DB_USER}'."
echo "Enter the MySQL password for '${ADMIN_USER}':"

mysql -u "${ADMIN_USER}" -p <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

for file in db/init/*.sql; do
    echo "Loading ${file}"
    MYSQL_PWD="${DB_PASS}" mysql -u "${DB_USER}" "${DB_NAME}" < "${file}"
done

if [ "$WITH_DEMO" -eq 1 ]; then
    for file in db/seed/*.sql; do
        echo "Loading ${file}  (development data)"
        MYSQL_PWD="${DB_PASS}" mysql -u "${DB_USER}" "${DB_NAME}" < "${file}"
    done
fi

echo
MYSQL_PWD="${DB_PASS}" mysql -u "${DB_USER}" "${DB_NAME}" -e "
    SELECT COUNT(*) AS users, (SELECT COUNT(*) FROM tasks) AS tasks FROM users;"

if [ "$WITH_DEMO" -eq 0 ]; then
    echo
    echo "Schema loaded. Create the first administrator with:"
    echo "  php scripts/create-admin.php"
fi
