import { Pagination } from '../../quizzes/data-access/quizzes.models';

export type SessionHistorySort = 'RECENT' | 'OLDEST' | 'QUIZ_TITLE_ASC' | 'QUIZ_TITLE_DESC';
export type ParticipantType = 'REGISTERED' | 'GUEST';

export interface ResultQuizReference {
  id?: number;
  title: string;
  version: number;
}

export interface ResultHostReference {
  id: number;
  name: string;
}

export interface SessionHistoryItem {
  id: number;
  quizId: number;
  quiz: ResultQuizReference;
  host: ResultHostReference;
  gamePin: string;
  status: 'FINISHED' | 'ACTIVE' | 'WAITING';
  questionCount: number;
  participantCount: number;
  removedParticipantCount: number;
  startedAt: string | null;
  endedAt: string | null;
  createdAt: string;
}

export interface SessionHistoryQuery {
  pageIndex: number;
  pageSize: number;
  search?: string;
  status: 'FINISHED';
  quizId?: number;
  sort: SessionHistorySort;
}

export interface SessionHistoryResponse {
  sessions: SessionHistoryItem[];
  pagination: Pagination;
}

export interface SessionReportQuestionOption {
  id: number;
  optionText: string;
  isCorrect: boolean;
  optionOrder: number;
}

export interface SessionReportQuestionStats {
  participantCount: number;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  averageResponseTimeMs: number | null;
}

export interface SessionReportQuestion {
  id: number;
  questionText: string;
  questionType: string;
  imagePath: string | null;
  timeLimitSeconds: number;
  maxPoints: number;
  questionOrder: number;
  options: SessionReportQuestionOption[];
  stats: SessionReportQuestionStats;
}

export interface SessionLeaderboardEntry {
  rank: number;
  participantId: number;
  participantType: ParticipantType;
  nickname: string;
  avatarKey: string;
  totalScore: number;
  answerCount: number;
  correctAnswerCount: number;
}

export interface SessionReportStudent {
  id: number;
  firstName: string;
  lastName: string;
  username: string;
}

export interface SessionReportParticipantAnswer {
  sessionQuestionId: number;
  questionOrder: number;
  answered: boolean;
  selectedOptionIds: number[];
  isCorrect: boolean | null;
  responseTimeMs: number | null;
  pointsAwarded: number;
  answeredAt: string | null;
}

export interface SessionReportParticipant {
  participantId: number;
  participantType: ParticipantType;
  student: SessionReportStudent | null;
  nickname: string;
  avatarKey: string;
  totalScore: number;
  isRemoved: boolean;
  removedAt: string | null;
  finalRank: number | null;
  answerCount: number;
  correctAnswerCount: number;
  answers: SessionReportParticipantAnswer[];
}

export interface SessionReport {
  session: SessionHistoryItem & {
    currentQuestionOrder: number | null;
    currentQuestionStartedAt: string | null;
    currentQuestionDeadline: string | null;
    currentQuestionClosedAt: string | null;
    joinDeadline: string | null;
  };
  summary: {
    participantCount: number;
    removedParticipantCount: number;
    totalAnswerCount: number;
    totalCorrectAnswerCount: number;
    highestScore: number;
    averageScore: number | null;
  };
  leaderboard: SessionLeaderboardEntry[];
  questions: SessionReportQuestion[];
  participants: SessionReportParticipant[];
}

export interface QuizStatisticsSummary {
  finishedSessionCount: number;
  participantEntryCount: number;
  registeredParticipationCount: number;
  guestParticipationCount: number;
  uniqueRegisteredStudentCount: number;
  averageParticipantsPerSession: number | null;
  totalPossibleAnswerCount: number;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  accuracyPercentage: number | null;
  answerRatePercentage: number | null;
  highestScore: number;
  averageScore: number | null;
}

export interface QuizQuestionStatistics {
  sourceQuestionId: number;
  questionText: string;
  questionType: string;
  latestQuestionOrder: number;
  isCurrentlyDeleted: boolean;
  sessionCount: number;
  participantOpportunityCount: number;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  accuracyPercentage: number | null;
  answerRatePercentage: number | null;
  averageResponseTimeMs: number | null;
  averagePointsAwarded: number | null;
}

export interface QuizStatistics {
  quiz: Required<ResultQuizReference>;
  summary: QuizStatisticsSummary;
  questions: QuizQuestionStatistics[];
}

export interface StudentStatisticsSummary {
  finishedSessionCount: number;
  distinctQuizCount: number;
  totalPossibleAnswerCount: number;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  accuracyPercentage: number | null;
  answerRatePercentage: number | null;
  totalScore: number;
  averageScore: number | null;
  averageScorePercentage: number | null;
  highestScore: number;
  highestScorePercentage: number | null;
  averageResponseTimeMs: number | null;
  topThreeCount: number;
  firstPlaceCount: number;
}

export interface StudentQuizStatistics {
  quizId: number;
  quizTitle: string;
  quizVersion: number;
  finishedSessionCount: number;
  totalPossibleAnswerCount: number;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  accuracyPercentage: number | null;
  answerRatePercentage: number | null;
  totalScore: number;
  averageScore: number | null;
  averageScorePercentage: number | null;
  highestScore: number;
  highestScorePercentage: number | null;
  averageResponseTimeMs: number | null;
  topThreeCount: number;
  firstPlaceCount: number;
}

export interface StudentStatistics {
  student: {
    id: number;
    firstName: string;
    lastName: string;
    username: string;
    isActive: boolean;
  };
  summary: StudentStatisticsSummary;
  quizzes: StudentQuizStatistics[];
}

export interface StudentSessionPerformance {
  sessionId: number;
  quiz: Required<ResultQuizReference>;
  startedAt: string;
  endedAt: string;
  questionCount: number;
  maxPossibleScore: number;
  totalScore: number;
  scorePercentage: number | null;
  answerCount: number;
  correctAnswerCount: number;
  incorrectAnswerCount: number;
  unansweredCount: number;
  accuracyPercentage: number | null;
  answerRatePercentage: number | null;
  averageResponseTimeMs: number | null;
  participantCount: number;
  finalRank: number;
}

export interface StudentSessionPerformanceResponse {
  sessions: StudentSessionPerformance[];
  pagination: Pagination;
}
