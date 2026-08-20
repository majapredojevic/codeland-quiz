# UI/UX implementation

> Status: Implemented

The Angular frontend is a responsive same-origin client for the staff and
Player workflows. Production builds are served as static files by Nginx; local
development uses the Angular proxy for `/api`, `/media`, and `/ws`.

## Staff experience

The desktop-first staff shell includes login, required and voluntary password
change, dashboard, ADMIN-only Teacher management, Students, Topics, Quizzes,
Question creation/editing/reordering, image upload, Session creation, QR/PIN
lobby, live participant monitoring, manual game controls, history, reports,
Quiz statistics, and registered-Student statistics.

Mutations use the CSRF interceptor and pending/disabled controls. Lists map the
zero-based API pagination to visible controls and expose loading, empty, error,
authorization, and conflict states. Creating a Quiz navigates directly to its
Questions tab; Question input enforces the shared 250-character product limit.

## Participant experience

The mobile-first Player flow covers PIN preview, registered/guest selection,
registered username, per-Session nickname and Koda avatar, lobby, current
Question/options/countdown, answer acknowledgement, closed-Question feedback,
leaderboard, final result, removal/replacement handling, and reconnection.

`ANSWER_ACCEPTED` is presented only as receipt, never correctness. The Player
restores its participant credential from memory plus `sessionStorage`, uses
same-host WSS on HTTPS, acknowledges server heartbeats without a client timer,
and handles WAITING, ACTIVE/open, ACTIVE/closed, and FINISHED reconnect states.

## Accessibility and presentation

The UI uses semantic controls and labels, visible focus, keyboard-operable
actions, status text beyond color, responsive breakpoints, reduced-motion
styles, and explicit loading/empty/recoverable-error states. Shared date
formatters render `dd.MM.yyyy.` and, where time matters,
`dd.MM.yyyy. HH:mm` while backend timestamps remain canonical ISO values.
