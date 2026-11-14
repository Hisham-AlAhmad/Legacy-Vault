import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { ApiRequestError } from '../../lib/api/client';
import { useVaultKey } from '../../lib/vaultKey';
import { login } from './authApi';

/**
 * Login — docs/02_User_Flows.md. A successful authentication whose Master Key unwrap
 * fails means the password wrap is stale (post password-reset): the user is routed to
 * Recovery Key recovery, presented as expected behavior, not an error
 * (docs/06_Cryptography.md §19).
 */
export function LoginPage() {
  const { t } = useTranslation('auth');
  const navigate = useNavigate();
  const { setMasterKey } = useVaultKey();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [needsTotp, setNeedsTotp] = useState(false);
  const [remember, setRemember] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setBusy(true);

    try {
      const result = await login(email, password, needsTotp ? totpCode : null, remember);

      if (result.masterKey) {
        setMasterKey(result.masterKey);
        navigate('/dashboard');
      } else {
        // Authenticated but vault locked (Flow 6 step 5) → recovery.
        navigate('/recover', { state: { vaultLocked: true } });
      }
    } catch (err) {
      if (err instanceof ApiRequestError && err.error.code === 'TOTP_REQUIRED') {
        setNeedsTotp(true);
      } else {
        setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('login.title')}</h1>

          {error ? <Alert tone="error">{error}</Alert> : null}

          <Input
            label={t('login.email')}
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Input
            label={t('login.password')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />

          {needsTotp ? (
            <Input
              label={t('login.totp')}
              inputMode="numeric"
              autoComplete="one-time-code"
              required
              hint={t('login.totpHint')}
              value={totpCode}
              onChange={(e) => setTotpCode(e.target.value)}
            />
          ) : null}

          <label className="muted">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />{' '}
            {t('login.remember')}
          </label>

          <Button type="submit" busy={busy}>
            {t('login.submit')}
          </Button>

          <p className="muted">
            <Link to="/forgot-password">{t('login.forgot')}</Link>
          </p>
          <p className="muted">
            {t('login.noAccount')} <Link to="/register">{t('login.createAccount')}</Link>
          </p>
        </form>
      </Card>
    </main>
  );
}
