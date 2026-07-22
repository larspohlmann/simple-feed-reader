// src/app/admin/admin.models.ts
export type AdminUserStatus =
  'pending_verification' | 'pending_approval' | 'active' | 'rejected' | 'suspended';

export interface AdminUserDto {
  id: number;
  email: string;
  status: AdminUserStatus;
  roles: string[];
  createdAt: string;
  approvedAt: string | null;
  identities: string[];
}

export type AdminAction = 'approve' | 'reject' | 'suspend';
