/**
 * API types mirrored from docs/08_API_Specification.md — the DTO contract
 * (docs/16_Coding_Standards.md §3). camelCase here; the client boundary converts
 * from the API's snake_case (docs/16_Coding_Standards.md §4).
 */

export interface UserSummary {
  id: number;
  email: string;
  status: 'pending' | 'active' | 'suspended' | 'deceased_processing' | 'purged';
  locale: 'en' | 'ar';
  twoFactorEnabled: boolean;
  recoveryKeyAcknowledged: boolean;
  createdAt: string;
}

export interface WrappedKeyPayload {
  keyType?: string;
  /** base64 */
  ciphertext: string;
  /** base64 */
  nonce: string;
  /** base64 */
  kdfSalt: string | null;
  wrapVersion?: number;
}

export interface LoginResponse {
  user: UserSummary;
  passwordWrappedMasterKey: WrappedKeyPayload;
}

export interface SessionEntry {
  id: string;
  isCurrent: boolean;
  ipAddress: string | null;
  userAgent: string | null;
  lastActiveAt: string;
  deviceLabel: string | null;
  deviceTrusted: boolean;
}

export interface LoginHistoryEntry {
  eventType: 'auth.login_success' | 'auth.login_failed';
  ipAddress: string | null;
  userAgent: string | null;
  createdAt: string;
}

export interface TwoFactorEnrollment {
  secret: string;
  otpauthUri: string;
}

export type DeliveryMethod = 'email' | 'sms' | 'link';

/** Recipient as the API returns it — sealed fields are still ciphertext (base64). */
export interface RecipientRecord {
  id: number;
  nameEnc: string;
  relationshipEnc: string;
  email: string | null;
  phone: string | null;
  preferredMethods: DeliveryMethod[];
  isEmergencyContact: boolean;
  createdAt: string;
  updatedAt: string;
}

export interface EmergencyContactRecord {
  nameEnc: string;
  email: string;
  phone: string | null;
  linkedRecipientId: number | null;
  updatedAt: string;
}

export interface ApiError {
  code: string;
  message: string;
  fields: Record<string, string[]>;
}
