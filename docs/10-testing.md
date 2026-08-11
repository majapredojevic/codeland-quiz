# Testing strategy

> Status: Implemented

No automated unit/integration test suite is present in the repository. Backend development validation is therefore documented as manual and tool-assisted smoke/regression testing.

## Test categories

- REST: request validation, status, JSON shape/content type, middleware and role combinations.
- WebSocket: authentication timeout, participant authentication, answer/event ordering, reconnect/replacement and removal.
- Database: rows, foreign keys, generated uniqueness, snapshots, token rotation links, audit and statistics consistency.
- Security regressions: cookies/CSRF, generic login failures, inactive staff, password-change gating, refresh rotation/revocation and role denial.
- Concurrency: parallel answer/close and join/start operations; transaction rollback and lock ordering.
- Lifecycle: valid and invalid WAITING → ACTIVE → FINISHED transitions.
- Reporting: session report totals, quiz aggregates and registered-student longitudinal results against stored answers.
- Final smoke test: end-to-end classroom flow after a clean/known database setup.

## Primary smoke test

1. Login as staff and complete required password change if applicable.
2. Create topic/quiz/questions, validate question rules and activate the quiz.
3. Create a session and verify snapshots/PIN.
4. Preview and join as registered participant and guest.
5. Open `/ws/game`, authenticate both participant JWTs and verify waiting state.
6. Start; receive question; submit answers; close and verify personal results/leaderboard.
7. Start next question and repeat as needed; finish manually.
8. Verify final events, session report, quiz statistics and registered-student statistics.

## Negative regression matrix

Test duplicate registered join, duplicate nickname, duplicate answer, answer after close/deadline, next before close, finish too early, removed participant reconnect, inactive teacher login/access, rotated old refresh token, missing/incorrect CSRF, forbidden role and invalid/closed Game PIN. Also verify guests never create student rows and public responses do not reveal student identity.

## Race outcomes

- ANSWER vs CLOSE: answer shared lock first → answer commits and close includes it; close exclusive lock first → later answer observes closed state and is rejected.
- JOIN vs START: join shared lock first → participant is included before start; start exclusive lock first → later join observes ACTIVE and is rejected.

Use separate concurrent clients/connections and verify both API/WebSocket outcome and committed database state. Syntax checks and `git diff --check` supplement, but do not replace, runtime testing.
