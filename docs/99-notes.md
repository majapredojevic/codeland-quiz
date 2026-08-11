# Engineering decisions and future work

- OpenSwoole was selected instead of Laravel to demonstrate a long-running async HTTP/WebSocket backend.
- `worker_num=1` is intentional while participant connection registry state is in memory.
- Native PDO connections are lazy and coroutine-local; a bounded pool is a possible later scalability improvement.
- Multiple workers would require shared registry/pub-sub state such as Redis; Redis is not currently used.
- Session snapshots preserve historical questions/options independently of editable source data.
- Teachers control start, close, next and finish manually; there are no automatic state timers.
- Registered participants reference the student registry; guests remain separate and never create student rows.
- Answers store selected snapshot option IDs as JSON to support single and multiple selection.
- Local Angular development is planned through `/api` and `/ws` proxying to avoid unnecessary CORS.
- QR presentation can be generated client-side from a PIN/join URL; the backend does not generate QR assets.
