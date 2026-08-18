# WebSocket events

> Status: Implemented

Gameplay uses `/ws/game`. `/ws/echo` is a diagnostic echo endpoint only. Messages use `{"type":"...","payload":{...}}`.

## Connection and client messages

1. Client opens `/ws/game`.
2. Server sends `AUTHENTICATION_REQUIRED` with `timeoutSeconds: 10`.
3. Client must authenticate within 10 seconds:

```json
{
  "type": "PARTICIPANT_AUTHENTICATE",
  "payload": {"participantToken": "..."}
}
```

4. Server sends `PARTICIPANT_AUTHENTICATED` with participant identity and session summary, followed by state events needed for reconnection.

An authenticated participant submits:

```json
{
  "type": "ANSWER_SUBMIT",
  "payload": {"selectedOptionIds": [101]}
}
```

The only other accepted client message is the transparent heartbeat ACK:

```json
{"type":"HEARTBEAT_ACK","payload":{}}
```

It updates monotonic connection activity and bypasses gameplay/database work.

## Server gameplay events

| Event | Audience and meaning |
|---|---|
| `AUTHENTICATION_REQUIRED` | New socket; announces 10-second timeout |
| `PARTICIPANT_AUTHENTICATED` | New authenticated socket; participant and session identity/state |
| `GAME_STARTED` | Session participants; shared session start summary |
| `QUESTION_STARTED` | Session participants; public snapshot question/options and timing |
| `ANSWER_ACCEPTED` | Submitting participant only; order, response time and timestamp, never correctness |
| `QUESTION_CLOSED` | Session participants; correct option IDs and aggregate counts |
| `ANSWER_RESULT` | One participant; selected options, correctness, points and total score |
| `LEADERBOARD_UPDATED` | Session participants; shared ranked entries after close |
| `GAME_FINISHED` | Session participants; shared final counts and Top 3 |
| `FINAL_RESULT` | One participant; personal final rank and totals |
| `PARTICIPANT_REMOVED` | Removed participant before forced disconnect |
| `CONNECTION_REPLACED` | Previous socket when a newer socket authenticates for the same participant |
| `HEARTBEAT` | Authenticated participants; tiny liveness probe, no UI/domain effect |

Correctness is deliberately absent from `ANSWER_ACCEPTED`; it appears only after `QUESTION_CLOSED`. `ANSWER_RESULT` and `FINAL_RESULT` are personalized, while leaderboards and `GAME_FINISHED` are shared.

## Reconnect sequence

- WAITING: `PARTICIPANT_AUTHENTICATED` only.
- ACTIVE/open: authenticated, then `GAME_STARTED`, then `QUESTION_STARTED`.
- ACTIVE/closed: authenticated, then `GAME_STARTED`, `QUESTION_CLOSED`, the participant's `ANSWER_RESULT` when present, then `LEADERBOARD_UPDATED`.
- FINISHED: authenticated, then `GAME_FINISHED` and the participant's `FINAL_RESULT` when found.

The current socket mapping is replaced before the old socket is disconnected, preventing its close event from disconnecting the replacement.

The server sends `HEARTBEAT` on the shared 25-second sweep. Any valid inbound participant message refreshes activity; 75 seconds without one closes the stale socket and marks presence disconnected without removing the participant. The browser responds immediately and creates no interval/subscription.

## Errors and closure

`ERROR` payloads contain `code` and `message`. Current gateway codes are:

- `INVALID_AUTHENTICATION_MESSAGE`, `PARTICIPANT_AUTHENTICATION_FAILED`, `PARTICIPANT_CONNECTION_REJECTED`, `AUTHENTICATION_TIMEOUT`;
- `INVALID_ANSWER_MESSAGE`, `UNSUPPORTED_MESSAGE`;
- `ANSWER_SUBMISSION_NOT_ALLOWED`, `ANSWER_QUESTION_CLOSED`, `ANSWER_DEADLINE_EXPIRED`, `ANSWER_ALREADY_SUBMITTED`, `INVALID_SELECTED_OPTIONS`;
- `UNKNOWN_WEBSOCKET_PATH`, `INTERNAL_ERROR`.

Authentication/path/internal policy failures disconnect with WebSocket close code 1008 where the connection is established. Staff lifecycle control has no WebSocket authentication or command channel.
