# Load-testing methodology

CodeLand Quiz uses long-lived, bidirectional WebSockets during gameplay. An
ordinary REST requests-per-second model would lose the defining concurrency:
connected Players retain identity and Session state, receive server broadcasts,
answer heartbeats, and submit answers only after Question events. The harness
therefore assigns one long-lived k6 virtual user and one fixed iteration to each
Player, while a separate authenticated Teacher virtual user controls each
Session.

CLASSROOM and BURST represent different temporal shapes. CLASSROOM distributes
deterministic answer delays across several seconds to approximate independent
human reactions. BURST concentrates most valid answers in roughly the first two
seconds while keeping a smaller tail; it probes transient coroutine, connection,
and MySQL write pressure without inventing a zero-time packet storm. A recorded
seed makes both shapes reproducible.

Higher levels use several independent classrooms rather than one oversized
Session. The 300-500-Player topology deliberately uses 20 Teachers and 20
Sessions. Shared absolute Question schedules make these classrooms overlap so
system-wide peaks are retained. This models the application domain and its
Session isolation more faithfully than placing every Player in one game.

The existing private OpenSwoole metrics describe connection/coroutine pressure,
WebSocket authentication state, memory, request volume, heartbeat cleanup, and
event-loop lag. Event-loop lag is especially important with one worker: rising
lag indicates that the worker is not returning promptly to its event loop. The
baseline does not change `worker_num`, introduce a PDO pool, or add TaskWorkers;
those decisions require later evidence.

MySQL observations include connected/running threads, connection totals and
errors, row-lock waits/time, and deadlocks. Lifetime counters are compared at
the start and end of a run. Connection pressure matters because concurrent
coroutines can overlap database operations, but observation alone does not
justify a pool. Docker CPU and memory are sampled for the load generator as well
as the system under test: backend results are not convincing if k6 itself is the
bottleneck.

Each run also verifies exact database invariants and removes only manifest IDs.
A fast report with incorrect answers, duplicate Participants, cross-Session
associations, or inconsistent scores is invalid. Optional warm-up is separate
from recorded output, matrix levels run sequentially with configurable cool-down,
and any functional/correctness failure stops escalation.

All results are environment-specific. Docker Desktop allocation, host hardware,
background work, component versions, and application limits are recorded so a
later thesis result can accurately begin with “On the tested environment...” A
10-Player Phase 4A smoke validates the harness only; it is not evidence of
higher-scale capacity.

See [the harness runbook](../load-testing/README.md) for commands, safety rules,
metric definitions, and artifact names.
