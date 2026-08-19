import http from 'k6/http';
import exec from 'k6/execution';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { WebSocket } from 'k6/websockets';

import {
  answerDelayMs,
  shouldAnswerCorrectly,
  validIncorrectSelection,
} from './lib/workload.js';

const manifestPath = __ENV.MANIFEST_PATH || '/results/manifest.json';
const credentialsPath = __ENV.CREDENTIALS_PATH || '/results/runtime/credentials.json';
const manifest = JSON.parse(open(manifestPath));
const credentials = JSON.parse(open(credentialsPath));
const baseUrl = (__ENV.TARGET_URL || manifest.configuration.targetUrl).replace(/\/$/, '');
const webSocketUrl = baseUrl.replace(/^http/, 'ws') + '/ws/game';
const originMatch = baseUrl.match(/^https?:\/\/[^/]+/i);
const origin = originMatch ? originMatch[0] : '';
const startEpochMs = Number(__ENV.TEST_START_EPOCH_MS);
const mode = manifest.mode;
const commonTags = { mode, studentCount: String(manifest.requestedStudentCount) };

if (!Number.isFinite(startEpochMs) || startEpochMs <= 0) {
  throw new Error('TEST_START_EPOCH_MS must be a positive epoch timestamp.');
}
if (manifest.players.length !== manifest.requestedStudentCount) {
  throw new Error('Manifest Player assignment count is not exact.');
}
if (credentials.teachers.length !== manifest.sessionCount) {
  throw new Error('Ephemeral Teacher credential count does not match Sessions.');
}
if (origin === '') {
  throw new Error('TARGET_URL must be an absolute HTTP(S) URL.');
}

const joinLatency = new Trend('app_join_latency', true);
const wsAuthenticationLatency = new Trend('app_ws_authentication_latency', true);
const answerAcknowledgementLatency = new Trend('app_answer_acknowledgement_latency', true);
const answerResultLatency = new Trend('app_answer_result_latency', true);
const reconnectLatency = new Trend('app_reconnect_latency', true);
const mediaRequestLatency = new Trend('app_media_request_latency', true);
const joinSuccess = new Rate('app_join_success');
const wsAuthenticationSuccess = new Rate('app_ws_authentication_success');
const answerAcknowledgementSuccess = new Rate('app_answer_acknowledgement_success');
const reconnectSuccess = new Rate('app_reconnect_success');
const finalResultSuccess = new Rate('app_final_result_success');
const playerFlowSuccess = new Rate('app_player_flow_success');
const teacherFlowSuccess = new Rate('app_teacher_flow_success');
const legitimateRequestFailure = new Rate('app_legitimate_request_failure');
const duplicateAcceptedAnswers = new Counter('app_duplicate_accepted_answers');
const crossSessionEvents = new Counter('app_cross_session_events');
const clientMessagesSent = new Counter('app_messages_sent');
const clientMessagesReceived = new Counter('app_messages_received');
const heartbeatAcknowledgements = new Counter('app_heartbeat_acknowledgements');
const broadcastActionMarker = new Counter('broadcast_action_marker');
const broadcastReceiptMarker = new Counter('broadcast_receipt_marker');

const expectedDurationSeconds = Math.ceil(
  (manifest.configuration.scheduleLeadInMs
    + manifest.questionCount
      * (manifest.configuration.timing.questionOpenMs
        + manifest.configuration.timing.betweenQuestionsMs)
    + 30000) / 1000,
);

const strictThresholds = {
  app_legitimate_request_failure: [
    'rate==0',
    { threshold: 'rate<0.20', abortOnFail: true, delayAbortEval: '10s' },
  ],
  app_join_success: ['rate==1'],
  app_ws_authentication_success: ['rate==1'],
  app_answer_acknowledgement_success: ['rate==1'],
  app_final_result_success: ['rate==1'],
  app_player_flow_success: ['rate==1'],
  app_teacher_flow_success: ['rate==1'],
  app_duplicate_accepted_answers: ['count==0'],
  app_cross_session_events: ['count==0'],
};
if (manifest.configuration.reconnectPlayerCount > 0) {
  strictThresholds.app_reconnect_success = ['rate==1'];
}

export const options = {
  scenarios: {
    players: {
      executor: 'per-vu-iterations',
      exec: 'playerFlow',
      vus: manifest.requestedStudentCount,
      iterations: 1,
      maxDuration: `${expectedDurationSeconds}s`,
    },
    teachers: {
      executor: 'per-vu-iterations',
      exec: 'teacherFlow',
      vus: manifest.sessionCount,
      iterations: 1,
      maxDuration: `${expectedDurationSeconds}s`,
    },
  },
  insecureSkipTLSVerify: manifest.configuration.localSelfSignedCertificate === true,
  discardResponseBodies: false,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'],
  tags: commonTags,
  thresholds: strictThresholds,
};

function request(method, path, body, tags, expectedStatuses, headers = {}) {
  const response = http.request(method, `${baseUrl}${path}`, body, {
    headers,
    tags: { ...commonTags, ...tags },
    timeout: '10s',
  });
  const success = expectedStatuses.includes(response.status);
  legitimateRequestFailure.add(!success, tags);
  check(response, { [`${method} ${path} returned ${expectedStatuses.join('/')}`]: () => success });
  return response;
}

function responseJson(response) {
  try {
    return response.json();
  } catch (_) {
    return null;
  }
}

function waitUntil(epochMilliseconds) {
  while (Date.now() < epochMilliseconds) {
    sleep(Math.min(0.25, Math.max(0.001, (epochMilliseconds - Date.now()) / 1000)));
  }
}

function csrfToken() {
  const cookies = http.cookieJar().cookiesForURL(baseUrl);
  const values = cookies[manifest.configuration.csrfCookieName];
  return Array.isArray(values) && values.length > 0 ? values[0] : null;
}

function teacherHeaders(csrf) {
  return {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrf,
  };
}

function actionMarker(sessionSlot, questionIndex, eventType) {
  broadcastActionMarker.add(1, {
    mode,
    studentCount: String(manifest.requestedStudentCount),
    sessionSlot: String(sessionSlot),
    questionIndex: String(questionIndex),
    eventType,
  });
}

function receiptMarker(sessionSlot, questionIndex, eventType) {
  broadcastReceiptMarker.add(1, {
    mode,
    studentCount: String(manifest.requestedStudentCount),
    sessionSlot: String(sessionSlot),
    questionIndex: String(questionIndex),
    eventType,
  });
}

export function teacherFlow() {
  const sessionSlotIndex = exec.scenario.iterationInTest;
  const sessionSlot = sessionSlotIndex + 1;
  const fixture = manifest.sessions[sessionSlotIndex];
  const teacher = credentials.teachers[sessionSlotIndex];
  const tags = { role: 'teacher', sessionSlot: String(sessionSlot) };
  let flowOk = true;

  sleep(sessionSlotIndex * manifest.configuration.teacherLoginStaggerMs / 1000);
  const loginResponse = request(
    'POST',
    '/api/auth/login',
    JSON.stringify({ email: teacher.email, password: teacher.password }),
    { ...tags, endpoint: 'teacher_login' },
    [200],
    { 'Content-Type': 'application/json' },
  );
  flowOk = flowOk && loginResponse.status === 200;
  const csrf = csrfToken();
  if (csrf === null) {
    flowOk = false;
    legitimateRequestFailure.add(true, { ...tags, endpoint: 'csrf_cookie' });
  }

  let allPlayersReady = false;
  while (Date.now() < startEpochMs - 250) {
    const participantsResponse = request(
      'GET',
      `/api/sessions/${fixture.sessionId}/participants`,
      null,
      { ...tags, endpoint: 'teacher_participants' },
      [200],
    );
    const participants = responseJson(participantsResponse);
    const ready = participantsResponse.status === 200
      && participants?.participantCount === fixture.expectedStudentCount
      && participants?.connectedParticipantCount === fixture.expectedStudentCount;
    if (ready) {
      allPlayersReady = true;
      break;
    }
    sleep(0.5);
  }
  if (!allPlayersReady) {
    flowOk = false;
    legitimateRequestFailure.add(true, { ...tags, endpoint: 'players_ready' });
  }

  const controlHeaders = teacherHeaders(csrf || 'missing');
  const timing = manifest.configuration.timing;

  for (let questionIndex = 1; questionIndex <= manifest.questionCount; questionIndex += 1) {
    const questionStart = startEpochMs
      + (questionIndex - 1) * (timing.questionOpenMs + timing.betweenQuestionsMs);
    waitUntil(questionStart);
    actionMarker(sessionSlot, questionIndex, 'QUESTION_STARTED');
    const startPath = questionIndex === 1
      ? `/api/sessions/${fixture.sessionId}/start`
      : `/api/sessions/${fixture.sessionId}/questions/next`;
    const startResponse = request(
      'POST',
      startPath,
      null,
      { ...tags, endpoint: questionIndex === 1 ? 'teacher_start' : 'teacher_next', questionIndex: String(questionIndex) },
      [200],
      controlHeaders,
    );
    flowOk = flowOk && startResponse.status === 200;

    waitUntil(questionStart + timing.questionOpenMs);
    actionMarker(sessionSlot, questionIndex, 'QUESTION_CLOSED');
    const closeResponse = request(
      'POST',
      `/api/sessions/${fixture.sessionId}/questions/current/close`,
      null,
      { ...tags, endpoint: 'teacher_close', questionIndex: String(questionIndex) },
      [200],
      controlHeaders,
    );
    flowOk = flowOk && closeResponse.status === 200;
  }

  const finishAt = startEpochMs
    + manifest.questionCount * timing.questionOpenMs
    + (manifest.questionCount - 1) * timing.betweenQuestionsMs
    + timing.betweenQuestionsMs;
  waitUntil(finishAt);
  actionMarker(sessionSlot, 0, 'GAME_FINISHED');
  const finishResponse = request(
    'POST',
    `/api/sessions/${fixture.sessionId}/finish`,
    null,
    { ...tags, endpoint: 'teacher_finish' },
    [200],
    controlHeaders,
  );
  flowOk = flowOk && finishResponse.status === 200;

  const reportResponse = request(
    'GET',
    `/api/sessions/${fixture.sessionId}/results`,
    null,
    { ...tags, endpoint: 'teacher_results' },
    [200],
  );
  const report = responseJson(reportResponse);
  flowOk = flowOk
    && reportResponse.status === 200
    && report?.summary?.participantCount === fixture.expectedStudentCount;
  teacherFlowSuccess.add(flowOk, tags);
}

function questionFixture(sessionFixture, questionOrder) {
  return sessionFixture.questions.find((question) => question.questionOrder === questionOrder);
}

function selectedAnswer(state, question) {
  const fixture = questionFixture(state.sessionFixture, question.questionOrder);
  if (!fixture || fixture.sessionQuestionId !== question.id) {
    crossSessionEvents.add(1, state.tags);
    return null;
  }

  const correct = shouldAnswerCorrectly(
    manifest.configuration.correctAnswerRatio,
    manifest.seed,
    state.playerIndex,
    question.questionOrder,
  );
  return correct ? [...fixture.correctOptionIds] : validIncorrectSelection(fixture);
}

function completePlayer(state, success) {
  if (state.completed) return;
  state.completed = true;
  clearTimeout(state.watchdog);

  for (let questionIndex = 1; questionIndex <= manifest.questionCount; questionIndex += 1) {
    if (!state.acceptedQuestions.has(questionIndex)) {
      answerAcknowledgementSuccess.add(false, {
        ...state.tags,
        questionIndex: String(questionIndex),
      });
      success = false;
    }
  }

  if (!state.initialAuthenticationRecorded) {
    wsAuthenticationSuccess.add(false, state.tags);
    success = false;
  }
  if (!state.finalResultReceived) {
    finalResultSuccess.add(false, state.tags);
    success = false;
  }
  if (state.assignment.reconnect && !state.reconnectCompleted) {
    reconnectSuccess.add(false, state.tags);
    success = false;
  }
  if (state.heartbeatCount < 1) success = false;

  playerFlowSuccess.add(success, state.tags);
  if (state.socket?.readyState === 1) state.socket.close(1000);
}

function openPlayerSocket(state, reconnecting) {
  const socketStartedAt = Date.now();
  const socket = new WebSocket(webSocketUrl, null, {
    headers: { Origin: origin },
    tags: { ...commonTags, role: 'player', sessionSlot: String(state.assignment.sessionSlot) },
  });
  state.socket = socket;

  socket.addEventListener('open', () => {
    state.socketOpenedAt = socketStartedAt;
  });

  socket.addEventListener('message', (event) => {
    clientMessagesReceived.add(1, state.tags);
    let message;
    try {
      message = JSON.parse(event.data);
    } catch (_) {
      completePlayer(state, false);
      return;
    }

    if (!message || typeof message.type !== 'string' || typeof message.payload !== 'object') {
      completePlayer(state, false);
      return;
    }

    if (message.type === 'HEARTBEAT') {
      state.heartbeatCount += 1;
      socket.send(JSON.stringify({ type: 'HEARTBEAT_ACK', payload: {} }));
      clientMessagesSent.add(1, state.tags);
      heartbeatAcknowledgements.add(1, state.tags);
      return;
    }

    if (message.type === 'AUTHENTICATION_REQUIRED') {
      state.authenticationSentAt = Date.now();
      socket.send(JSON.stringify({
        type: 'PARTICIPANT_AUTHENTICATE',
        payload: { participantToken: state.participantToken },
      }));
      clientMessagesSent.add(1, state.tags);
      return;
    }

    if (message.type === 'PARTICIPANT_AUTHENTICATED') {
      const authenticatedAt = Date.now();
      const validAssociation = message.payload?.session?.id === state.sessionFixture.sessionId
        && message.payload?.participant?.id === state.participantId
        && message.payload?.participant?.sessionId === state.sessionFixture.sessionId;
      if (!validAssociation) {
        crossSessionEvents.add(1, state.tags);
        completePlayer(state, false);
        return;
      }
      const latency = authenticatedAt - state.authenticationSentAt;
      wsAuthenticationLatency.add(latency, state.tags);
      if (reconnecting) {
        state.reconnectCompleted = true;
        reconnectLatency.add(authenticatedAt - state.reconnectStartedAt, state.tags);
        reconnectSuccess.add(true, state.tags);
      } else if (!state.initialAuthenticationRecorded) {
        state.initialAuthenticationRecorded = true;
        wsAuthenticationSuccess.add(true, state.tags);
      }
      return;
    }

    if (message.type === 'QUESTION_STARTED') {
      const question = message.payload?.question;
      if (!question || !Number.isInteger(question.questionOrder)) {
        completePlayer(state, false);
        return;
      }
      if (!state.startedQuestions.has(question.questionOrder)) {
        state.startedQuestions.add(question.questionOrder);
        receiptMarker(state.assignment.sessionSlot, question.questionOrder, 'QUESTION_STARTED');
      }

      if (question.imagePath && !state.fetchedImages.has(question.questionOrder)) {
        state.fetchedImages.add(question.questionOrder);
        const mediaStartedAt = Date.now();
        http.asyncRequest('GET', `${baseUrl}${question.imagePath}`, null, {
          tags: {
            ...commonTags,
            endpoint: 'question_media',
            sessionSlot: String(state.assignment.sessionSlot),
            questionIndex: String(question.questionOrder),
            questionType: question.questionType,
          },
          timeout: '10s',
        }).then((response) => {
          const success = response.status === 200;
          legitimateRequestFailure.add(!success, { ...state.tags, endpoint: 'question_media' });
          mediaRequestLatency.add(Date.now() - mediaStartedAt, {
            ...state.tags,
            questionIndex: String(question.questionOrder),
          });
        }).catch(() => {
          legitimateRequestFailure.add(true, { ...state.tags, endpoint: 'question_media' });
        });
      }

      if (state.acceptedQuestions.has(question.questionOrder)
        || state.answerTimers.has(question.questionOrder)) return;
      const selectedOptionIds = selectedAnswer(state, question);
      if (selectedOptionIds === null) {
        completePlayer(state, false);
        return;
      }
      const delay = answerDelayMs(
        mode,
        manifest.seed,
        state.playerIndex,
        question.questionOrder,
        manifest.configuration.timing,
      );
      const timer = setTimeout(() => {
        state.answerTimers.delete(question.questionOrder);
        if (state.socket !== socket || socket.readyState !== 1 || state.completed) {
          answerAcknowledgementSuccess.add(false, {
            ...state.tags,
            questionIndex: String(question.questionOrder),
          });
          completePlayer(state, false);
          return;
        }
        state.answerSentAt.set(question.questionOrder, Date.now());
        socket.send(JSON.stringify({ type: 'ANSWER_SUBMIT', payload: { selectedOptionIds } }));
        clientMessagesSent.add(1, state.tags);
      }, delay);
      state.answerTimers.set(question.questionOrder, timer);
      return;
    }

    if (message.type === 'ANSWER_ACCEPTED') {
      const questionOrder = message.payload?.questionOrder;
      if (state.acceptedQuestions.has(questionOrder)) {
        duplicateAcceptedAnswers.add(1, state.tags);
        completePlayer(state, false);
        return;
      }
      const sentAt = state.answerSentAt.get(questionOrder);
      if (!Number.isFinite(sentAt)) {
        completePlayer(state, false);
        return;
      }
      state.acceptedQuestions.add(questionOrder);
      answerAcknowledgementLatency.add(Date.now() - sentAt, {
        ...state.tags,
        questionIndex: String(questionOrder),
        questionType: questionFixture(state.sessionFixture, questionOrder)?.questionType || 'UNKNOWN',
      });
      answerAcknowledgementSuccess.add(true, {
        ...state.tags,
        questionIndex: String(questionOrder),
      });
      return;
    }

    if (message.type === 'QUESTION_CLOSED') {
      const questionOrder = message.payload?.questionOrder;
      if (!state.closedQuestions.has(questionOrder)) {
        receiptMarker(state.assignment.sessionSlot, questionOrder, 'QUESTION_CLOSED');
      }
      state.closedQuestions.add(questionOrder);
      return;
    }

    if (message.type === 'ANSWER_RESULT') {
      const questionOrder = message.payload?.questionOrder;
      if (!state.answerResults.has(questionOrder)) {
        const sentAt = state.answerSentAt.get(questionOrder);
        if (Number.isFinite(sentAt)) {
          answerResultLatency.add(Date.now() - sentAt, {
            ...state.tags,
            questionIndex: String(questionOrder),
            questionType: questionFixture(state.sessionFixture, questionOrder)?.questionType || 'UNKNOWN',
          });
        }
      }
      state.answerResults.add(questionOrder);
      if (state.assignment.reconnect
        && !state.reconnectStarted
        && questionOrder === manifest.configuration.reconnectAfterQuestion) {
        state.reconnectStarted = true;
        state.intentionalReconnect = true;
        state.reconnectStartedAt = Date.now();
        socket.close(1000);
      }
      return;
    }

    if (message.type === 'GAME_FINISHED') {
      if (message.payload?.session?.id !== state.sessionFixture.sessionId) {
        crossSessionEvents.add(1, state.tags);
        completePlayer(state, false);
        return;
      }
      if (!state.gameFinishedReceived) {
        receiptMarker(state.assignment.sessionSlot, 0, 'GAME_FINISHED');
      }
      state.gameFinishedReceived = true;
      return;
    }

    if (message.type === 'FINAL_RESULT') {
      if (message.payload?.participantId !== state.participantId) {
        crossSessionEvents.add(1, state.tags);
        completePlayer(state, false);
        return;
      }
      state.finalResultReceived = true;
      finalResultSuccess.add(true, state.tags);
      const success = state.gameFinishedReceived
        && state.acceptedQuestions.size === manifest.questionCount
        && state.closedQuestions.size === manifest.questionCount
        && state.answerResults.size === manifest.questionCount
        && (!state.assignment.reconnect || state.reconnectCompleted);
      setTimeout(() => completePlayer(state, success), 100);
      return;
    }

    if (message.type === 'ERROR') {
      completePlayer(state, false);
    }
  });

  socket.addEventListener('error', () => {
    if (!state.intentionalReconnect && !state.completed) completePlayer(state, false);
  });

  socket.addEventListener('close', () => {
    if (state.socket !== socket || state.completed) return;
    state.socket = null;
    if (state.intentionalReconnect) {
      state.intentionalReconnect = false;
      setTimeout(
        () => openPlayerSocket(state, true),
        manifest.configuration.reconnectDelayMs,
      );
      return;
    }
    completePlayer(state, state.finalResultReceived);
  });
}

export function playerFlow() {
  const playerIndex = exec.scenario.iterationInTest;
  const assignment = manifest.players[playerIndex];
  const sessionFixture = manifest.sessions[assignment.sessionSlot - 1];
  const tags = {
    role: 'player',
    sessionSlot: String(assignment.sessionSlot),
    participantType: assignment.participantType,
  };

  const previewResponse = request(
    'GET',
    `/api/game/session/${sessionFixture.gamePin}`,
    null,
    { ...tags, endpoint: 'game_preview' },
    [200],
  );
  const preview = responseJson(previewResponse);
  if (previewResponse.status !== 200 || preview?.session?.canJoin !== true) {
    joinSuccess.add(false, tags);
    wsAuthenticationSuccess.add(false, tags);
    finalResultSuccess.add(false, tags);
    playerFlowSuccess.add(false, tags);
    return;
  }

  const joinPayload = {
    gamePin: sessionFixture.gamePin,
    participantType: assignment.participantType,
    nickname: assignment.nickname,
    avatarKey: assignment.avatarKey,
  };
  if (assignment.participantType === 'REGISTERED') {
    joinPayload.username = assignment.username;
  }
  const joinStartedAt = Date.now();
  const joinResponse = request(
    'POST',
    '/api/game/join',
    JSON.stringify(joinPayload),
    { ...tags, endpoint: 'game_join' },
    [201],
    { 'Content-Type': 'application/json' },
  );
  joinLatency.add(Date.now() - joinStartedAt, tags);
  const joined = responseJson(joinResponse);
  const validJoin = joinResponse.status === 201
    && typeof joined?.participantToken === 'string'
    && joined?.participant?.sessionId === sessionFixture.sessionId
    && joined?.session?.id === sessionFixture.sessionId;
  joinSuccess.add(validJoin, tags);
  if (!validJoin) {
    wsAuthenticationSuccess.add(false, tags);
    finalResultSuccess.add(false, tags);
    playerFlowSuccess.add(false, tags);
    return;
  }

  const state = {
    playerIndex,
    assignment,
    sessionFixture,
    tags,
    participantToken: joined.participantToken,
    participantId: joined.participant.id,
    socket: null,
    socketOpenedAt: 0,
    authenticationSentAt: 0,
    initialAuthenticationRecorded: false,
    acceptedQuestions: new Set(),
    startedQuestions: new Set(),
    closedQuestions: new Set(),
    answerResults: new Set(),
    answerSentAt: new Map(),
    answerTimers: new Map(),
    fetchedImages: new Set(),
    heartbeatCount: 0,
    reconnectStarted: false,
    reconnectStartedAt: 0,
    reconnectCompleted: false,
    intentionalReconnect: false,
    gameFinishedReceived: false,
    finalResultReceived: false,
    completed: false,
    watchdog: null,
  };
  state.watchdog = setTimeout(
    () => completePlayer(state, false),
    expectedDurationSeconds * 1000 - 1000,
  );
  openPlayerSocket(state, false);
}

export function handleSummary(data) {
  const summaryPath = __ENV.SUMMARY_PATH || '/results/k6-summary.json';
  return {
    [summaryPath]: JSON.stringify(data, null, 2),
    stdout: `k6 aggregate summary written to ${summaryPath}\n`,
  };
}
