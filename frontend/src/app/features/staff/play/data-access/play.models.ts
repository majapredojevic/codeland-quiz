import { Pagination, QuizTopic } from '../../quizzes/data-access/quizzes.models';

export type QuizSessionStatus = 'WAITING' | 'ACTIVE' | 'FINISHED';

export interface PlayableQuiz {
  id: number;
  title: string;
  description: string | null;
  questionCount: number;
  topic: QuizTopic | null;
}

export interface RecentPlayableQuiz extends PlayableQuiz {
  lastPlayedAt: string | null;
}

export interface QuizSession {
  id: number;
  quizId: number;
  quiz: {
    title: string;
    version: number;
  };
  host: {
    id: number;
    name: string;
  };
  gamePin: string;
  status: QuizSessionStatus;
  currentQuestionOrder: number | null;
  currentQuestionStartedAt: string | null;
  currentQuestionDeadline: string | null;
  currentQuestionClosedAt: string | null;
  joinDeadline: string | null;
  startedAt: string | null;
  endedAt: string | null;
  createdAt: string;
  questionCount: number;
  participantCount: number;
}

export interface QuizSessionResponse {
  session: QuizSession;
}

export interface PublicSessionQuestionOption {
  id: number;
  optionText: string;
  optionOrder: number;
}

export interface PublicSessionQuestion {
  id: number;
  questionText: string;
  questionType: 'TRUE_FALSE' | 'SINGLE_CHOICE' | 'MULTIPLE_CHOICE';
  imagePath: string | null;
  timeLimitSeconds: number;
  maxPoints: number;
  questionOrder: number;
  options: PublicSessionQuestionOption[];
}

export interface SessionLeaderboardEntry {
  rank: number;
  participantId: number;
  participantType: 'REGISTERED' | 'GUEST';
  nickname: string;
  avatarKey: string;
  totalScore: number;
  pointsAwardedThisQuestion: number;
}

export interface QuestionResult {
  question: PublicSessionQuestion;
  closedAt: string;
  correctOptionIds: number[];
  stats: {
    participantCount: number;
    answerCount: number;
    correctAnswerCount: number;
    incorrectAnswerCount: number;
    unansweredCount: number;
  };
  participantResults: unknown[];
  leaderboard: SessionLeaderboardEntry[];
}

export interface FinalLeaderboardEntry {
  rank: number;
  participantId: number;
  participantType: 'REGISTERED' | 'GUEST';
  nickname: string;
  avatarKey: string;
  totalScore: number;
  answerCount: number;
  correctAnswerCount: number;
}

export interface FinalResult {
  participantCount: number;
  totalAnswerCount: number;
  totalCorrectAnswerCount: number;
  topThree: FinalLeaderboardEntry[];
  leaderboard: FinalLeaderboardEntry[];
}

export interface QuizSessionStateResponse extends QuizSessionResponse {
  currentQuestion: PublicSessionQuestion | null;
  questionResult: QuestionResult | null;
  finalResult: FinalResult | null;
}

export interface QuizSessionHistoryItem {
  id: number;
  quizId: number;
  quiz: {
    title: string;
    version: number;
  };
  host: {
    id: number;
    name: string;
  };
  gamePin: string;
  status: QuizSessionStatus;
  questionCount: number;
  participantCount: number;
  removedParticipantCount: number;
  startedAt: string | null;
  endedAt: string | null;
  createdAt: string;
}

export interface QuizSessionHistoryResponse {
  sessions: QuizSessionHistoryItem[];
  pagination: Pagination;
}

export interface SessionParticipant {
  id: number;
  participantType: 'REGISTERED' | 'GUEST';
  student: {
    id: number;
    firstName: string;
    lastName: string;
    username: string;
  } | null;
  nickname: string;
  avatarKey: string;
  totalScore: number;
  isConnected: boolean;
  disconnectedAt: string | null;
  joinedAt: string;
  hasAnsweredCurrentQuestion: boolean;
}

export interface SessionParticipantsResponse {
  session: {
    id: number;
    status: QuizSessionStatus;
    currentQuestionOrder: number | null;
  };
  participants: SessionParticipant[];
  participantCount: number;
  connectedParticipantCount: number;
  answeredCurrentQuestionCount: number;
}

export interface StartQuizSessionResponse {
  session: QuizSession;
  currentQuestion: PublicSessionQuestion;
  questionCount: number;
  stateChanged: boolean;
}

export interface CloseQuestionResponse {
  session: QuizSession;
  questionResult: QuestionResult;
  stateChanged: boolean;
}

export interface StartNextQuestionResponse {
  session: QuizSession;
  currentQuestion: PublicSessionQuestion;
  questionCount: number;
  previousQuestionOrder: number;
}

export interface FinishQuizSessionResponse {
  session: QuizSession;
  finalResult: FinalResult;
  stateChanged: boolean;
}
