import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import { ZxcvbnFactory } from '@zxcvbn-ts/core';
import * as zxcvbnCommon from '@zxcvbn-ts/language-common';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { MIN_PASSWORD_LENGTH, MIN_PASSWORD_SCORE } from '../../crypto/constants';
import { ApiRequestError } from '../../lib/api/client';
import { useVaultKey } from '../../lib/vaultKey';
import { acknowledgeRecoveryKey, register } from './authApi';
import { RecoveryKeyScreen } from './RecoveryKeyScreen';

const zxcvbn = new ZxcvbnFactory({
  dictionary: zxcvbnCommon.dictionary,
  graphs: zxcvbnCommon.adjacencyGraphs,
});

/** Flow 1 — registration & vault key setup (docs/02_User_Flows.md). */
export function RegisterPage() {
  const { t, i18n } = useTranslation('auth');
  const navigate = useNavigate();
  const { setMasterKey } = useVaultKey();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [recoveryKey, setRecoveryKey] = useState<string | null>(null);
  const [pendingMasterKey, setPendingMasterKey] = useState<Uint8Array | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    if (password !== passwordConfirm) {
      setFieldErrors({ passwordConfirm: t('register.passwordMismatch') });
      return;
    }

    // Client-side strength check for fast feedback (AUTH-2); the server re-checks.
    if (password.length < MIN_PASSWORD_LENGTH || zxcvbn.check(password, [email]).score < MIN_PASSWORD_SCORE) {
      setFieldErrors({ password: t('register.passwordTooWeak') });
      return;
    }

    setBusy(true);
    try {
      const result = await register(email, password, i18n.language === 'ar' ? 'ar' : 'en');
      setPendingMasterKey(result.masterKey);
      setRecoveryKey(result.recoveryKeyDisplay); // step 4: shown once, must acknowledge
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
    } finally {
      setBusy(false);
    }
  };

  const finishRegistration = async () => {
    setBusy(true);
    try {
      await acknowledgeRecoveryKey();
      if (pendingMasterKey) {
        setMasterKey(pendingMasterKey);
      }
      navigate('/dashboard');
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      setBusy(false);
    }
  };

  if (recoveryKey) {
    return <RecoveryKeyScreen recoveryKey={recoveryKey} onAcknowledged={finishRegistration} busy={busy} />;
  }

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('register.title')}</h1>
          <p className="muted">{t('register.subtitle')}</p>

          {error ? <Alert tone="error">{error}</Alert> : null}

          <Input
            label={t('register.email')}
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Input
            label={t('register.password')}
            type="password"
            autoComplete="new-password"
            required
            minLength={MIN_PASSWORD_LENGTH}
            hint={t('register.passwordHint')}
            error={fieldErrors.password}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Input
            label={t('register.passwordConfirm')}
            type="password"
            autoComplete="new-password"
            required
            error={fieldErrors.passwordConfirm}
            value={passwordConfirm}
            onChange={(e) => setPasswordConfirm(e.target.value)}
          />

          <Button type="submit" busy={busy}>
            {busy ? t('register.generatingKeys') : t('register.submit')}
          </Button>

          <p className="muted">
            {t('register.haveAccount')} <Link to="/login">{t('register.signIn')}</Link>
          </p>
        </form>
      </Card>
    </main>
  );
}
