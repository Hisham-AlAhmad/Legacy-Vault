import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { ApiRequestError } from '../../lib/api/client';
import { useVaultKey } from '../../lib/vaultKey';
import { unlockVault } from './vaultApi';

/**
 * Renders its children only while the vault is unlocked. The Master Key lives in memory
 * only (docs/10_Frontend_Architecture.md §3), so after a reload the session is still
 * valid but the vault is locked — this asks for the password again
 * (docs/19_Decision_Log.md Decision #12). A stale wrap (post password-reset) routes to
 * Recovery Key recovery, as the login page does.
 */
export function VaultUnlockGate({ children }: { children: ReactNode }) {
  const { t } = useTranslation('vault');
  const navigate = useNavigate();
  const { unlocked, setMasterKey } = useVaultKey();
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (unlocked) {
    return <>{children}</>;
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setBusy(true);

    try {
      const masterKey = await unlockVault(password);
      if (masterKey) {
        setMasterKey(masterKey);
      } else {
        navigate('/recover', { state: { vaultLocked: true } });
      }
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
    } finally {
      setPassword('');
      setBusy(false);
    }
  };

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('unlock.title')}</h1>
          <p className="muted">{t('unlock.explain')}</p>
          {error ? <Alert tone="error">{error}</Alert> : null}
          <Input
            label={t('unlock.password')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Button type="submit" busy={busy}>
            {t('unlock.submit')}
          </Button>
        </form>
      </Card>
    </main>
  );
}
