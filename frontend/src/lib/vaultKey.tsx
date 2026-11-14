/**
 * VaultKeyProvider — docs/10_Frontend_Architecture.md §3.
 *
 * The unwrapped Master Key lives in a module-level in-memory variable (not React state,
 * so it's never serialized by devtools state inspection), cleared on logout and tab end.
 * Never persisted to any storage API (AI_RULES.md #2).
 */
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { zeroize } from '../crypto';

let masterKeyInMemory: Uint8Array | null = null;

interface VaultKeyContextValue {
  /** Whether the vault is unlocked in this session (the key itself is never exposed via context). */
  unlocked: boolean;
  setMasterKey: (key: Uint8Array) => void;
  clearMasterKey: () => void;
}

const VaultKeyContext = createContext<VaultKeyContextValue | null>(null);

/** Read access for crypto operations only — features should prefer useVaultKey().unlocked. */
export function getMasterKey(): Uint8Array | null {
  return masterKeyInMemory;
}

export function VaultKeyProvider({ children }: { children: ReactNode }) {
  const [unlocked, setUnlocked] = useState(false);

  const setMasterKey = useCallback((key: Uint8Array) => {
    if (masterKeyInMemory) {
      zeroize(masterKeyInMemory);
    }
    masterKeyInMemory = key;
    setUnlocked(true);
  }, []);

  const clearMasterKey = useCallback(() => {
    if (masterKeyInMemory) {
      zeroize(masterKeyInMemory);
    }
    masterKeyInMemory = null;
    setUnlocked(false);
  }, []);

  return (
    <VaultKeyContext.Provider value={{ unlocked, setMasterKey, clearMasterKey }}>
      {children}
    </VaultKeyContext.Provider>
  );
}

export function useVaultKey(): VaultKeyContextValue {
  const context = useContext(VaultKeyContext);
  if (!context) {
    throw new Error('useVaultKey must be used within VaultKeyProvider');
  }
  return context;
}
