#!/bin/sh
set -e

# ── 1. Render servers.json from template ──────────────────────────────────────
# Only the listed variables are substituted; all other $ signs are left alone.
envsubst '${DB_HOST} ${DB_PORT} ${DB_NAME} ${DB_USER}' \
  < /pgadmin4/servers.json.template \
  > /pgadmin4/servers.json

# ── 2. Write pgpass from env vars ─────────────────────────────────────────────
# Written to a permanent path so servers.json's PassFile can reference it
# directly (pgAdmin does not expand ~ in PassFile).
PGPASS_FILE="/var/lib/pgadmin/pgpass"
mkdir -p /var/lib/pgadmin
printf '%s:%s:%s:%s:%s\n' \
  "${DB_HOST}" "${DB_PORT}" "*" "${DB_USER}" "${DB_PASS}" \
  > "${PGPASS_FILE}"
chmod 0600 "${PGPASS_FILE}"

# ── 3. Hand off to the official pgAdmin entrypoint ────────────────────────────
# Our script is at /startup.sh; the original base-image entrypoint lives at
# /entrypoint.sh and is untouched, so we can safely call it here.
exec /entrypoint.sh
