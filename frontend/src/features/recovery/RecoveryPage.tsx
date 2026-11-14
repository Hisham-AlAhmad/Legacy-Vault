import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { normalizeAndValidateRecoveryKey } from '../../crypto/recoveryKey';
import { ApiRequestError } from '../../lib/api/client';
import { useVaultKey } from '../../lib/vaultKey';
import { recoverVault } from '../auth/authApi';
import { RecoveryKeyScreen } from '../auth/RecoveryKeyScreen';

/**
 * Flow 7 — vault recovery via Recovery Key. Requires an authenticated session (account
 * access restored via password reset first, or normal login with a locked vault).
 */
export function RecoveryPage() {
  const { t } = useTranslation('auth');
  const navigate = useNavigate();
  const location = useLocation();
  const { setMasterKey } = useVaultKey();

  const vaultLockedNote = Boolean((location.state as { vaultLocked?: boolean } | null)?.vaultLocked);

  const [recoveryKeyInput, setRecoveryKeyInput] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [newRecoveryKey, setNewRecoveryKey] = useState<string | null>(null);
  const [recoveredKey, setRecoveredKey] = useState<Uint8Array | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);

    // Checksum catches typos locally, before any KDF work (docs/06_Cryptography.md §11).
    const normalized = await normalizeAndValidateRecoveryKey(recoveryKeyInput);
    if (!normalized) {
      setError(t('recovery.badChecksum'));
      return;
    }

    setBusy(true);
    try {
      const result = await recoverVault(normalized, password);

      if (result === null) {
        setError(t('recovery.wrongKey'));
        return;
      }

      // Show the NEW Recovery Key (old one is now invalid) before entering the app.
      setRecoveredKey(result.masterKey);
      setNewRecoveryKey(result.newRecoveryKeyDisplay);
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
    } finally {
      setBusy(false);
    }
  };

  if (newRecoveryKey) {
    return (
      <RecoveryKeyScreen
        recoveryKey={newRecoveryKey}
        titleKey="recoveryKey.newKeyTitle"
        showOldInvalidNote
        onAcknowledged={() => {
          if (recoveredKey) {
            setMasterKey(recoveredKey);
          }
          navigate('/dashboard');
        }}
      />
    );
  }

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('recovery.title')}</h1>

          {vaultLockedNote ? (
            <Alert tone="warning">
              <p>
                <strong>{t('login.vaultLockedTitle')}</strong>
              </p>
              <p>{t('login.vaultLockedBody')}</p>
            </Alert>
          ) : null}

          <p className="muted">{t('recovery.explain')}</p>

          {error ? <Alert tone="error">{error}</Alert> : null}

          <Input
            label={t('recovery.recoveryKey')}
            required
            autoComplete="off"
            spellCheck={false}
            className="mono"
            dir="ltr"
            value={recoveryKeyInput}
            onChange={(e) => setRecoveryKeyInput(e.target.value)}
          />
          <Input
            label={t('recovery.currentPassword')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />

          <Button type="submit" busy={busy}>
            {t('recovery.submit')}
          </Button>
        </form>
      </Card>
    </main>
  );
}
