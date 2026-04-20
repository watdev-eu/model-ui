# Production Deployment Guide

## File layout

```
project-root/
├── docker-compose.prod.yml      ← production compose file
├── .env                         ← copy from .env.prod.example and fill in secrets
├── pgadmin/
│   ├── Dockerfile               ← builds custom pgAdmin image
│   ├── entrypoint.sh            ← renders servers.json + pgpass from env at startup
│   └── servers.json.template    ← DB connection template (env vars substituted at runtime)
├── Dockerfile
├── db/
│   └── Dockerfile
└── src/
```

---

## First-time setup

### 1. Create your `.env`

```bash
cp .env.prod.example .env
# Edit .env – fill in all "change-me" values
```

### 2. Create the external Docker network

Traefik and the app services share a network called `proxy`.
Create it once on the host (it persists across `docker compose down`):

```bash
docker network create proxy
```

### 3. Deploy

```bash
docker compose -f docker-compose.prod.yml --env-file .env up -d
```

---

## Keycloak configuration

In your Keycloak admin console you need to:

1. **Add a Valid Redirect URI** for the pgAdmin OAuth2 callback:
   ```
   https://toolbox.watdev.eu/pgadmin/oauth2/authorize
   ```

2. **Add `toolbox.watdev.eu` to Web Origins** (for CORS):
   ```
   https://toolbox.watdev.eu
   ```

3. **Create a client role called `admin`** inside the `watdev-toolbox` client
   (Client → Roles → Create role → `admin`).

4. **Assign that role** to every user who should have pgAdmin access
   (Users → [user] → Role Mappings → Client Roles → `watdev-toolbox` → `admin`).

> **How the role check works:** pgAdmin's `OAUTH2_ADDITIONAL_CLAIMS` setting
> verifies that the user's JWT contains
> `resource_access.watdev-toolbox.roles` including `"admin"`.
> Users without the role are denied at the OAuth2 layer before they ever
> reach pgAdmin.

---

## DB server registration in pgAdmin

`servers.json.template` is rendered at container startup by `entrypoint.sh`
using `envsubst`, so the DB connection always reflects your `.env` values —
no manual editing required.

However, pgAdmin only **seeds** the server list from `servers.json` on the
very first start (when the `pgadmin_data` volume is empty). Subsequent
restarts re-render the file, but pgAdmin ignores it once the volume has data.

If you need to re-seed (e.g. after changing `DB_HOST` or `DB_NAME`):

```bash
docker compose -f docker-compose.prod.yml down -v   # ⚠ removes pgadmin_data
docker compose -f docker-compose.prod.yml up -d
```

This also clears saved queries and user preferences. For a connection-only
change you can instead log in as the bootstrap admin and edit the server
manually.

---

## Staging / Let's Encrypt rate limits

While testing, **enable** the staging CA line in `docker-compose.prod.yml`
by uncommenting it:

```yaml
# Before (production – line is commented out):
# - "--certificatesresolvers.letsencrypt.acme.caserver=https://acme-staging-v02.api.letsencrypt.org/directory"

# During testing – uncomment it:
- "--certificatesresolvers.letsencrypt.acme.caserver=https://acme-staging-v02.api.letsencrypt.org/directory"
```

Staging certificates are not trusted by browsers but won't consume your
production rate-limit quota. **Comment it back out** once everything works,
then delete the `letsencrypt` volume to force a fresh certificate issuance:

```bash
docker compose -f docker-compose.prod.yml down
docker volume rm <project>_letsencrypt
docker compose -f docker-compose.prod.yml up -d
```

---

## Differences from the test-server compose file

| Aspect | Test server | Production |
|---|---|---|
| Compose file | `docker-compose.yml` | `docker-compose.prod.yml` |
| SSL | None | Let's Encrypt via Traefik |
| Port 80/443 | Not used | Traefik listens on both |
| DB port | Exposed on `5432` | Internal only (no host binding) |
| pgAdmin | Not included | `/pgadmin` via Traefik |
| Networks | Default bridge | `proxy` (public) + `internal` (DB) |
