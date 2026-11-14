import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { requestPasswordReset } from './authApi';

/** Flow 6 steps 1–2 — the response is identical whether or not the email exists (AUTH-9). */
export function ForgotPasswordPage() {
  const { t } = useTranslation('auth');
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    try {
      await requestPasswordReset(email);
    } finally {
      // Always the same outcome for the user, by design.
      setSent(true);
      setBusy(false);
    }
  };

  return (
    <main className="page">
      <Card>
        <form className="stack" onSubmit={submit}>
          <h1>{t('forgot.title')}</h1>
          <p className="muted">{t('forgot.explain')}</p>
          <Alert tone="info">{t('forgot.vaultNote')}</Alert>

          {sent ? <Alert tone="success">{t('forgot.sent')}</Alert> : null}

          <Input
            label={t('login.email')}
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          <Button type="submit" busy={busy}>
            {t('forgot.submit')}
          </Button>
        </form>
      </Card>
    </main>
  );
}
