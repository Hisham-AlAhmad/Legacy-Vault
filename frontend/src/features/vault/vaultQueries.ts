/**
 * Shared query plumbing for the vault pages. Cache keys are scoped per user
 * (docs/10_Frontend_Architecture.md §3) and every mutation invalidates the keys it
 * affects.
 */
import { useQuery } from '@tanstack/react-query';
import { getMasterKey } from '../../lib/vaultKey';
import { fetchMe } from '../auth/authApi';
import { fetchRecipients } from './vaultApi';

/** The unwrapped Master Key. Pages using this render inside VaultUnlockGate, which guarantees it exists. */
export function requireMasterKey(): Uint8Array {
  const key = getMasterKey();
  if (!key) {
    throw new Error('vault-locked');
  }
  return key;
}

export const vaultKeys = {
  recipients: (userId: number | undefined) => ['recipients', userId] as const,
  items: (userId: number | undefined, recipientId: number) => ['items', userId, recipientId] as const,
  item: (userId: number | undefined, itemId: string) => ['item', userId, itemId] as const,
};

/** The signed-in user's ID (cached by the route guard's `me` query). */
export function useUserId(): number | undefined {
  const { data } = useQuery({ queryKey: ['me'], queryFn: fetchMe });
  return data?.id;
}

/** The user's recipients, names decrypted — the same cache entry the recipients page uses. */
export function useRecipients(userId: number | undefined) {
  return useQuery({
    queryKey: vaultKeys.recipients(userId),
    queryFn: () => fetchRecipients(requireMasterKey(), userId!),
    enabled: userId !== undefined,
  });
}
