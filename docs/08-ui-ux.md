# UI/UX requirements

> Status: Planned

The Angular frontend is not implemented. This document defines requirements, not completed screens.

## Staff experience

Desktop-first, responsive areas should cover login, required-password change, dashboard, ADMIN teacher management, students, topics, quizzes, question editor/reordering, session creation, waiting room, live REST-based participant monitor and manual session controls, history/report, quiz statistics and student statistics.

Mutations must send the CSRF header, show pending/disabled states, and present validation, authorization, conflict and network errors without exposing internal details. Lists need zero-based API pagination mapped to understandable UI controls and clear loading/empty/error states.

## Participant experience

The mobile-first flow should cover Game PIN preview, registered/guest choice where product design exposes it, username for registered participants, per-session nickname/avatar, waiting room, current question/options/timer display, answer acknowledgement, closed-question feedback, leaderboard, final result and reconnection.

The UI must not present `ANSWER_ACCEPTED` as correctness. It should tolerate reconnect event sequences for WAITING, ACTIVE/open, ACTIVE/closed and FINISHED, and clearly handle replacement/removal messages.

## Accessibility

Use keyboard-operable controls, visible focus, semantic labels, adequate contrast, status text beyond color alone, responsive layouts and reduced-motion-friendly transitions. Provide basic loading, empty and recoverable error states throughout.
