# CodeLand Quiz load-testing harness

This directory contains a reproducible, production-path load harness. It is a
measurement and correctness system, not evidence that the application has any
particular capacity. The system under test remains:

`k6 -> HTTPS/WSS -> Nginx -> OpenSwoole -> MySQL`

The Compose overlay changes only local bindings, adds private observer/tool
containers, and mounts generated artifacts. It does not weaken the production
Compose file, publish `/internal/metrics`, bypass staff authentication, or add a
test HTTP API or database schema.

## Prerequisites and primary command

Use Docker Desktop and Windows PowerShell from the repository root. A global k6
installation is not needed.

```powershell
.\load-testing\scripts\run-load-test.ps1 -Students 10 -Mode classroom
```

The primary runner accepts `-Students`, optional `-Sessions`, `-Mode classroom`
or `burst`, `-RegisteredPercent`, `-ReconnectPercent`, `-CorrectAnswerRatio`,
`-BurstMajorityRatio`, `-Seed`, and `-Warmup`. When `-Sessions` is omitted, a documented matrix level
selects its fixed topology. An arbitrary student count requires an explicit
session count; allocation is balanced and its sum is verified exactly.

The default is 50% REGISTERED and 50% GUEST, a 70% deterministic correct-answer
ratio, and about 5% planned reconnects. These are coverage defaults, not usage
claims. `-Seed 0` creates and records a random seed; supplying a positive seed
reproduces player selection, delays, and logical answers for the same topology.

Generated files are stored under `load-testing/results/<runId>/` and ignored by
Git. Plaintext fixture passwords live only in a runtime subdirectory used by
k6; the runner deletes it before reporting. The manifest never contains
passwords, cookies, JWTs, refresh tokens, or participant tokens.
The credential file is briefly readable by the isolated k6 container because
its non-root UID differs from the backend UID; the runner removes the file and
its runtime directory before reporting or teardown.

## Safety

The approved default target is exactly `https://quiz.load.test`, reached by the
k6 container on the isolated Compose edge network. Nginx terminates ephemeral
self-signed TLS and normal server-side Origin allowlisting remains enabled.
Certificate verification is bypassed only for that locally generated
certificate.

A different URL is rejected unless both the explicit URL and either
`-AllowRemote` or `LOAD_TEST_ALLOW_REMOTE=true` are supplied. Remote use must be
limited to an owned staging environment whose fixture and database lifecycle is
under the operator's control. Never aim the runner at production.

On failure, diagnostics are captured before exact-ID cleanup. To inspect a
failed disposable environment, use both `-KeepLoadTestFixtures -KeepStack`, or
set `KEEP_LOAD_TEST_FIXTURES=true` (which also retains that isolated stack).
Remove it afterward with:

```powershell
.\load-testing\scripts\stop-load-test-stack.ps1
```

The cleanup command deletes only IDs in that run's manifest and verifies that
the exact Teachers, Students, Quizzes, Questions, Sessions, Participants,
answers, audit/login rows, and managed image are gone. It never uses names or
wildcards to select data.

## Workload model

Each Player is one `per-vu-iterations` VU with one iteration and a persistent
WebSocket. It performs public preview/join, obtains the normal participant
token, authenticates over `k6/websockets`, acknowledges heartbeats, receives all
Questions, submits structurally valid correct or incorrect choices, receives
answer/result events, optionally reconnects once after Question 2 without
joining again, receives final results, and closes cleanly.

One Teacher VU per Session logs in through the normal staff API, keeps cookies,
sends the real CSRF header, checks that every assigned Player is connected, and
uses start/close/next/finish/results APIs. Teacher login is slightly staggered;
gameplay actions use one absolute schedule so parallel classrooms stay closely
synchronized.

The six-Question fixture covers TRUE_FALSE, SINGLE_CHOICE, and MULTIPLE_CHOICE
repeatedly. One Question references a tiny PNG that Players fetch through HTTPS
and Nginx. CLASSROOM delays are deterministically spread from 1.0 to 5.5 seconds.
BURST sends 85% from 0.25 to 1.8 seconds and the remaining tail from 1.8 to 3.2
seconds. All remain below the production Question deadline.

The future fixed matrix is 10/1, 30/2, 50/2, 100/5, 200/10, 300/20, 400/20,
and 500/20 (Students/Sessions). `run-matrix.ps1` runs levels sequentially,
checks correctness, cleans exact fixtures, cools down without restarting the
stack, and stops immediately on failure. Optional BURST presets are 100, 300,
and 500 and remain configurable. **Do not run the matrix during Phase 4A.**

## Metrics and validity

k6 is pinned in `compose.load-test.yaml` and uses the supported
`k6/websockets` API. Native JSON output preserves timestamped built-in HTTP and
WebSocket metrics. Custom metrics cover join, WS authentication, authoritative
answer acknowledgement, answer reveal, reconnect, final-result and full-flow
success, heartbeat acknowledgements, message counts, and broadcast markers.

Answer acknowledgement latency is exactly `ANSWER_SUBMIT` send to the matching
authoritative `ANSWER_ACCEPTED` receive. Answer-result latency continues to the
matching `ANSWER_RESULT`, so it includes the remaining open period and close
action. Broadcast post-processing correlates a Teacher action marker and all
Player receipt markers by Session slot, Question index, and production event;
it reports first, p50, p95, p99, and last receipt without participant-ID tags or
protocol changes.

A private observer samples the existing `/internal/metrics` and MySQL global
status about once per second. It records worker/server state, accepted/current
connections, coroutines, pending/authenticated WebSockets, heartbeat cleanup,
memory, event-loop lag, and request counters; plus MySQL threads, connection
counters, lock waits, and deadlocks. Server-lifetime values are reported as
start/end deltas. Unavailable values are explicitly `NOT AVAILABLE`.

The host samples Docker CPU/memory for backend, MySQL, Nginx, and k6 without
mounting the Docker socket into any container. Environment metadata includes the
commit, component versions, Docker CPU/memory, OpenSwoole limits, and MySQL
`max_connections`.

There is no latency SLO in this baseline. Functional thresholds are strict. A
sustained legitimate HTTP failure rate of 20% or more after 10 seconds aborts
the run; final validity still requires zero legitimate failures, all Player and
Teacher flows, database correctness, exact cleanup, observer success, and all
required metric artifacts. A report separates performance from correctness and
marks sparse p99 values as descriptive.

## Files

- `k6/main.js`: Player/Teacher gameplay and metrics
- `k6/warmup.js`: separate unrecorded warm-up
- `fixtures/manage-fixtures.php`: domain-backed provision/finalize/exact cleanup
- `fixtures/verify-correctness.php`: independent post-run DB invariants
- `observer/runtime-mysql-observer.php`: private runtime/MySQL sampler
- `scripts/run-load-test.ps1`: one-run orchestrator
- `scripts/run-matrix.ps1`: future sequential Phase 4B runner
- `report/generate-report.php`: aggregate and broadcast report generator
