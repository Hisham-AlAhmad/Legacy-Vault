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

/**
 * Vault item as the list endpoint returns it — everything is still ciphertext (base64
 * envelopes); enough to decrypt the title, without the body. `id` is the item's
 * client-generated UUID (docs/08_API_Specification.md).
 */
export interface VaultItemSummaryRecord {
  id: string;
  recipientId: number;
  itemType: 'message' | 'file_bundle';
  titleEnc: string;
  contentKeyWrapped: string;
  aadVersion: number;
  position: number;
  createdAt: string;
  updatedAt: string;
}

/** A single vault item, including its body ciphertext — `null` for a file bundle, which has no body. */
export interface VaultItemRecord extends VaultItemSummaryRecord {
  contentEnc: string | null;
}

/**
 * An attachment as the API returns it (docs/08_API_Specification.md §Attachments). The
 * three sealed values are base64 envelopes; `sizeBytes` is ciphertext bytes. `id` is the
 * client-generated attachment UUID and `itemId` the owning item's UUID.
 */
export interface AttachmentRecord {
  id: string;
  itemId: string;
  filenameEnc: string;
  mimeTypeEnc: string;
  attachmentKeyWrapped: string;
  sizeBytes: number;
  chunkCount: number;
  receivedChunks: number;
  status: 'uploading' | 'complete';
  aadVersion: number;
  createdAt: string;
  updatedAt: string;
}

/** The attachment quota, all in ciphertext bytes — reported by the server, never hard-coded. */
export interface StorageUsage {
  limitBytes: number;
  usedBytes: number;
  /** The part of usedBytes held by deleted files awaiting purge. */
  trashedBytes: number;
}

export interface TrashFields {
  deletedAt: string;
  purgeAt: string;
}

/** GET /trash — still ciphertext; the client decrypts names and titles. */
export interface TrashListing {
  undoWindowDays: number;
  recipients: Array<RecipientRecord & TrashFields & { itemCount: number }>;
  items: Array<VaultItemSummaryRecord & TrashFields>;
  attachments: Array<AttachmentRecord & TrashFields & { recipientId: number }>;
  storage: StorageUsage;
}

export interface ApiError {
  code: string;
  message: string;
  fields: Record<string, string[]>;
}
