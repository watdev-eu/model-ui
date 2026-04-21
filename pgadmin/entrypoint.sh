#!/bin/sh
set -e

cat > /pgadmin4/servers.json <<EOF
{
  "Servers": {
    "1": {
      "Name": "WatDev DB",
      "Group": "Servers",
      "Host": "${DB_HOST}",
      "Port": ${DB_PORT},
      "MaintenanceDB": "${DB_NAME}",
      "Username": "${DB_USER}",
      "SharedUsername": "${DB_USER}",
      "PassFile": "/var/lib/pgadmin/pgpass",
      "SavePassword": true,
      "SSLMode": "prefer",
      "Shared": true,
      "Comment": "Auto-configured production database"
    }
  }
}
EOF

PGPASS_FILE="/var/lib/pgadmin/pgpass"
mkdir -p /var/lib/pgadmin
printf '%s:%s:%s:%s:%s\n' \
  "${DB_HOST}" "${DB_PORT}" "*" "${DB_USER}" "${DB_PASS}" \
  > "${PGPASS_FILE}"
chmod 0600 "${PGPASS_FILE}"

exec /entrypoint.sh