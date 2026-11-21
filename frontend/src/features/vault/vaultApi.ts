/**
 * Vault feature API orchestration — recipients, emergency contact, vault unlock
 * (docs/02_User_Flows.md Flow 2; docs/06_Cryptography.md §15). Names and relationship
 * labels are sealed with the Metadata Key before they leave the browser and opened here
 * after they come back; components only ever see plaintext views.
 */
import {
  deriveMetadataKey,
  metadataAad,
  openField,
  sealField,
  zeroize,
  type MetadataField,
} from '../../crypto';
import { api } from '../../lib/api/client';
import type {
  DeliveryMethod,
  EmergencyContactRecord,
  RecipientRecord,
  WrappedKeyPayload,
} from '../../lib/api/types';
import { unwrapWithSecret } from '../auth/authApi';
import { reportEmergencyContactNameFailure, reportRecipientFieldFailure } from './integrityReporter';

export interface RecipientInput {
  name: string;
  relationship: string;
  email: string | null;
  phone: string | null;
  preferredMethods: DeliveryMethod[];
}

/** A decrypted recipient. `name`/`relationship` are null only if a sealed field failed its integrity check. */
export interface RecipientView extends Omit<RecipientRecord, 'nameEnc' | 'relationshipEnc'> {
  name: string | null;
  relationship: string | null;
}

export interface EmergencyContactInput {
  name: string;
  email: string;
  phone: string | null;
  recipientId: number | null;
}

export interface EmergencyContactView extends Omit<EmergencyContactRecord, 'nameEnc'> {
  name: string | null;
}

/** Derive the Metadata Key for one operation and zeroize it afterwards. */
async function withMetadataKey<T>(masterKey: Uint8Array, run: (key: Uint8Array) => Promise<T>): Promise<T> {
  const key = await deriveMetadataKey(masterKey);
  try {
    return await run(key);
  } finally {
    zeroize(key);
  }
}

const seal = (value: string, key: Uint8Array, field: MetadataField, userId: number) =>
  sealField(value, key, metadataAad(field, userId));

const open = (value: string, key: Uint8Array, field: MetadataField, userId: number) =>
  openField(value, key, metadataAad(field, userId));

async function sealRecipient(input: RecipientInput, key: Uint8Array, userId: number) {
  return {
    nameEnc: await seal(input.name, key, 'recipient.name', userId),
    relationshipEnc: await seal(input.relationship, key, 'recipient.relationship', userId),
    email: input.email,
    phone: input.phone,
    preferredMethods: input.preferredMethods,
  };
}

async function openRecipient(record: RecipientRecord, key: Uint8Array, userId: number): Promise<RecipientView> {
  const { nameEnc, relationshipEnc, ...rest } = record;
  const name = await open(nameEnc, key, 'recipient.name', userId);
  const relationship = await open(relationshipEnc, key, 'recipient.relationship', userId);

  // A field that won't open is shown as an integrity warning and reported (Decision #31).
  if (name === null) {
    reportRecipientFieldFailure('recipient.name', record.id, 'recipients');
  }
  if (relationship === null) {
    reportRecipientFieldFailure('recipient.relationship', record.id, 'recipients');
  }

  return { ...rest, name, relationship };
}

// ── Recipients ────────────────────────────────────────────────────────────────────────

export async function fetchRecipients(masterKey: Uint8Array, userId: number): Promise<RecipientView[]> {
  const { recipients } = await api<{ recipients: RecipientRecord[] }>('GET', '/recipients');
  return withMetadataKey(masterKey, (key) => Promise.all(recipients.map((r) => openRecipient(r, key, userId))));
}

export async function createRecipient(masterKey: Uint8Array, userId: number, input: RecipientInput): Promise<void> {
  const body = await withMetadataKey(masterKey, (key) => sealRecipient(input, key, userId));
  await api('POST', '/recipients', body);
}

/** Re-seals every field on save — a fresh nonce each time (docs/06_Cryptography.md §8). */
export async function updateRecipient(
  masterKey: Uint8Array,
  userId: number,
  id: number,
  input: RecipientInput,
): Promise<void> {
  const body = await withMetadataKey(masterKey, (key) => sealRecipient(input, key, userId));
  await api('PATCH', `/recipients/${id}`, body);
}

/** Step-up: the current password is re-verified server-side (docs/05_Security_Architecture.md §3). */
export async function deleteRecipient(id: number, password: string): Promise<void> {
  await api('DELETE', `/recipients/${id}`, { password });
}

// ── Emergency contact ─────────────────────────────────────────────────────────────────

export async function fetchEmergencyContact(masterKey: Uint8Array, userId: number): Promise<EmergencyContactView | null> {
  const { emergencyContact } = await api<{ emergencyContact: EmergencyContactRecord | null }>(
    'GET',
    '/emergency-contact',
  );

  if (!emergencyContact) {
    return null;
  }

  const { nameEnc, ...rest } = emergencyContact;
  const name = await withMetadataKey(masterKey, (key) => open(nameEnc, key, 'emergency_contact.name', userId));
  if (name === null) {
    reportEmergencyContactNameFailure('recipients');
  }

  return { ...rest, name };
}

export async function setEmergencyContact(
  masterKey: Uint8Array,
  userId: number,
  input: EmergencyContactInput,
): Promise<void> {
  const nameEnc = await withMetadataKey(masterKey, (key) => seal(input.name, key, 'emergency_contact.name', userId));
  await api('POST', '/emergency-contact', {
    nameEnc,
    email: input.email,
    phone: input.phone,
    recipientId: input.recipientId,
  });
}

export async function removeEmergencyContact(password: string): Promise<void> {
  await api('DELETE', '/emergency-contact', { password });
}

// ── Vault unlock (docs/19_Decision_Log.md Decision #12) ──────────────────────────────

/**
 * Re-unlock after a page reload: the server re-verifies the password before releasing the
 * password-wrapped Master Key, which is then unwrapped locally. Returns null when the wrap
 * is stale (post password-reset) — the caller routes to Recovery Key recovery.
 */
export async function unlockVault(password: string): Promise<Uint8Array | null> {
  const { passwordWrappedMasterKey } = await api<{ passwordWrappedMasterKey: WrappedKeyPayload }>(
    'POST',
    '/vault/unlock',
    { password },
  );

  return unwrapWithSecret(passwordWrappedMasterKey, password);
}

/** Normalize what people type ("+962 79-123 4567", "00962…") to E.164. */
export function normalizePhone(value: string): string | null {
  const compact = value.replace(/[\s\-().]/g, '');
  if (compact === '') {
    return null;
  }
  return compact.startsWith('00') ? `+${compact.slice(2)}` : compact;
}
