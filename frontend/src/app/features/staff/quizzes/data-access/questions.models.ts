export type QuestionType = 'TRUE_FALSE' | 'SINGLE_CHOICE' | 'MULTIPLE_CHOICE';

export interface QuestionOptionItem {
  id: number;
  optionText: string;
  isCorrect: boolean;
  optionOrder: number;
}

export interface QuestionItem {
  id: number;
  quizId: number;
  questionText: string;
  questionType: QuestionType;
  imagePath: string | null;
  timeLimitSeconds: number;
  maxPoints: number;
  questionOrder: number;
  options: QuestionOptionItem[];
  createdAt: string;
  updatedAt: string;
}

export interface QuestionsListResponse {
  questions: QuestionItem[];
  questionCount: number;
}

export interface QuestionResponse {
  question: QuestionItem;
}

export interface QuestionOptionInput {
  optionText: string;
  isCorrect: boolean;
}

export interface CreateQuestionRequest {
  questionText: string;
  questionType: QuestionType;
  imagePath: string | null;
  timeLimitSeconds: number;
  maxPoints: number;
  options: QuestionOptionInput[];
}

type QuestionWritableFields = CreateQuestionRequest;

export type UpdateQuestionRequest = Partial<QuestionWritableFields> &
  (
    | { questionText: string }
    | { questionType: QuestionType }
    | { imagePath: string | null }
    | { timeLimitSeconds: number }
    | { maxPoints: number }
    | { options: QuestionOptionInput[] }
  );

export interface ReorderQuestionsRequest {
  questionIds: number[];
}

export const QUESTION_TYPE_LABELS: Record<QuestionType, string> = {
  TRUE_FALSE: 'Tačno / Netačno',
  SINGLE_CHOICE: 'Jedan tačan odgovor',
  MULTIPLE_CHOICE: 'Više tačnih odgovora',
};

export const QUESTION_TYPE_BADGES: Record<QuestionType, string> = {
  TRUE_FALSE: 'Tačno / Netačno',
  SINGLE_CHOICE: 'Jedan odgovor',
  MULTIPLE_CHOICE: 'Više odgovora',
};
