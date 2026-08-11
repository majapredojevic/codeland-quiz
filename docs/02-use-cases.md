# Use cases

> Status: Implemented

Each case below records actor, transport, preconditions, primary flow, and important alternatives.

| ID | Use case | Actor / transport | Preconditions | Primary scenario | Alternatives and errors |
|---|---|---|---|---|---|
| UC-01 | Staff Login | Admin/teacher, REST | Active staff account; normal-login role | Submit email/password; backend records success and issues access, refresh and CSRF cookies | Unknown, wrong, inactive or non-staff identity gets generic invalid credentials; rate limit may reject |
| UC-02 | Change Required Password | Staff, REST | Authenticated; CSRF; ADMIN/TEACHER | Submit current/new password; save new hash, revoke refresh tokens, clear requirement | Invalid current/new password; unauthenticated or missing CSRF |
| UC-03 | Manage Staff Users | Admin, REST | Authenticated, changed password, ADMIN; CSRF for mutations | Create/list/get/update teachers; activate/deactivate; reset password | Duplicate email, teacher missing; repeated status request is idempotent |
| UC-04 | Manage Students | Admin/teacher, REST | Staff access; CSRF for mutations | Create/list/get/update and activate/deactivate students | Duplicate username, missing student, invalid input |
| UC-05 | Manage Topics | Admin/teacher, REST | Staff access; CSRF for mutations | Create/list/get/update/delete shared topics | Duplicate/invalid topic or forbidden deletion conflict |
| UC-06 | Manage Quizzes | Admin/teacher, REST | Staff access; CSRF for mutations | Create/list/get/update/delete quizzes | Duplicate title/version, missing quiz, invalid state |
| UC-07 | Manage Questions | Admin/teacher, REST | Existing editable quiz; CSRF for mutations | Create/list/get/update/delete/reorder validated questions | Invalid type/options/timing/points; missing or non-editable quiz |
| UC-08 | Activate/Deactivate Quiz | Admin/teacher, REST | Staff access; CSRF | Activate a valid quiz or deactivate it | Invalid/incomplete quiz or conflicting state |
| UC-09 | Create Quiz Session | Admin/teacher, REST | Active quiz; staff access; CSRF | Generate PIN and immutable quiz/question/option snapshots | Quiz unavailable/inactive or invalid session creation |
| UC-10 | Join as Registered | Student participant, REST | WAITING joinable session; active student username | Preview PIN; submit REGISTERED, username, nickname/avatar; receive participant JWT | Invalid PIN, closed join, unknown student, duplicate student/nickname |
| UC-11 | Join as Guest | Guest, REST | WAITING joinable session | Submit GUEST with nickname/avatar; receive participant JWT | Invalid PIN, closed join or duplicate nickname; no student row is created |
| UC-12 | Authenticate Participant WebSocket | Participant, WebSocket | Valid participant JWT; `/ws/game` open | Receive challenge; send `PARTICIPANT_AUTHENTICATE`; receive authenticated/current state | Invalid message/token/state or 10-second timeout closes socket |
| UC-13 | Start Quiz Session | Host staff, REST | WAITING session with snapshot questions; CSRF | Lock session, start first question, commit, notify participants | Wrong host/state or missing content |
| UC-14 | Submit Answer | Participant, WebSocket | Authenticated; ACTIVE open question before deadline | Send selected snapshot option IDs; validate and store one answer; receive acknowledgement | Duplicate/late/closed/invalid answer rejected with `ERROR` |
| UC-15 | Close Current Question | Host staff, REST | ACTIVE open question; CSRF | Lock session, close, calculate results/scores, commit, notify | Already closed/wrong state or wrong host |
| UC-16 | Start Next Question | Host staff, REST | Current question closed and another exists; CSRF | Lock session, start next snapshot question, commit, notify | Current open, no next question or wrong state |
| UC-17 | Finish Session | Host staff, REST | Session ready to finish; CSRF | Lock session, mark FINISHED, commit, publish shared and personal final results | Too early, wrong state or wrong host |
| UC-18 | Reconnect Participant | Participant, WebSocket | Valid JWT and non-removed DB participant | New socket replaces old mapping and receives state for WAITING/ACTIVE/FINISHED | Removed/invalid participant rejected; old socket receives replacement notice |
| UC-19 | Remove Participant | Host staff, REST | Session/participant exist; CSRF | Lock session then participant, mark removed, commit, notify and disconnect | Already removed/mismatch/wrong host |
| UC-20 | Session History/Report | Admin/teacher, REST | Staff access | List sessions or retrieve protected final report | Missing session or invalid pagination |
| UC-21 | Quiz Statistics | Admin/teacher, REST | Staff access; quiz exists | Retrieve aggregate quiz/session/answer statistics | Quiz missing or invalid identifier |
| UC-22 | Student Statistics | Admin/teacher, REST | Staff access; registered student exists | Retrieve aggregate and paginated session performance | Student missing or invalid pagination |
