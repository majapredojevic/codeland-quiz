# Engineering decisions and future work

- OpenSwoole was selected instead of Laravel to demonstrate a long-running async HTTP/WebSocket backend.
- `worker_num=1` is intentional while participant connection registry state is in memory.
- Native PDO connections are lazy and coroutine-local; a bounded pool is a possible later scalability improvement.
- `max_request=0` preserves the long-running async worker; retained memory must be measured and fixed rather than hidden by periodic recycling.
- One shared application heartbeat sweep maintains participant presence; OpenSwoole transport heartbeat is a secondary TCP safety net.
- Runtime request/coroutine IDs, memory and event-loop lag are bounded diagnostics used by the Phase 4 measurement harness.
- Multiple workers would require shared registry/pub-sub state such as Redis; Redis is not currently used.
- Session snapshots preserve historical questions/options independently of editable source data.
- Teachers control start, close, next and finish manually; there are no automatic state timers.
- Registered participants reference the student registry; guests remain separate and never create student rows.
- Answers store selected snapshot option IDs as JSON to support single and multiple selection.
- Local Angular development uses `/api`, `/media`, and `/ws` proxying to avoid unnecessary CORS.
- The Angular lobby generates QR presentation client-side from the join URL; the backend does not generate QR assets.
