import type { StaffRole } from '../../../../core/auth/auth.models';

export type ManagedUserRole = Extract<StaffRole, 'TEACHER'>;

export interface UserListItem {
  id: number;
  name: string;
  email: string;
  role: ManagedUserRole;
  isActive: boolean;
  mustChangePassword: boolean;
}

export type UserDetail = UserListItem;

export interface UsersPagination {
  pageIndex: number;
  pageSize: number;
  totalItems: number;
  totalPages: number;
}

export interface UsersListResponse {
  users: UserListItem[];
  pagination: UsersPagination;
}

export interface CreateUserRequest {
  name: string;
  email: string;
}

export type UpdateUserRequest = { name: string; email?: string } | { name?: string; email: string };

export interface CreatedUser {
  id: number;
  name: string;
  email: string;
  role: ManagedUserRole;
}

export interface UserResponse {
  user: UserDetail;
}

export interface CreateUserResponse {
  user: CreatedUser;
  temporaryPassword: string;
}

export interface TemporaryPasswordResponse {
  user: UserDetail;
  temporaryPassword: string;
}
