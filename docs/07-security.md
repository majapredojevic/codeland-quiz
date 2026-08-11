# Security

> Status: Implemented

## Staff authentication

Staff access JWTs use HS256 only; construction rejects a `JWT_SECRET` shorter than 32 characters. Claims identify user, email, role, issue time and expiry. Authentication middleware decodes the access cookie and rechecks the current database user, so deactivation immediately blocks existing access JWTs.

Login issues an HttpOnly access cookie, HttpOnly refresh cookie and readable CSRF cookie. `Secure`, cookie path, names and `SameSite=Strict` are environment-driven. Production configuration is expected to use HTTPS, Secure cookies and `__Host-` names; local plain HTTP uses non-`__Host` names and `Secure=false` through environment values.

## Refresh tokens and logout

Refresh tokens are generated with `random_bytes`, returned only as plaintext cookies and stored as HMAC-SHA256 hashes. Rotation runs atomically: valid token row `SELECT ... FOR UPDATE`, replacement insert, old-token revocation/replacement link, commit. The old token is then invalid. Password changes/resets and teacher deactivation revoke outstanding refresh tokens.

Logout is CSRF-protected but independent of a valid access JWT. A missing, invalid or already-revoked refresh token is tolerated; access, refresh and CSRF cookies are cleared and success is idempotent.

## Password and login protection

Passwords use PHP `password_hash`/`password_verify`, with rehash on successful eligible login. New/reset teacher accounts receive a generated temporary password and `must_change_password`; middleware restricts normal protected operations until change. Login attempts are stored and counted by email/time for rate limiting. Unknown, incorrect, inactive and non-staff login attempts receive the same generic invalid-credentials response. Known-user failures are audited.

## Middleware

- `AuthenticationMiddleware`: access cookie/JWT plus live database-user checks.
- `CsrfMiddleware`: double-submit CSRF cookie and `X-CSRF-Token` header.
- `PasswordChangeRequiredMiddleware`: blocks staff who must change password.
- `RoleMiddleware`: ADMIN-only or ADMIN/TEACHER authorization.

Normal protected mutations are ordered authentication → CSRF → required-password check → role. Reads omit CSRF. Login and public game routes are public; refresh preserves its current cookie-based behavior.

## Participant security

Participant JWTs use a separate minimum-32-character secret and HS256. Claims include `iss`, `aud`, `tokenType`, string `sub`, `sessionId`, `participantType`, nullable `studentId`, `iat`, `exp` and random `jti`. WebSocket authentication verifies claims and current session/participant database state; removed participants are rejected. Participant tokens grant no staff permissions.

The gameplay socket requires authentication within 10 seconds. One socket is current per participant; a new authenticated socket replaces the old one safely. Public game responses do not expose registered-student identity. Protected participant/report endpoints may expose it to staff. Answer acknowledgement does not reveal correctness; answer and final results are personalized.

## Deployment responsibilities and future hardening

TLS termination and a production reverse proxy are deployment responsibilities and are not present in this repository. CORS should be introduced only if deployment becomes cross-origin. Possible future work includes IP-based rate limiting, security headers, CAPTCHA where justified, Redis/shared multi-worker registry state and a participant-token revocation list. None is currently implemented.
