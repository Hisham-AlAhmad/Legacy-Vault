/**
 * Auth feature API orchestration — where the key hierarchy meets the API
 * (docs/02_User_Flows.md Flows 1, 6, 7, 8; docs/06_Cryptography.md §4–5).
 * Components never call fetch directly (docs/16_Coding_Standards.md §2).
 */
import {
  deriveKeyFromPassword,
  deriveKeyFromRecoveryKey,
  fromBase64,
  generateKdfSalt,
  generateMasterKey,
  toBase64,
  unwrapKey,
  wrapKey,
  zeroize,
} from '../../crypto';
import { generateRecoveryKey } from '../../crypto/recoveryKey';
import { api } from '../../lib/api/client';
import type {
  LoginHistoryEntry,
  LoginResponse,
  SessionEntry,
  TwoFactorEnrollment,
  UserSummary,
  WrappedKeyPayload,
} from '../../lib/api/types';

interface WrappedKeyBody {
  ciphertext: string;
  nonce: string;
  kdfSalt: string;
}

async function wrapMasterKeyWithSecret(masterKey: Uint8Array, secret: string): Promise<WrappedKeyBody> {
  const salt = generateKdfSalt();
  const kek = await deriveKeyFromPassword(secret, salt);
  const wrapped = await wrapKey(masterKey, kek);
  zeroize(kek);

  return {
    ciphertext: toBase64(wrapped.ciphertext),
    nonce: toBase64(wrapped.nonce),
    kdfSalt: toBase64(salt),
  };
}

export interface RegistrationResult {
  user: UserSummary;
  masterKey: Uint8Array;
  recoveryKeyDisplay: string;
}

/**
 * Flow 1 steps 3–5: generate the Master Key + Recovery Key in-browser, wrap both copies,
 * and send the plaintext Master Key ONCE over TLS for the server-side SWK wrap
 * (docs/06_Cryptography.md §5 — the one documented exception, discarded after wrapping).
 */
export async function register(email: string, password: string, locale: 'en' | 'ar'): Promise<RegistrationResult> {
  const masterKey = generateMasterKey();
  const recovery = await generateRecoveryKey();

  const passwordWrapped = await wrapMasterKeyWithSecret(masterKey, password);
  const rdkSalt = generateKdfSalt();
  const rdk = await deriveKeyFromRecoveryKey(recovery.normalized, rdkSalt);
  const recoveryWrapped = await wrapKey(masterKey, rdk);
  zeroize(rdk);

  const response = await api<{ user: UserSummary }>('POST', '/auth/register', {
    email,
    password,
    locale,
    passwordWrappedMasterKey: passwordWrapped,
    recoveryWrappedMasterKey: {
      ciphertext: toBase64(recoveryWrapped.ciphertext),
      nonce: toBase64(recoveryWrapped.nonce),
      kdfSalt: toBase64(rdkSalt),
    },
    serverWrapMasterKeyPlaintext: toBase64(masterKey),
  });

  return { user: response.user, masterKey, recoveryKeyDisplay: recovery.display };
}

export async function acknowledgeRecoveryKey(): Promise<void> {
  await api('POST', '/vault/recovery-key/acknowledge');
}

export interface LoginResult {
  user: UserSummary;
  /** null when the wrap is stale (post password-reset) — vault locked, route to recovery. */
  masterKey: Uint8Array | null;
}

/** Flow 3 of the session: authenticate, then unwrap the Master Key locally. */
export async function login(
  email: string,
  password: string,
  totpCode: string | null,
  remember: boolean,
): Promise<LoginResult> {
  const response = await api<LoginResponse>('POST', '/auth/login', {
    email,
    password,
    totpCode,
    remember,
  });

  const masterKey = await unwrapWithSecret(response.passwordWrappedMasterKey, password);

  return { user: response.user, masterKey };
}

/** Unwrap a password-wrapped Master Key locally; null on a wrong password or stale wrap. */
export async function unwrapWithSecret(payload: WrappedKeyPayload, secret: string): Promise<Uint8Array | null> {
  if (!payload.kdfSalt) {
    return null;
  }

  const kek = await deriveKeyFromPassword(secret, fromBase64(payload.kdfSalt));
  const masterKey = await unwrapKey(fromBase64(payload.ciphertext), fromBase64(payload.nonce), kek);
  zeroize(kek);

  return masterKey;
}

export async function logout(): Promise<void> {
  await api('POST', '/auth/logout');
}

export async function fetchMe(): Promise<UserSummary> {
  const response = await api<{ user: UserSummary }>('GET', '/auth/me');
  return response.user;
}

/** Flow 8: verify current password server-side while re-wrapping the Master Key client-side. */
export async function changePassword(
  masterKey: Uint8Array,
  currentPassword: string,
  newPassword: string,
): Promise<void> {
  await api('POST', '/auth/password/change', {
    currentPassword,
    newPassword,
    passwordWrappedMasterKey: await wrapMasterKeyWithSecret(masterKey, newPassword),
  });
}

export async function requestPasswordReset(email: string): Promise<void> {
  await api('POST', '/auth/password/forgot', { email });
}

export async function performPasswordReset(email: string, token: string, newPassword: string): Promise<void> {
  await api('POST', '/auth/password/reset', { email, token, newPassword });
}

export interface RecoveryResult {
  masterKey: Uint8Array;
  newRecoveryKeyDisplay: string;
}

/**
 * Flow 7: unwrap the Master Key with the entered Recovery Key, re-wrap under the current
 * password + a freshly issued Recovery Key, and rotate server-side (old key invalidated).
 * Returns null masterKey (throws RecoveryError) when the Recovery Key is wrong.
 */
export async function recoverVault(
  normalizedRecoveryKey: string,
  currentPassword: string,
): Promise<RecoveryResult | null> {
  const { recoveryWrappedMasterKey } = await api<{ recoveryWrappedMasterKey: WrappedKeyPayload }>(
    'POST',
    '/vault/recovery-key/verify',
  );

  const masterKey = await unwrapWithRecoveryKey(recoveryWrappedMasterKey, normalizedRecoveryKey);

  if (masterKey === null) {
    return null; // wrong Recovery Key — GCM tag failed client-side
  }

  const fresh = await generateRecoveryKey();
  const rdkSalt = generateKdfSalt();
  const rdk = await deriveKeyFromRecoveryKey(fresh.normalized, rdkSalt);
  const recoveryWrapped = await wrapKey(masterKey, rdk);
  zeroize(rdk);

  await api('POST', '/vault/recovery-key/rotate', {
    password: currentPassword,
    recoveryWrappedMasterKey: {
      ciphertext: toBase64(recoveryWrapped.ciphertext),
      nonce: toBase64(recoveryWrapped.nonce),
      kdfSalt: toBase64(rdkSalt),
    },
    passwordWrappedMasterKey: await wrapMasterKeyWithSecret(masterKey, currentPassword),
  });

  return { masterKey, newRecoveryKeyDisplay: fresh.display };
}

async function unwrapWithRecoveryKey(
  payload: WrappedKeyPayload,
  normalizedRecoveryKey: string,
): Promise<Uint8Array | null> {
  if (!payload.kdfSalt) {
    return null;
  }

  const rdk = await deriveKeyFromRecoveryKey(normalizedRecoveryKey, fromBase64(payload.kdfSalt));
  const masterKey = await unwrapKey(fromBase64(payload.ciphertext), fromBase64(payload.nonce), rdk);
  zeroize(rdk);

  return masterKey;
}

/** Security-settings rotation (vault already unlocked): issue a new Recovery Key only. */
export async function rotateRecoveryKey(masterKey: Uint8Array, password: string): Promise<string> {
  const fresh = await generateRecoveryKey();
  const rdkSalt = generateKdfSalt();
  const rdk = await deriveKeyFromRecoveryKey(fresh.normalized, rdkSalt);
  const recoveryWrapped = await wrapKey(masterKey, rdk);
  zeroize(rdk);

  await api('POST', '/vault/recovery-key/rotate', {
    password,
    recoveryWrappedMasterKey: {
      ciphertext: toBase64(recoveryWrapped.ciphertext),
      nonce: toBase64(recoveryWrapped.nonce),
      kdfSalt: toBase64(rdkSalt),
    },
  });

  return fresh.display;
}

// ── 2FA & sessions ────────────────────────────────────────────────────────────────────

export async function enableTwoFactor(password: string): Promise<TwoFactorEnrollment> {
  return api<TwoFactorEnrollment>('POST', '/auth/2fa/enable', { password });
}

export async function confirmTwoFactor(code: string): Promise<string[]> {
  const response = await api<{ backupCodes: string[] }>('POST', '/auth/2fa/confirm', { code });
  return response.backupCodes;
}

export async function disableTwoFactor(password: string, code: string): Promise<void> {
  await api('POST', '/auth/2fa/disable', { password, code });
}

export async function fetchSessions(): Promise<SessionEntry[]> {
  const response = await api<{ sessions: SessionEntry[] }>('GET', '/auth/sessions');
  return response.sessions;
}

export async function revokeSession(idHash: string, password?: string): Promise<void> {
  await api('DELETE', `/auth/sessions/${idHash}`, password ? { password } : undefined);
}

export async function fetchLoginHistory(): Promise<LoginHistoryEntry[]> {
  const response = await api<{ entries: LoginHistoryEntry[] }>('GET', '/auth/login-history');
  return response.entries;
}
