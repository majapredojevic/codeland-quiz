export interface StaffReference {
  id: number;
  name: string;
}

export interface Pagination {
  pageIndex: number;
  pageSize: number;
  totalItems: number;
  totalPages: number;
}

export interface TopicItem {
  id: number;
  name: string;
  description: string | null;
  quizCount: number;
  createdBy: StaffReference;
  updatedBy: StaffReference;
  createdAt: string;
  updatedAt: string;
}

export interface TopicsListResponse {
  topics: TopicItem[];
  pagination: Pagination;
}

export interface TopicResponse {
  topic: TopicItem;
}

export interface CreateTopicRequest {
  name: string;
  description: string | null;
}

export type UpdateTopicRequest = Partial<CreateTopicRequest> &
  ({ name: string } | { description: string | null });

export type QuizStatusFilter = 'all' | 'active' | 'inactive';
export type QuizSort = 'recent' | 'titleAsc' | 'titleDesc';

export interface QuizTopic {
  id: number;
  name: string | null;
}

export interface QuizItem {
  id: number;
  title: string;
  version: number;
  description: string | null;
  isActive: boolean;
  questionCount: number;
  topic: QuizTopic | null;
  createdBy: StaffReference;
  updatedBy: StaffReference;
  createdAt: string;
  updatedAt: string;
}

export interface QuizzesListResponse {
  quizzes: QuizItem[];
  pagination: Pagination;
}

export interface QuizListQuery {
  pageIndex: number;
  pageSize: number;
  search: string;
  topicId: number | null;
  status: QuizStatusFilter;
  sort: QuizSort;
}
