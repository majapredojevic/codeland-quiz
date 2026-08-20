# Deployment and environment

> Status: Production architecture implemented and locally verifiable. A real
> hostname, CA-issued certificate, deployment secrets, backup policy and
> real-browser verification are still operator responsibilities.

## Architecture

Production uses the standalone `compose.production.yaml`; it is deliberately
not an override of the development Compose file. This prevents development
port publications and phpMyAdmin from being inherited through Compose merge
semantics.

```text
Browser -- HTTPS/WSS --> Nginx :80/:443
                             |-- /             Angular static files
                             |-- /api          OpenSwoole :9501
                             |-- /media        OpenSwoole :9501
                             |-- /health       OpenSwoole :9501
                             |-- /ready        OpenSwoole :9501
                             `-- /ws           OpenSwoole WebSocket :9501

Nginx -- edge network --> backend -- internal database network --> MySQL :3306
```

Only Nginx publishes host ports: `80` and `443`. Backend port `9501` is exposed
only to the Docker edge network. MySQL port `3306` is available only on the
internal database network. Production contains no phpMyAdmin service. Nginx
terminates TLS; OpenSwoole intentionally remains internal HTTP/WebSocket.

The backend remains `worker_num=1`. Its participant connection registry is
process-local, so increasing workers or backend instances without shared
registry and fan-out state would break WebSocket correctness. The implemented
runtime includes single-worker heartbeat, lifecycle reconciliation, readiness
and private metrics. Redis, a PDO pool, TaskWorkers and multi-instance
coordination remain deliberately absent because the recorded profile did not
justify them.

## Development remains separate

Local development is unchanged:

```bash
docker compose up -d
cd frontend
npm start
```

The development Compose file still publishes OpenSwoole on `9501`, MySQL on
`3307`, and phpMyAdmin on `8081`. Angular's development proxy forwards `/api`,
`/media`, and `/ws` to OpenSwoole. Development does not require TLS or local
certificates.

## Production inputs and secrets

Copy `.env.production.example` to the gitignored `.env.production`, then
replace every `replace-with-...` value. The file is a pragmatic secret store
for this single-host Compose deployment: make it readable only by the
deployment account (for example, `chmod 600 .env.production` on Linux), keep it
out of backups that are not encrypted, and never send it through chat or commit
it.

Generate three independent values of at least 32 random characters for
`JWT_SECRET`, `REFRESH_TOKEN_HASH_KEY`, and `PARTICIPANT_TOKEN_SECRET`.
`DB_PASSWORD` must be at least 16 random characters, and
`MYSQL_ROOT_PASSWORD` must be a separate strong value. Production startup
rejects missing/example token secrets, reused token secrets, development DB
credentials, insecure cookie settings, non-HTTPS application/WebSocket
origins, and a missing trusted-proxy configuration. Error messages never print
secret values.

Keep `DB_DATABASE=codeland_quiz` unless the tracked MySQL initialization SQL is
updated at the same time; the current schema script explicitly creates and
selects that database.

Set these deployment-specific values consistently:

- `SERVER_NAME=quiz.example.com`
- `CODELAND_IMAGE_TAG` to an immutable release identifier or Git commit SHA
- `APP_URL=https://quiz.example.com`
- `WS_ALLOWED_ORIGINS=https://quiz.example.com`
- `TLS_CERTIFICATE_PATH` to the host's full certificate chain
- `TLS_PRIVATE_KEY_PATH` to the corresponding host private key

The certificate and key are bind-mounted read-only at
`/etc/nginx/tls/fullchain.pem` and `/etc/nginx/tls/privkey.pem`. Keep both files
outside the repository. A real deployment requires a currently valid,
CA-issued certificate matching `SERVER_NAME`; do not treat a local self-signed
certificate as production verification.

The backend service receives an explicit allowlist of environment variables.
It does not receive `MYSQL_ROOT_PASSWORD` or the TLS host paths. Compose itself
needs the root password only to initialize/run MySQL.

Production mounts only `docker/mysql/init/001_schema.sql`. It deliberately
does not mount the initialization directory or `002_seed_admin.sql`; therefore
a fresh production database has the schema but no application administrator.
The fixed development seed remains available only through `docker-compose.yml`
and its credentials must never be reused outside local development.

## Production commands

From the repository root:

```bash
docker compose --env-file .env.production -f compose.production.yaml config
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d
```

### One-time initial administrator bootstrap

After the fresh stack reports MySQL and backend healthy, create the first
administrator from an interactive operator terminal:

```bash
docker compose --env-file .env.production -f compose.production.yaml exec backend php scripts/bootstrap-initial-admin.php --name="Deployment Administrator" --email="operator-supplied@example.com"
```

The command prompts for the bootstrap password and confirmation with terminal
echo disabled; the password is never a command-line argument or environment
variable. For controlled automation, `exec -T` may instead receive exactly two
stdin lines containing the password and confirmation from an operator-managed,
untracked secret source. Do not store that source in this repository.

The CLI:

- is available only with `APP_ENV=production` and requires container/shell
  access rather than network access;
- normalizes the supplied name/email using the staff rules;
- enforces the normal application password policy and bcrypt hashing;
- transactionally creates one active `ADMIN` with
  `must_change_password=true`;
- refuses by default if any administrator already exists, without modifying
  or creating another account.

Log in through the configured HTTPS hostname immediately afterward and finish
the existing required-password-change flow. Additional staff accounts are then
created through the authenticated Admin workflow. There is no default
production administrator and no recovery/force flag in the bootstrap CLI.

`config` prints resolved environment values, including credentials, so run it
only in a trusted terminal and do not redirect its output to an unprotected
file. Inspect the resolved `ports` entries: only the Nginx mappings for 80 and
443 may be present.

Operational commands:

```bash
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs nginx backend mysql
docker compose --env-file .env.production -f compose.production.yaml stop
docker compose --env-file .env.production -f compose.production.yaml down
docker compose --env-file .env.production -f compose.production.yaml build --pull
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Normal `down` preserves the named volumes. Do not add `--volumes` during
ordinary operations: `mysql_data` contains the database and `question_images`
contains uploaded Question images. Back up both volumes using the host's
documented backup process before upgrades.

## Pinned production images and container policy

The tested production build inputs are:

| Component | Image/version |
| --- | --- |
| Angular build | `node:24.19.0-alpine3.23` |
| Nginx | `nginx:1.30.4-alpine3.24` |
| Composer build | `composer:2.10.2` |
| PHP runtime | `php:8.3.32-cli-trixie` |
| OpenSwoole extension | `26.2.0` |
| MySQL | `mysql:8.4.9` |

Compose tags the two locally built application images with
`CODELAND_IMAGE_TAG`; the example intentionally requires an operator-supplied
immutable release identifier. Use a new immutable release/commit tag for each
source state rather than reusing an existing tag. No production service
references `latest`.

The Angular build is copied into the Nginx image; Node, `node_modules`, the
Angular CLI server, and frontend source do not enter the Nginx runtime. The
backend copies source and a Composer `--no-dev --classmap-authoritative` vendor
tree into its image, removes compiler/build packages, and runs as non-root
`www-data`. Backend root filesystem is read-only except for `/tmp` and the
Question image volume. Nginx also uses a read-only root with small tmpfs mounts.
Both services enable `no-new-privileges`; the backend drops all Linux
capabilities.

The backend receives SIGTERM and has a 20-second Compose stop grace. Its
`nofile` soft/hard limit is 8192, while OpenSwoole `max_conn` is 4096. The
application WebSocket ceiling remains 2000. Docker health checks the backend's
`/ready` route, so Nginx starts only after presence reconciliation, the runtime
timer and database readiness succeed.

Production PHP has `display_errors=Off`, `display_startup_errors=Off`, and
`log_errors=On`. Nginx ordinary logs exclude Cookie and Authorization header
values and log the normalized URI without query arguments. Do not enable debug
logging or add tokens/cookies to log formats.

## Nginx routing, TLS, and cache policy

Port 80 returns permanent `308` redirects to the same host, request URI, and
query string over HTTPS. Port 443 supports only TLS 1.2 and TLS 1.3; SSLv3,
TLS 1.0, TLS 1.1, session tickets, and TLS 1.3 early data are not enabled.

Nginx preserves `/api`, `/media`, `/health`, `/ready`, and `/ws` paths when proxying to
`backend:9501`; the `proxy_pass` target intentionally has no trailing slash.
WebSockets use upstream HTTP/1.1 and forward Upgrade/Connection correctly.
Read and send timeouts are both 150 seconds. The 25-second application
heartbeat supplies upstream traffic for live clients; the application declares
a participant stale at 75 seconds, and OpenSwoole transport cleanup is a later
120-second safety net.

`/internal/metrics` is deliberately not proxied: an exact Nginx location
returns 404 before the SPA fallback. It remains available only by reaching the
unpublished backend service from `docker exec` or the internal Docker network.

Angular 22 output is served from `dist/frontend/browser`. Existing static files
are served directly, while an unknown frontend route falls back to
`/index.html`. The API, media, health, and WebSocket locations take precedence
and can never fall through to the SPA. Hashed JavaScript/CSS receives a
one-year immutable cache policy; `index.html` and all API/health responses use
`no-store`. Backend-managed Question images retain their generated-filename
immutable cache response.

The frontend already uses same-origin `/api` and `/media` URLs. It constructs
the socket from `window.location.protocol` and `window.location.host`, so an
HTTPS page connects to `wss://<same-host>/ws/game` without mixed content. No
CORS header is added.

## Browser security headers and CSP

Nginx adds these headers to static and proxied HTTPS responses:

- `Content-Security-Policy`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), geolocation=(), microphone=(), payment=(), usb=()`
- `X-Frame-Options: DENY` (legacy defense in addition to CSP `frame-ancestors`)

The CSP is:

```text
default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' wss://<request-host>; form-action 'self'
```

`script-src` does not allow `unsafe-inline` or `unsafe-eval`. Angular's
production `inlineCritical` transform is disabled so the generated index does
not contain an inline `onload` handler. `style-src 'unsafe-inline'` is the one
documented relaxation: Angular/Material inject component styles and runtime
overlay style attributes. `img-src` permits same-origin Koda/Question assets,
QR data/canvas output, and the editor's blob preview.

HSTS is **READY BUT INTENTIONALLY NOT ENABLED**. A commented one-year header is
present in `docker/nginx/security-headers.conf`. After DNS and the real
CA-issued certificate are working, verify all production navigation/API/WSS
flows without certificate errors, uncomment exactly:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

Then rebuild/redeploy Nginx and verify the header. Do not add `preload` or
`includeSubDomains` until the organization has separately confirmed every
relevant subdomain is permanently HTTPS-only.

## Trusted reverse proxy and real client addresses

The production edge network assigns Nginx `172.30.0.10` and configures the
backend with `TRUSTED_PROXY_CIDRS=172.30.0.10/32`. Nginx overwrites
`X-Real-IP` with its TCP client's `$remote_addr`. Backend IP resolution follows
this rule:

1. If the direct TCP peer is the configured trusted proxy and `X-Real-IP` is a
   valid IPv4/IPv6 address, use the forwarded address.
2. Otherwise ignore the header and use the direct peer address.
3. If a trusted proxy supplies a malformed value, safely fall back to its peer
   address.

The backend does not select values from attacker-controlled
`X-Forwarded-For` chains. This trust is safe only while port 9501 remains
unpublished and the edge network contains controlled services. The same client
identity feeds login and WebSocket per-IP protection.

The deployment host must leave `172.30.0.0/24` available for this Compose
network. If it conflicts with an existing route, change the subnet, both fixed
service addresses, and `TRUSTED_PROXY_CIDRS` together; never widen trust merely
to make an overlap disappear.

## Cookies and CSRF

Production startup requires HTTPS `APP_URL`, Secure cookies, HttpOnly access
and refresh cookies, `SameSite=Strict`, and `Path=/`. Auth cookies remain
host-only because no Domain attribute is configured. The double-submit CSRF
cookie deliberately remains readable by Angular (`HttpOnly=false`); access and
refresh cookies remain HttpOnly. Existing cookie names are retained to avoid
unnecessary XSRF migration churn, so optional `__Host-` prefixes are deferred.

## Question upload limits and persistence

The aligned limits are:

| Layer | Limit |
| --- | ---: |
| Application Question file | 5 MiB |
| PHP `upload_max_filesize` | 5 MiB |
| PHP `post_max_size` | 6 MiB |
| Nginx `client_max_body_size` | 6 MiB |
| OpenSwoole `package_max_length` | 6 MiB |
| Gameplay WebSocket application frame | 16 KiB |

The extra 1 MiB accommodates multipart metadata without allowing broadly
oversized requests. The `question_images` named volume is mounted at the
backend-managed storage path; Nginx does not alias the directory. Existing
managed-path, MIME, extension, content, and reference checks remain
authoritative.

## Local production-stack verification

For a local smoke only, create an ephemeral self-signed certificate for
`localhost`, set `SERVER_NAME=localhost`, `APP_URL=https://localhost`, and
`WS_ALLOWED_ORIGINS=https://localhost` in an untracked env file, then run the
normal production commands. Browsers and `curl` will warn unless that temporary
certificate is explicitly trusted; this is expected and is not evidence of
real-domain TLS readiness.

Verify at minimum:

- `http://localhost/path?a=1` returns 308 to
  `https://localhost/path?a=1`;
- `/`, a refreshed frontend route, `/health`, `/ready`, `/api`, and a real `/media` image
  travel through HTTPS;
- `wss://localhost/ws/game` upgrades with Origin `https://localhost`, while a
  missing/untrusted Origin is rejected;
- login returns Secure/HttpOnly access and refresh cookies plus a
  Secure/readable CSRF cookie, and authenticated mutations/logout/password
  change still pass CSRF;
- the required security headers are present and browser console/network panels
  show no blocked legitimate resource or mixed-content request;
- resolved host publications are only 80 and 443, and host connections to
  9501/3306 fail;
- Nginx reaches backend and backend reaches MySQL internally;
- a 5 MiB valid Question image plus multipart metadata succeeds.

Also keep an authenticated Player idle beyond 75 seconds while it acknowledges
heartbeats, verify a non-acknowledging client is removed using short test-only
thresholds, and confirm public `/internal/metrics` returns 404 while direct
backend access returns sanitized metrics. Runtime details and load-test
collection guidance are in `docs/11-openswoole-runtime.md`.

Remove the self-signed key, certificate, smoke env file, and smoke-only Docker
volumes afterward. Real-domain certificate validation, HSTS activation, public
DNS/firewall behavior, browser-console CSP verification, and organizational
backup/restore testing remain deployment requirements.
