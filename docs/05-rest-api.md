# REST API

> Status: Implemented

JSON responses use `Content-Type: application/json; charset=utf-8`. In the tables, **Staff** means access-cookie authentication, completed required password change, and role `ADMIN` or `TEACHER`. Mutation routes marked CSRF require the double-submit token. IDs and payload validation can change the listed typical success into the documented error classes.

## Health and authentication

| Method | Path | Authentication / role | CSRF | Purpose | Typical success |
| --- | --- | --- | --- | --- | --- |
| GET | `/health` | Public | No | Health check | 200 |
| POST | `/api/auth/login` | Public | No | Staff login and cookie issuance | 200 |
| POST | `/api/auth/refresh` | Refresh cookie | No | Atomic refresh rotation and new cookies | 200 |
| GET | `/api/auth/me` | Access cookie | No | Current staff identity | 200 |
| POST | `/api/auth/change-password` | Access cookie; ADMIN/TEACHER | Yes | Change password/clear requirement | 204 |
| POST | `/api/auth/logout` | Access JWT not required | Yes | Revoke optional refresh token and clear cookies | 204 |

Login example:

```json
{"email":"teacher@example.com","password":"current-password"}
```

## Admin/staff management

All routes require authenticated ADMIN with completed password change; mutations require CSRF.

| Method | Path | Purpose | Typical success |
| --- | --- | --- | --- |
| POST | `/api/admin/users` | Create teacher with temporary password | 201 |
| GET | `/api/admin/users` | Paginated teacher list | 200 |
| GET | `/api/admin/users/{id}` | Teacher detail | 200 |
| PATCH | `/api/admin/users/{id}` | Update teacher profile | 200 |
| PATCH | `/api/admin/users/{id}/activate` | Activate teacher | 200 |
| PATCH | `/api/admin/users/{id}/deactivate` | Deactivate and revoke refresh tokens | 200 |
| POST | `/api/admin/users/{id}/reset-password` | Issue temporary password and revoke refresh tokens | 200 |

Create example: `{"name":"Nastavnik","email":"teacher@example.com"}`.

## Students

All routes require Staff; mutations require CSRF.

| Method | Path | Purpose | Typical success |
| --- | --- | --- | --- |
| GET | `/api/students` | Paginated student list | 200 |
| GET | `/api/students/{id}` | Student detail | 200 |
| POST | `/api/students` | Create student | 201 |
| PATCH | `/api/students/{id}` | Update student | 200 |
| PATCH | `/api/students/{id}/activate` | Activate student | 200 |
| PATCH | `/api/students/{id}/deactivate` | Deactivate student | 200 |
| GET | `/api/students/{id}/statistics` | Aggregate registered-student statistics | 200 |
| GET | `/api/students/{id}/statistics/sessions` | Paginated session performance | 200 |

## Topics

All routes require Staff; mutations require CSRF.

| Method | Path | Purpose | Typical success |
| --- | --- | --- | --- |
| GET | `/api/topics` | Paginated topic list | 200 |
| GET | `/api/topics/{id}` | Topic detail | 200 |
| POST | `/api/topics` | Create topic | 201 |
| PATCH | `/api/topics/{id}` | Update topic | 200 |
| DELETE | `/api/topics/{id}` | Delete topic | 204 |

## Quizzes and questions

All routes require Staff; mutations require CSRF.

| Method | Path | Purpose | Typical success |
| --- | --- | --- | --- |
| GET | `/api/quizzes` | Paginated quiz list | 200 |
| GET | `/api/quizzes/{id}` | Quiz detail | 200 |
| POST | `/api/quizzes` | Create quiz | 201 |
| PATCH | `/api/quizzes/{id}` | Update quiz | 200 |
| PATCH | `/api/quizzes/{id}/activate` | Validate and activate quiz | 200 |
| PATCH | `/api/quizzes/{id}/deactivate` | Deactivate quiz | 200 |
| DELETE | `/api/quizzes/{id}` | Soft-delete quiz | 204 |
| GET | `/api/quizzes/{id}/statistics` | Aggregate quiz statistics | 200 |
| GET | `/api/quizzes/{quizId}/questions` | Ordered questions | 200 |
| GET | `/api/quizzes/{quizId}/questions/{questionId}` | Question detail | 200 |
| POST | `/api/quizzes/{quizId}/questions` | Create validated question/options | 201 |
| PATCH | `/api/quizzes/{quizId}/questions/{questionId}` | Update question/options | 200 |
| DELETE | `/api/quizzes/{quizId}/questions/{questionId}` | Soft-delete question | 204 |
| PUT | `/api/quizzes/{quizId}/questions/order` | Reorder questions | 200 |

## Public game

| Method | Path | Authentication / role | CSRF | Purpose | Typical success |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/game/session/{gamePin}` | Public | No | Safe session preview | 200 |
| POST | `/api/game/join` | Public | No | Join as registered participant or guest; issue participant JWT | 201 |

Join example:

```json
{
  "gamePin": "123456",
  "participantType": "REGISTERED",
  "username": "student01",
  "nickname": "Maja",
  "avatarKey": "koda-blue"
}
```

For `GUEST`, `username` is omitted.

## Sessions, lifecycle and reporting

All routes require Staff; lifecycle/removal mutations require CSRF.

| Method | Path | Purpose | Typical success |
| --- | --- | --- | --- |
| POST | `/api/quizzes/{quizId}/sessions` | Create session and snapshots | 201 |
| GET | `/api/sessions` | Paginated session history | 200 |
| GET | `/api/sessions/{id}` | Session state/detail | 200 |
| GET | `/api/sessions/{id}/participants` | Current classroom participant monitor | 200 |
| DELETE | `/api/sessions/{id}/participants/{participantId}` | Logically remove participant | 204 |
| POST | `/api/sessions/{id}/start` | Manually start session/first question | 200 |
| POST | `/api/sessions/{id}/questions/current/close` | Manually close and score question | 200 |
| POST | `/api/sessions/{id}/questions/next` | Manually start next question | 200 |
| POST | `/api/sessions/{id}/finish` | Manually finish session | 200 |
| GET | `/api/sessions/{id}/results` | Final session report | 200 |

Lifecycle requests need no special body beyond the route ID. Notifications are sent after the transaction commits.

## Pagination and statuses

List endpoints accept zero-based `pageIndex`; default `pageSize` is 10 and maximum is 20. Responses retain endpoint-specific pagination objects.

- `200 OK`: read/update/action result.
- `201 Created`: resource or participant/session creation.
- `204 No Content`: successful deletion, password change or logout.
- `400 Bad Request`: malformed or invalid input.
- `401 Unauthorized`: missing/invalid authentication.
- `403 Forbidden`: role, password-change or CSRF restriction.
- `404 Not Found`: route/resource absent.
- `405 Method Not Allowed`: path exists for another method; `Allow` lists methods.
- `409 Conflict`: uniqueness or state conflict.

Error bodies remain `{"error":"..."}`; no separate error-code envelope is used for REST.
