# Changelog

## Phase 5A final validation and production bootstrap hardening

- Production MySQL now initializes from `001_schema.sql` only; the fixed Admin
  seed remains a clearly labeled development convenience.
- Added an operator-only, one-time production CLI for creating an active initial
  Admin with a runtime bcrypt hash and required first-login password change.
- Added resolved-Compose, bootstrap-service, and real-database state checks and
  completed production-path HTTPS/WSS, application, build, dependency, and
  cleanup validation.
- Added the final thesis evidence fact sheet and reconciled documentation with
  the implemented Angular, runtime, deployment, load, and profiling behavior.

## Phase 4 load testing and profiling

- Added the reproducible production-path k6 harness, exact fixture lifecycle,
  runtime/MySQL observer, correctness verification, and result reporting.
- Recorded valid local CLASSROOM and BURST runs through 500 Players across 20
  Sessions; these results are environment-specific, not a universal capacity
  claim.
- Added bounded opt-in profiling. Measurements did not justify a PDO pool,
  TaskWorkers, more workers, Redis, query rewrites, or other Phase 5 performance
  changes.

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
