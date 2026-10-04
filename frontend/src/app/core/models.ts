export type Locale = 'nl' | 'en';
export type SystemRole = 'user' | 'admin';
export type BoardRole = 'owner' | 'editor' | 'viewer';

export interface User {
  id: number;
  email: string;
  name: string;
  role: SystemRole;
  locale: Locale;
}

export interface SessionResponse {
  access_token: string;
  token_type: 'Bearer';
  expires_in: number;
  user: User;
}

/** Registration while email verification is on: no session until the link is clicked. */
export interface VerificationPending {
  verification_required: true;
  email: string;
}

export interface BoardSummary {
  id: number;
  name: string;
  description: string | null;
  owner_id: number;
  my_role: BoardRole;
  member_count: number;
  card_count: number;
  created_at: string;
  updated_at: string;
}

export interface Card {
  id: number;
  column_id: number;
  title: string;
  description: string | null;
  position: number;
  assignee_id: number | null;
  created_by: number | null;
  created_at: string;
  updated_at: string;
}

export interface BoardColumn {
  id: number;
  name: string;
  position: number;
  cards: Card[];
}

export interface BoardMember {
  user_id: number;
  name: string;
  email: string;
  role: BoardRole;
  created_at: string;
}

export interface BoardInvitation {
  id: number;
  email: string;
  role: Exclude<BoardRole, 'owner'>;
  created_at: string;
}

export interface Board {
  id: number;
  name: string;
  description: string | null;
  owner_id: number;
  my_role: BoardRole;
  columns: BoardColumn[];
  members: BoardMember[];
  invitations: BoardInvitation[];
  created_at: string;
  updated_at: string;
}

export interface AdminUser {
  id: number;
  email: string;
  name: string;
  role: SystemRole;
  locale: Locale;
  is_active: number;
  email_verified_at: string | null;
  last_login_at: string | null;
  created_at: string;
  board_count: number;
}

export type MailEncryption = 'tls' | 'ssl' | 'none';

export interface MailSettings {
  enabled: boolean;
  host: string;
  port: number;
  encryption: MailEncryption;
  username: string;
  from_address: string;
  from_name: string;
  has_password: boolean;
}
