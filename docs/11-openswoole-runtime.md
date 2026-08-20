# OpenSwoole runtime hardening and measurement notes

> Status: Implemented and locally verified, including controlled Phase 4 load
> and profiling runs. Those results are environment-specific and are not a
> universal production-capacity claim.

## Process and resource model

Production intentionally runs one backend container with one OpenSwoole worker.
`ParticipantConnectionRegistry`, WebSocket limiters, heartbeat state and
delivery indexes are process-local. Increasing `worker_num`, starting a second
backend instance, or performing an overlapping rolling deployment would require
shared connection ownership and fan-out coordination; that architecture is not
implemented in this phase.

| Setting | Value | Decision |
| --- | ---: | --- |
| `worker_num` | 1 | Preserves process-local WebSocket ownership |
| `max_request` | 0 | Async long-running worker is not recycled to hide unknown leaks |
| `max_conn` | 4096 | Above the 2000 application WebSocket budget plus HTTP/internal headroom |
| Production `nofile` soft/hard | 8192 / 8192 | Keeps the server ceiling below the descriptor ceiling |
| `max_coroutine` | 4096 | Bounds concurrently executable work with measured test headroom |
| `reactor_num` | not explicitly changed | OpenSwoole selects/clamps its runtime default; local stats report one reactor thread with one worker |
| `package_max_length` | 6 MiB | Existing upload-compatible transport limit retained |
| Gameplay frame policy | 16 KiB | Existing application frame limit retained |

An idle persistent WebSocket is not one permanently executing coroutine.
`max_coroutine` therefore bounds simultaneous request/message/database work,
not the count of connected idle browsers. `backlog`, socket buffers,
`max_request_execution_time`, TCP keepalive and WebSocket compression retain
their OpenSwoole defaults because the recorded profile produced no measurement
showing a need to change them. Small gameplay messages do not justify
compression overhead.

## Application and transport heartbeat

The browser protocol adds two internal-only messages:

```json
{"type":"HEARTBEAT","payload":{"acknowledge":true}}
{"type":"HEARTBEAT_ACK","payload":{}}
```

One worker-level timer ticks every second. It measures event-loop delay on each
tick and performs one registry sweep every 25 seconds; there is no participant
timer. An authenticated connection is stale after 75 seconds without a valid
inbound participant message. Authentication, valid answer frames and heartbeat
ACKs refresh monotonic `lastSeen`; heartbeats never update the database, reload
game state, or produce success logs.

A stale sweep verifies both fd and cryptographic connection ID before removing
state. It then removes limiter/heartbeat registry state, closes the socket, and
uses the existing presence transaction to mark the participant disconnected.
The transaction re-checks current socket ownership after obtaining the
participant row lock, so a newer connection cannot be overwritten by late
cleanup from the old socket. The connection ID also protects against fd reuse.

OpenSwoole transport heartbeat runs every 30 seconds with a 120-second idle
threshold. It is a passive TCP safety net based on client-to-server traffic;
the application 75-second policy remains authoritative for participant
presence. Production Nginx read/send timeouts are 150 seconds, safely above
both layers. Pending unauthenticated sockets remain governed by the existing
10-second authentication timeout and Phase 1 connection limits; application
heartbeat never touches them.

## Presence and lifecycle

At `WorkerStart`, one SQL update changes only `is_connected` and
`disconnected_at` for non-removed participants in `WAITING` or `ACTIVE`
sessions. Scores, membership, answers, session status and history are not
changed. Readiness remains 503 until this reconciliation completes and the
runtime timer starts.

At `WorkerExit`, while the worker event loop is still available, readiness is
cleared, the timer is removed, and the same bounded reconciliation query runs
best-effort. `WorkerStop` performs only final synchronous state/log cleanup;
OpenSwoole documents that coroutine/asynchronous work is unavailable there.
`Shutdown` likewise logs only after all worker event loops have ended. Abrupt
termination is covered by the next startup reconciliation. Production sends
SIGTERM and allows a 20-second stop grace.

This strategy is correct only for the documented single backend instance and
single worker. A future horizontally scaled design needs shared runtime
identity/leases instead of a global startup update.

## Liveness, readiness and correlation

- `GET /health` is process/event-server liveness. It performs no database work.
- `GET /ready` returns 200 only after runtime initialization and a cheap
  `SELECT 1`; initialization or DB failure returns a sanitized 503.
- Production Docker health checks `/ready`; MySQL retains its own healthcheck.
- Every routed HTTP response includes a backend-generated 24-character
  hexadecimal `X-Request-ID`. Client-provided IDs are not trusted.

Request context stores the ID, canonical route, method and response status.
Completion logs also contain monotonic duration and the diagnostic coroutine
ID. Coroutine IDs are never an API contract.

## Structured logging

Runtime logs are one bounded JSON object per line. Common fields are
`timestamp`, `level`, `event`, `requestId`, `route`, `method`, `status`,
`durationMs`, `coroutineId`, `workerId`, `fd`, `connectionId`, `sessionId`,
`participantId` and exception class. The logger uses an explicit field
allowlist and truncates strings. It never accepts request bodies, passwords,
JWT/participant tokens, cookies, CSRF tokens or answer content. Production does
not emit DEBUG events or heartbeat-success events; abnormal lifecycle,
readiness, limit, stale cleanup and exception events are logged.

## Private runtime metrics

`GET /internal/metrics` is reachable directly on the internal backend service.
Production Nginx has an exact location that returns 404, and backend port 9501
is not published. The sanitized response includes, when OpenSwoole supplies
them:

- accepted/active/closed connections, request/dispatch totals, worker/reactor
  state, task count and OpenSwoole event-loop lag;
- active/peak coroutine diagnostics;
- pending/authenticated participant socket counts and heartbeat sweep/stale
  cleanup totals;
- observed HTTP/readiness-failure totals;
- application timer current/max event-loop lag;
- current/peak allocated PHP memory;
- the selected non-secret runtime ceilings.

The application timer stores only current/max lag and counters, never an
unbounded sample array. The load harness scrapes the endpoint at a modest fixed
cadence through the internal Docker network and records container CPU/RSS
alongside it. It observes MySQL connection pressure (`Threads_connected`,
`Threads_running`, connection errors) outside application requests. Do not
expose the metrics path through the public edge.

## Verified thesis facts to preserve

- PHP remains resident as a long-running process instead of booting per request.
- WebSocket connections persist independently of short HTTP requests.
- OpenSwoole dispatches overlapping callbacks in distinct coroutine contexts
  inside the single worker; structured logs expose those diagnostic IDs.
- Hooked PDO/network I/O can yield so another coroutine may run while one waits.
- Event-loop lag measures responsiveness of the sole PHP worker.
- I/O concurrency does not make CPU-heavy bcrypt, image inspection or JSON work
  non-blocking; a lag increase during those operations would be evidence to
  investigate before choosing offloading or more workers.

The runtime intentionally does not add Redis, shared tables, a PDO pool,
TaskWorkers, caching, replicas, or horizontal scaling. Subsequent Phase 4
measurement found no evidence-backed reason to add them. Valid 500-Player
CLASSROOM and BURST runs are recorded only as evidence for the tested local
Docker Desktop environment; see `docs/13-final-validation-and-evidence.md`.
