import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { MIN_PASSWORD_LENGTH } from '../../crypto/constants';
import { ApiRequestError } from '../../lib/api/client';
import { performPasswordReset } from './authApi';

/** Flow 6 steps 3–5 — restores account access; the vault stays locked (AUTH-10). */
export function ResetPasswordPage() {
  const { t } = useTranslation('auth');
  const [params] = useSearchParams();
  const [newPassword, setNewPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await performPasswordReset(params.get('email') ?? '', params.get('token') ?? '', newPassword);
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('reset.title')}</h1>

          {error ? <Alert tone="error">{error}</Alert> : null}
          {done ? (
            <>
              <Alert tone="success">{t('reset.done')}</Alert>
              <p>
                <Link to="/login">{t('login.submit')}</Link>
              </p>
            </>
          ) : (
            <>
              <Input
                label={t('reset.newPassword')}
                type="password"
                autoComplete="new-password"
                required
                minLength={MIN_PASSWORD_LENGTH}
                hint={t('register.passwordHint')}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
              />
              <Button type="submit" busy={busy}>
                {t('reset.submit')}
              </Button>
            </>
          )}
        </form>
      </Card>
    </main>
  );
}
