export interface Student {
  id: number;
  firstName: string;
  lastName: string;
  username: string;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
}

export type StudentListItem = Student;

export type StudentDetail = Student;

export interface StudentsPagination {
  pageIndex: number;
  pageSize: number;
  totalItems: number;
  totalPages: number;
}

export interface StudentsListResponse {
  students: StudentListItem[];
  pagination: StudentsPagination;
}

export interface CreateStudentRequest {
  firstName: string;
  lastName: string;
  username: string;
}

export type UpdateStudentRequest =
  | { firstName: string; lastName?: string; username?: string }
  | { firstName?: string; lastName: string; username?: string }
  | { firstName?: string; lastName?: string; username: string };

export interface StudentResponse {
  student: StudentDetail;
}
