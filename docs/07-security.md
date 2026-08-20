# Security

> Status: Implemented

## Staff authentication

Staff access JWTs use HS256 only; construction rejects a `JWT_SECRET` shorter than 32 characters. Claims identify user, email, role, issue time and expiry. Authentication middleware decodes the access cookie and rechecks the current database user, so deactivation immediately blocks existing access JWTs.

Login issues an HttpOnly access cookie, HttpOnly refresh cookie and readable CSRF cookie. `Secure`, cookie path, names and `SameSite=Strict` are environment-driven. Production requires HTTPS, Secure host-only cookies, `Path=/` and `SameSite=Strict`; optional `__Host-` names are not currently enabled. Local plain HTTP may use `Secure=false` through development environment values.

## Refresh tokens and logout

Refresh tokens are generated with `random_bytes`, returned only as plaintext cookies and stored as HMAC-SHA256 hashes. Rotation runs atomically: valid token row `SELECT ... FOR UPDATE`, replacement insert, old-token revocation/replacement link, commit. The old token is then invalid. Password changes/resets and teacher deactivation revoke outstanding refresh tokens.

Logout is CSRF-protected but independent of a valid access JWT. A missing, invalid or already-revoked refresh token is tolerated; access, refresh and CSRF cookies are cleared and success is idempotent.

## Password and login protection

Passwords use PHP `password_hash`/`password_verify`, with rehash on successful eligible login. New/reset teacher accounts receive a generated temporary password and `must_change_password`; middleware restricts normal protected operations until change. Login email is normalized and bounded to 180 bytes, while persisted User-Agent data is reduced to printable ASCII and bounded to 255 bytes.

Production database initialization mounts only the schema and creates no
default administrator. The first Admin is an explicit operator action through
the backend-only `scripts/bootstrap-initial-admin.php` CLI; there is no HTTP
bootstrap endpoint. The CLI accepts name/email arguments, reads and confirms
the password without terminal echo (or from two non-interactive stdin lines),
uses the same bcrypt hasher and password policy as normal password changes,
and creates an active `ADMIN` with `must_change_password=true` inside a
transaction. It refuses when any Admin already exists. The fixed development
seed is development convenience only and is never mounted by production.

Failed logins are limited to 5 per normalized account in 15 minutes. A MySQL account advisory lock serializes the account check and attempt write. A second single-worker in-memory limit allows 100 failed logins per client IP in the same window; successful logins release their IP reservation. Direct peers cannot spoof forwarded headers. When, and only when, the TCP peer matches an explicit `TRUSTED_PROXY_CIDRS` entry, a syntactically valid `X-Real-IP` value is accepted; otherwise the direct socket address remains authoritative. Active limits return HTTP 429. Unknown, incorrect, inactive and non-staff login attempts receive the same generic invalid-credentials response, while unexpected infrastructure failures receive a generic 500 response and are logged without request credentials or tokens.

Password changes and admin teacher profile/reset/status mutations lock the affected user row with `SELECT ... FOR UPDATE` inside their transaction. Read-only profile and list operations remain unlocked.

## Middleware

- `AuthenticationMiddleware`: access cookie/JWT plus live database-user checks.
- `CsrfMiddleware`: double-submit CSRF cookie and `X-CSRF-Token` header.
- `PasswordChangeRequiredMiddleware`: blocks staff who must change password.
- `RoleMiddleware`: ADMIN-only or ADMIN/TEACHER authorization.

Normal protected mutations are ordered authentication → CSRF → required-password check → role. Reads omit CSRF. Login and public game routes are public; refresh preserves its current cookie-based behavior.

## Participant security

Participant JWTs use a separate minimum-32-character secret and HS256. Claims include `iss`, `aud`, `tokenType`, string `sub`, `sessionId`, `participantType`, nullable `studentId`, `iat`, `exp` and random `jti`. WebSocket authentication verifies claims and current session/participant database state; removed participants are rejected. Participant tokens grant no staff permissions.

The gameplay socket accepts only exact origins in `WS_ALLOWED_ORIGINS`; missing, malformed and unlisted browser origins are rejected during the WebSocket handshake. Production origins must use HTTPS. `/ws/echo` is available only when `APP_ENV=development`, and its logging records frame length rather than frame contents.

Gameplay frames are capped at 16 KiB before JSON decoding. The gameplay socket requires authentication within 10 seconds and allows three authentication attempts per connection, plus 1,000 authentication attempts per client IP each minute. Answer submissions allow eight attempts per connection in ten seconds. Once the backend accepts an answer, repeated submissions are rejected from authoritative connection state until the next question starts; the database uniqueness constraint remains authoritative.

The single worker allows at most 2,000 WebSocket connections globally, 750 pending participant authentications and 750 connections per client IP. These broad ceilings accommodate 500 students and shared school NAT while bounding unauthenticated accumulation. Participant token expiry is retained in authenticated connection state and checked before each participant command. One socket is current per participant; a new authenticated socket replaces the old one safely. Public game responses do not expose registered-student identity. Protected participant/report endpoints may expose it to staff. Answer acknowledgement does not reveal correctness; answer and final results are personalized.

## Production edge and future hardening

The standalone production deployment terminates TLS 1.2/1.3 at Nginx, serves Angular same-origin, proxies API/media/health/WebSocket paths without publishing OpenSwoole, and isolates MySQL on an internal network. It adds a constrained CSP, nosniff, referrer, permissions and frame protections. Secure host-only Strict cookies are validated at production startup; the readable CSRF cookie remains intentionally non-HttpOnly. HSTS is staged but deliberately disabled until the real hostname has CA-valid HTTPS verification. See `docs/09-deployment.md` for the exact policy and activation procedure.

Heartbeat ACKs are accepted only after participant authentication and
remain under the 16 KiB frame policy. They bypass answer/database work but do
not extend pending authentication, bypass Origin checks, or weaken connection
and authentication ceilings. Private runtime metrics are blocked by an exact
Nginx 404 route and contain no tokens, credentials, request bodies or answer
content. Runtime JSON logs use an allowlist of bounded diagnostic fields.

CORS should be introduced only if deployment becomes cross-origin. Login-attempt timestamp indexes keep time-window queries bounded, but old rows still require later operational retention/cleanup. Possible future work includes CAPTCHA where justified, Redis/shared multi-worker registry state, a bounded PDO pool if later evidence justifies it, and a participant-token revocation list.
