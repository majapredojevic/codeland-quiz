# User Flows

> Status: Draft  
> Version: 1.0  
> Project: CodeLand Quiz

## Purpose

This document describes the main user flows of the CodeLand Quiz platform.

The goal is to define how administrators, teachers, and students interact with the system before implementation begins.

The platform uses REST APIs for management operations and WebSockets for real-time quiz communication.

## Communication Types

| Type | Description |
| --- | --- |
| REST | HTTP REST API |
| WS | WebSocket communication |
| DB | Database operation |
| ASYNC | OpenSwoole internal asynchronous processing |

## Teacher Flow

```mermaid
flowchart TD
    A["Teacher Login [REST]"]
    --> B["Dashboard [REST]"]
    --> C["Manage Quizzes [REST]"]
    --> D["Create/Edit Quiz [REST]"]
    --> E["Manage Questions [REST]"]
    --> F["Start Quiz Session [REST]"]
    --> G["Waiting Room [WS]"]
    --> H["Start Question [WS]"]
    --> I["Live Teacher Dashboard [WS]"]
    --> J["Show Leaderboard [WS]"]
    --> K["Next Question [WS]"]
    --> L["Finish Quiz [WS]"]
    --> M["Session Statistics [REST]"]
```

## Student Flow

```mermaid
flowchart TD
    A["Scan QR Code or Enter Game PIN [REST]"]
    --> B["Enter Username [REST]"]
    --> C["Choose Nickname [REST]"]
    --> D["Choose Kode Avatar [REST]"]
    --> E["Join Waiting Room [WS]"]
    --> F["Receive Question [WS]"]
    --> G["Submit Answer [WS]"]
    --> H["Receive Score [WS]"]
    --> I["View Leaderboard [WS]"]
    --> J["View Final Results [WS]"]
```

## Administrator Flow

```mermaid
flowchart TD
    A["Administrator Login [REST]"]
    --> B["Dashboard [REST]"]
    --> C["Administration [REST]"]
    --> D["Create Administrator [REST]"]
    --> E["Create Teacher [REST]"]
    --> F["Deactivate User [REST]"]
```

## Quiz Session Flow

```mermaid
flowchart TD
    A["Teacher creates session [REST]"]
    --> B["Server creates Game PIN [DB]"]
    --> C["Students join session [WS]"]
    --> D["Teacher starts quiz [WS]"]
    --> E["OpenSwoole broadcasts question [WS + ASYNC]"]
    --> F["Students submit answers [WS]"]
    --> G["OpenSwoole calculates scores [ASYNC]"]
    --> H["OpenSwoole broadcasts leaderboard [WS]"]
    --> I["Teacher starts next question [WS]"]
    --> J["Session is finished [WS]"]
    --> K["Results are persisted [DB]"]
```

## Real-Time Communication Flow

```mermaid
sequenceDiagram
    participant T as Teacher
    participant S as OpenSwoole Server
    participant A as Student A
    participant B as Student B
    participant C as Student C

    T->>S: start_question
    S-->>A: question_started
    S-->>B: question_started
    S-->>C: question_started

    A->>S: student_answer
    B->>S: student_answer
    C->>S: student_answer

    S->>S: calculate scores
    S-->>T: live_statistics_updated
    S-->>A: answer_result
    S-->>B: answer_result
    S-->>C: answer_result
    S-->>T: leaderboard_updated
```

## Error Flows

### Invalid Game PIN

```mermaid
flowchart TD
    A["Student enters invalid Game PIN [REST]"]
    --> B["System displays validation error [REST]"]
    --> C["Student tries again [REST]"]
```

### Invalid Student Username

```mermaid
flowchart TD
    A["Student enters username [REST]"]
    --> B["System checks student existence [DB]"]
    --> C{"Username valid?"}
    C -->|Yes| D["Student continues"]
    C -->|No| E["Access denied"]
```

### Student Disconnects

```mermaid
flowchart TD
    A["Student WebSocket connection closes [WS]"]
    --> B["OpenSwoole detects disconnect [ASYNC]"]
    --> C["Teacher dashboard is updated [WS]"]
    --> D["Student may reconnect [WS]"]
```

## Dashboard Modes

### Classroom Mode

Classroom Mode is the default teacher dashboard mode.

It displays:

- connected students;
- submitted answers;
- pending answers;
- countdown timer;
- leaderboard;
- connection warnings.

### Developer Mode

Developer Mode is available only to administrators.

It may display:

- active WebSocket connections;
- server uptime;
- memory usage;
- recent WebSocket events;
- average latency.

Developer Mode is used for technical monitoring and thesis demonstration.

## Key Design Decision

The platform uses REST APIs for actions that happen once, such as login, quiz creation, question management, and viewing statistics.

The platform uses WebSocket communication for actions that require real-time updates, such as joining a live session, broadcasting questions, submitting answers, updating the leaderboard, and monitoring live classroom state.
