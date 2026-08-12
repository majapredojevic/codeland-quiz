export type StaffRole = 'ADMIN' | 'TEACHER';

export type AuthStatus = 'checking' | 'authenticated' | 'unauthenticated';

export type AuthNotice = 'password-changed';

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  role: StaffRole;
  mustChangePassword: boolean;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface ChangePasswordRequest {
  currentPassword: string;
  newPassword: string;
  newPasswordConfirmation: string;
}

export interface CurrentUserResponse {
  user: StaffUser;
}

export interface LoginResponse extends CurrentUserResponse {
  expiresInSeconds: number;
}

export interface RefreshResponse {
  expiresInSeconds: number;
}

export interface ApiErrorResponse {
  error: string;
}
