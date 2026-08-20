# Final validation and evidence fact sheet

> Validated locally on 2026-08-20 from committed baseline
> `7760d8e9dc96013c8fa1d4aef43f0072c29936bf`, plus the reviewable Phase 5A
> production-bootstrap and documentation changes in the working tree. This is
> thesis evidence for the tested environment, not a claim of completed public
> production deployment.

## FINAL ARCHITECTURE

CodeLand Quiz is an Angular 22 single-page application served by Nginx. Nginx
is the only public production service and routes HTTPS/WSS to one internal
PHP 8.3/OpenSwoole worker; the backend reaches MySQL 8.4 on a private internal
network. Staff control uses REST, while Players use REST to preview/join and a
persistent authenticated WebSocket for gameplay. Session Question snapshots
preserve historical correctness independently of editable source Questions.

## SECURITY CONTROLS

- HS256 staff and participant tokens use separate strong-secret domains;
  refresh tokens are HMAC-hashed and rotated under row lock.
- Secure, host-only, `SameSite=Strict` cookies carry staff credentials; access
  and refresh cookies are HttpOnly, while the readable CSRF cookie supports
  double-submit protection on state changes.
- Authentication rechecks current User state. ADMIN/TEACHER authorization,
  required-password-change restrictions, generic login failures, account/IP
  login limits, and transaction row locking were verified.
- WebSockets enforce trusted Origin, participant-token expiry and identity,
  frame/connection/pending/per-IP/auth/answer limits, duplicate-answer control,
  and generic error handling without raw-frame logging.
- Question images use generated managed paths, MIME/content/extension checks,
  aligned 5 MiB application and 6 MiB transport limits, and reference-aware
  deletion.
- Production exposes neither `/ws/echo` nor private metrics/profile routes.
  Profiling defaults off and production forces it off.
- A fresh production database has no fixed Admin. The shell-only bootstrap CLI
  accepts operator name/email, reads a password without a password argument,
  applies the application policy and bcrypt hasher, and transactionally creates
  one active Admin with `must_change_password=true`; it refuses if any Admin
  already exists.

## OPENSWOOLE CONFIGURATION

Production deliberately uses `worker_num=1`, `max_request=0`, `max_conn=4096`,
`max_coroutine=4096`, a 6 MiB package ceiling, and a 16 KiB gameplay-frame
ceiling. The container has an 8192 file-descriptor limit and graceful SIGTERM
handling. Application heartbeat defaults are 25/75 seconds; OpenSwoole's
transport safety net uses 30/120 seconds.

## COROUTINE MODEL

Each HTTP request and WebSocket callback executes in OpenSwoole's coroutine
runtime. Blocking database work uses coroutine-compatible PDO. Runtime request
and coroutine identifiers, memory, connection counts, counters, and event-loop
lag are bounded diagnostics; logs retain only allowlisted diagnostic fields.

## WEBSOCKET MODEL

The participant registry is process-local and maps an authenticated Player to
its current connection. Replacement and file-descriptor reuse are guarded so
an old socket cannot disconnect a newer one. One shared timer sends heartbeat
challenges, removes stale connections, and reconciles presence. Startup and
worker exit also reconcile database presence. Staff gameplay control remains
REST-based; authoritative state and results are broadcast to Player sockets.

## DATABASE CONCURRENCY

PDO is lazy and coroutine-local, with a process-local fallback for CLI use;
repository operations in one coroutine/transaction reuse the same connection.
Transactions preserve exceptions, and row/advisory locks protect sensitive
User mutations, login-attempt accounting, joins, answers, presence, and Session
lifecycle transitions. Dynamic ordering is selected from internal enums/matches
and pagination values are bound integers.

## PRODUCTION DEPLOYMENT

Resolved production Compose contains only `nginx`, `backend`, and `mysql`.
Only Nginx publishes ports 80 and 443; backend 9501 and MySQL 3306 remain
internal. Nginx serves the production Angular build, redirects HTTP with 308,
accepts TLS 1.2/1.3 only, proxies WSS, applies the documented restrictive CSP
and security headers, and returns 404 for internal observability routes.
Application images use immutable operator tags, Composer `--no-dev` with an
authoritative classmap, non-root backend execution, read-only roots, dropped
capabilities, and externally mounted untracked TLS material.

## LOAD-TEST METHODOLOGY

The deterministic k6 harness exercises the real `HTTPS/WSS -> Nginx ->
OpenSwoole -> MySQL` path. Each Player previews/joins normally, authenticates a
persistent socket, acknowledges heartbeats, answers all six Questions, may
reconnect, receives results, and exits. One Teacher VU per Session uses normal
login, CSRF, and manual lifecycle APIs. Fixtures cover registered/guest Players,
all three Question types, and a real PNG. Independent database invariants,
broadcast correlation, observer artifacts, strict functional thresholds, and
exact-ID cleanup determine validity.

## KEY VERIFIED PERFORMANCE RESULTS

On the recorded Docker Desktop environment (20 allocated CPUs, 15.46 GiB RAM,
one backend worker, MySQL `max_connections=151`), both 500-Player/20-Session
CLASSROOM and BURST runs were valid with 250 registered and 250 guest Players,
six Questions, 3,000 accepted answers, complete flows, database invariants, and
exact cleanup.

| Metric | CLASSROOM | BURST |
| --- | ---: | ---: |
| Answer p95 | 16 ms | 18 ms |
| HTTP p95 | 765.025 ms | 707.296 ms |
| Join p95 | 781.050 ms | 750.000 ms |
| WebSocket connect p95 | 763.841 ms | 714.327 ms |
| WebSocket auth p95 | 688 ms | 659 ms |
| Run-window maximum event-loop lag | 52.452 ms | 1.013 ms |
| Backend maximum CPU | 113.12% | 113.38% |
| MySQL maximum CPU | 107.30% | 108.17% |
| MySQL maximum connected/running threads | 21 / 14 | 14 / 7 |

BURST peaked at 301 sends/s and 302 accepts/s. Required broadcasts had zero
push failures; average broadcast loops stayed below 0.31 ms and approximate
p95 stayed at or below 2 ms.

## PROFILE CONCLUSIONS

PDO creation was measurable (5,401/5,396 connections, about 2.1 ms average and
5 ms approximate p95), but MySQL pressure stayed healthy with no connection
errors or row-lock waits. No individual query path or synchronous CPU-heavy
operation justified a speculative change. The one-worker setup approached a
CPU/event-loop boundary during CLASSROOM setup, while the BURST answer peak
remained responsive. Therefore a PDO pool is optional, TaskWorkers are not
indicated, `worker_num` remains one, and no Phase 5 performance optimization is
evidence-backed.

## KNOWN LIMITATIONS

- Measurements are local Docker Desktop evidence only and establish no
  universal concurrency or production-capacity guarantee.
- Real-domain DNS, CA-issued certificate verification, public firewall/routing,
  and a representative real-browser console/network pass remain deployment
  work. The repository has no configured browser automation framework.
- HSTS is intentionally staged but disabled until the real HTTPS domain is
  proven. Organizational backup/restore procedure and rehearsal remain an
  operator responsibility.
- WebSocket registry and fan-out state are process-local, so one worker and one
  backend instance are required for current correctness.
- k6 validates protocol behavior and server results; it does not render Angular
  or substitute for accessibility/usability testing in real browsers.

## FUTURE SCALING OPTIONS

Only later evidence should trigger scaling work. Multiple workers or instances
require shared/partitioned presence, registry, and pub/sub coordination (for
example Redis). A bounded PDO pool can be reconsidered if connection creation
becomes dominant. TaskWorkers would be appropriate only after a measured
synchronous CPU-heavy operation appears. Caching, replicas, query/index changes,
and limit tuning likewise require a specific measured bottleneck.

## FINAL VALIDATION SNAPSHOT

The final pass covered static PHP verification and full lint, 382 frontend
unit tests across 60 files, production Angular build, npm and Composer security
audits, strict Composer validation, Nginx configuration, resolved production
Compose, TLS protocol behavior, HTTPS/WSS/Origin/trusted-proxy controls,
cookies/CSRF/roles, initial-Admin and Teacher password-change flows, Student and
Quiz/Question/image management, a real two-Player game with all Question types
and reconnect, Results/database invariants, and exact synthetic cleanup.

**READY FOR DIPLOMA THESIS PRACTICAL-DERIVATION DOCUMENTATION: YES**

This readiness statement concerns deriving and writing the thesis from the
verified implementation and recorded evidence. It is not a claim that a public
production deployment has been completed.
