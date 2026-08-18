export type ParticipantType = 'REGISTERED' | 'GUEST';
export type QuizSessionStatus = 'WAITING' | 'ACTIVE' | 'FINISHED';
export type QuestionType = 'TRUE_FALSE' | 'SINGLE_CHOICE' | 'MULTIPLE_CHOICE';

export type PlayerPhase =
  | 'JOIN'
  | 'WAITING'
  | 'QUESTION_OPEN'
  | 'ANSWER_SUBMITTED'
  | 'QUESTION_RESULT'
  | 'BETWEEN_QUESTIONS'
  | 'FINISHED'
  | 'REMOVED'
  | 'REPLACED'
  | 'RECONNECTING'
  | 'TERMINAL_ERROR';

export interface GamePreviewResponse {
  session: {
    quiz: { title: string; version: number };
    status: QuizSessionStatus;
    participantCount: number;
    canJoin: boolean;
    joinDeadline: string | null;
  };
  avatarKeys: string[];
}

export type JoinGameRequest =
  | {
      participantType: 'REGISTERED';
      gamePin: string;
      username: string;
      nickname: string;
      avatarKey: string;
    }
  | {
      participantType: 'GUEST';
      gamePin: string;
      nickname: string;
      avatarKey: string;
    };

export interface PlayerParticipant {
  id: number;
  sessionId: number;
  participantType: ParticipantType;
  nickname: string;
  avatarKey: string;
  totalScore: number;
  isConnected: boolean;
  joinedAt: string;
}

export interface PlayerSession {
  id: number;
  quiz: { title: string; version: number };
  gamePin?: string;
  status: QuizSessionStatus;
  currentQuestionOrder?: number | null;
  questionCount?: number;
}

export interface JoinGameResponse {
  participant: PlayerParticipant & { studentId: number | null };
  session: PlayerSession & { gamePin: string };
  participantToken: string;
  participantTokenExpiresAt: string;
}

export interface PlayerQuestionOption {
  id: number;
  optionText: string;
  optionOrder: number;
}

export interface PlayerQuestion {
  id: number;
  questionText: string;
  questionType: QuestionType;
  imagePath: string | null;
  timeLimitSeconds: number;
  maxPoints: number;
  questionOrder: number;
  questionCount: number;
  options: PlayerQuestionOption[];
}

export interface PlayerAnswerResult {
  questionOrder: number;
  answered: boolean;
  selectedOptionIds: number[];
  isCorrect: boolean | null;
  responseTimeMs: number | null;
  pointsAwarded: number;
  totalScore: number;
  answeredAt: string | null;
}

export interface PlayerFinalResult {
  rank: number;
  participantId: number;
  participantType: ParticipantType;
  nickname: string;
  avatarKey: string;
  totalScore: number;
  answerCount: number;
  correctAnswerCount: number;
}

export interface StoredParticipantSession {
  version: 1;
  gamePin: string;
  participant: PlayerParticipant;
  session: PlayerSession & { gamePin: string };
  participantToken: string;
  participantTokenExpiresAt: string;
}

export interface WebSocketEnvelope<TPayload extends object = Record<string, unknown>> {
  type: string;
  payload: TPayload;
}
