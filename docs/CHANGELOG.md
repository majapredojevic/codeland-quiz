# Changelog

## Phase 3 runtime hardening

- Added one shared participant heartbeat/stale sweep with monotonic activity,
  reconnect/fd-reuse protection and transparent Angular acknowledgements.
- Added startup/WorkerExit presence reconciliation, graceful container stop,
  explicit OpenSwoole connection/coroutine ceilings and readiness gating.
- Added bounded request IDs, structured/redacted JSON logs, private runtime
  metrics, event-loop lag/memory observability and controlled verification.

## Unreleased

### Added

- Staff JWT authentication, role authorization, required-password changes and teacher administration.
- Student, topic, quiz and validated question management.
- Session snapshots, registered/guest joining and participant JWT issuance.
- Participant WebSocket authentication, reconnect/replacement, answer submission and removal handling.
- Manual start, question close, next-question and finish lifecycle with scoring, leaderboards and final results.
- Session history/reports, quiz statistics and registered-student statistics.
- Login attempt and audit logging.

### Changed

- PDO connections are isolated per OpenSwoole coroutine while transactions reuse one coroutine connection.
- Shared/exclusive session locks coordinate join, answer, participant connection and lifecycle races.
- PHP, PDO sessions and MySQL default to UTC.
- JSON responses consistently declare UTF-8; Router errors use consistent punctuation and 405 `Allow` headers.

### Security

- Refresh tokens are HMAC-hashed, atomically rotated under row lock and revoked after password changes/resets or staff deactivation.
- Inactive/non-staff login is rejected with generic credentials errors.
- Logout is CSRF-protected, access-token-independent and idempotent.
- Staff JWT configuration enforces a minimum secret length and HS256.
