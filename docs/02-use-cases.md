# Use Cases

> Status: Draft  
> Version: 1.0  
> Project: CodeLand Quiz

## UC-01: User Login

**Actor:** Administrator, Teacher

**Communication:** REST

### UC-01 Primary Scenario

1. User enters email and password.
2. System validates credentials.
3. System checks whether the user account is active.
4. System returns an authentication token.
5. User is redirected to the dashboard.

### UC-01 Alternative Scenarios

- Invalid credentials.
- Inactive user account.

---

## UC-02: Manage Users

**Actor:** Administrator

**Communication:** REST

### UC-02 Primary Scenario

1. Administrator opens user management.
2. Administrator creates a teacher or another administrator.
3. System stores the user with the selected role.
4. Administrator can deactivate users.

---

## UC-03: Manage Students

**Actor:** Administrator, Teacher

**Communication:** REST

### UC-03 Primary Scenario

1. User opens student management.
2. User creates a student profile.
3. System stores first name, last name and username.
4. Only active students may join quiz sessions.

---

## UC-04: Manage Quizzes

**Actor:** Administrator, Teacher

**Communication:** REST

### UC-04 Primary Scenario

1. User opens quiz management.
2. User creates, edits or deletes a quiz.
3. User adds questions and answer options.
4. User may upload an image for a question.

---

## UC-05: Start Quiz Session

**Actor:** Administrator, Teacher

**Communication:** REST + WebSocket

### UC-05 Primary Scenario

1. User selects a quiz.
2. System creates a quiz session.
3. System generates a Game PIN and QR code.
4. Students join the waiting room.
5. User starts the quiz.

---

## UC-06: Student Joins Quiz

**Actor:** Student

**Communication:** REST + WebSocket

### UC-06 Primary Scenario

1. Student enters Game PIN or scans QR code.
2. Student enters username.
3. System verifies that the student exists and is active.
4. Student enters nickname.
5. Student selects a Kode avatar.
6. Student joins the waiting room.

### UC-06 Alternative Scenarios

- Invalid Game PIN.
- Invalid student username.
- Inactive student profile.

---

## UC-07: Run Live Question

**Actor:** Administrator, Teacher, Student

**Communication:** WebSocket

### UC-07 Primary Scenario

1. Teacher starts a question.
2. Server broadcasts the question to all connected students.
3. Students submit answers.
4. Server calculates correctness, response time and score.
5. Teacher dashboard updates in real time.

---

## UC-08: Show Leaderboard

**Actor:** Administrator, Teacher, Student

**Communication:** WebSocket

### UC-08 Primary Scenario

1. Question ends.
2. Server calculates ranking.
3. Server broadcasts leaderboard.
4. Students and teacher see current ranking.

---

## UC-09: View Session Statistics

**Actor:** Administrator, Teacher

**Communication:** REST

### UC-09 Primary Scenario

1. User opens session history.
2. User selects a completed session.
3. System displays participants, scores, answers and question statistics.

---

## UC-10: Developer Mode

**Actor:** Administrator

**Communication:** WebSocket

### UC-10 Primary Scenario

1. Administrator enables Developer Mode.
2. System displays technical real-time data.
3. Administrator can observe WebSocket activity during the quiz.

### UC-10 Note

Developer Mode is intended for monitoring and thesis demonstration, not regular classroom use.
