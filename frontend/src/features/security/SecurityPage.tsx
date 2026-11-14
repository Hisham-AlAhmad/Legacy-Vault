import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { MIN_PASSWORD_LENGTH } from '../../crypto/constants';
import { ApiRequestError } from '../../lib/api/client';
import { getMasterKey, useVaultKey } from '../../lib/vaultKey';
import {
  changePassword,
  confirmTwoFactor,
  disableTwoFactor,
  enableTwoFactor,
  fetchLoginHistory,
  fetchMe,
  fetchSessions,
  revokeSession,
  rotateRecoveryKey,
} from '../auth/authApi';
import { RecoveryKeyScreen } from '../auth/RecoveryKeyScreen';
import './SecurityPage.css';

function errorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiRequestError ? err.error.message : fallback;
}

/** Security settings — AUTH-4/6/7, Flows 8 & 10. */
export function SecurityPage() {
  const { t } = useTranslation('dashboard');

  return (
    <main className="page page--wide">
      <h1>{t('security.title')}</h1>
      <div className="security-grid">
        <TwoFactorCard />
        <ChangePasswordCard />
        <RecoveryKeyCard />
        <SessionsCard />
        <LoginHistoryCard />
      </div>
    </main>
  );
}

function ChangePasswordCard() {
  const { t } = useTranslation('dashboard');
  const { unlocked } = useVaultKey();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [message, setMessage] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);

  const mutation = useMutation({
    mutationFn: async () => {
      const masterKey = getMasterKey();
      if (!masterKey) {
        throw new Error('vault-locked');
      }
      await changePassword(masterKey, current, next);
    },
    onSuccess: () => {
      setMessage({ tone: 'success', text: t('security.changePassword.done') });
      setCurrent('');
      setNext('');
    },
    onError: (err) => setMessage({ tone: 'error', text: errorMessage(err, t('common:errors.generic')) }),
  });

  return (
    <Card>
      <h2>{t('security.changePassword.title')}</h2>
      {!unlocked ? <Alert tone="warning">{t('security.changePassword.vaultLocked')}</Alert> : null}
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <form
        className="stack"
        onSubmit={(e) => {
          e.preventDefault();
          setMessage(null);
          mutation.mutate();
        }}
      >
        <Input
          label={t('security.changePassword.current')}
          type="password"
          autoComplete="current-password"
          required
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
        />
        <Input
          label={t('security.changePassword.new')}
          type="password"
          autoComplete="new-password"
          required
          minLength={MIN_PASSWORD_LENGTH}
          value={next}
          onChange={(e) => setNext(e.target.value)}
        />
        <Button type="submit" busy={mutation.isPending} disabled={!unlocked}>
          {t('security.changePassword.submit')}
        </Button>
      </form>
    </Card>
  );
}

function TwoFactorCard() {
  const { t } = useTranslation('dashboard');
  const queryClient = useQueryClient();
  const { data: user } = useQuery({ queryKey: ['me'], queryFn: fetchMe });

  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [enrollment, setEnrollment] = useState<{ secret: string; otpauthUri: string } | null>(null);
  const [backupCodes, setBackupCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['me'] });

  const start = useMutation({
    mutationFn: () => enableTwoFactor(password),
    onSuccess: (data) => {
      setEnrollment(data);
      setError(null);
    },
    onError: (err) => setError(errorMessage(err, t('common:errors.generic'))),
  });

  const confirm = useMutation({
    mutationFn: () => confirmTwoFactor(code),
    onSuccess: (codes) => {
      setBackupCodes(codes);
      setEnrollment(null);
      setError(null);
      void refresh();
    },
    onError: (err) => setError(errorMessage(err, t('common:errors.generic'))),
  });

  const disable = useMutation({
    mutationFn: () => disableTwoFactor(password, code),
    onSuccess: () => {
      setError(null);
      setPassword('');
      setCode('');
      void refresh();
    },
    onError: (err) => setError(errorMessage(err, t('common:errors.generic'))),
  });

  return (
    <Card>
      <h2>{t('security.twoFactor.title')}</h2>
      <p className="muted">{t('security.twoFactor.explain')}</p>
      {error ? <Alert tone="error">{error}</Alert> : null}

      {backupCodes ? (
        <div className="stack">
          <h3>{t('security.twoFactor.backupTitle')}</h3>
          <Alert tone="warning">{t('security.twoFactor.backupExplain')}</Alert>
          <ul className="security-backup-codes mono">
            {backupCodes.map((backupCode) => (
              <li key={backupCode}>{backupCode}</li>
            ))}
          </ul>
          <Button variant="secondary" onClick={() => setBackupCodes(null)}>
            {t('security.twoFactor.enabledNote')}
          </Button>
        </div>
      ) : user?.twoFactorEnabled ? (
        <form
          className="stack"
          onSubmit={(e) => {
            e.preventDefault();
            disable.mutate();
          }}
        >
          <Alert tone="success">{t('security.twoFactor.enabledNote')}</Alert>
          <Input
            label={t('security.twoFactor.password')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Input
            label={t('security.twoFactor.code')}
            inputMode="numeric"
            autoComplete="one-time-code"
            required
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
          <Button type="submit" variant="secondary" busy={disable.isPending}>
            {t('security.twoFactor.disable')}
          </Button>
        </form>
      ) : enrollment ? (
        <form
          className="stack"
          onSubmit={(e) => {
            e.preventDefault();
            confirm.mutate();
          }}
        >
          <p className="muted">{t('security.twoFactor.scan')}</p>
          <p>
            {t('security.twoFactor.secret')}: <code className="mono">{enrollment.secret}</code>
          </p>
          <Input
            label={t('security.twoFactor.code')}
            inputMode="numeric"
            autoComplete="one-time-code"
            required
            value={code}
            onChange={(e) => setCode(e.target.value)}
          />
          <Button type="submit" busy={confirm.isPending}>
            {t('security.twoFactor.confirm')}
          </Button>
        </form>
      ) : (
        <form
          className="stack"
          onSubmit={(e) => {
            e.preventDefault();
            start.mutate();
          }}
        >
          <Input
            label={t('security.twoFactor.password')}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Button type="submit" busy={start.isPending}>
            {t('security.twoFactor.start')}
          </Button>
        </form>
      )}
    </Card>
  );
}

function RecoveryKeyCard() {
  const { t } = useTranslation('dashboard');
  const { unlocked } = useVaultKey();
  const [password, setPassword] = useState('');
  const [newKey, setNewKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const rotate = useMutation({
    mutationFn: async () => {
      const masterKey = getMasterKey();
      if (!masterKey) {
        throw new Error('vault-locked');
      }
      return rotateRecoveryKey(masterKey, password);
    },
    onSuccess: (display) => {
      setNewKey(display);
      setPassword('');
      setError(null);
    },
    onError: (err) => setError(errorMessage(err, t('common:errors.generic'))),
  });

  if (newKey) {
    return (
      <RecoveryKeyScreen
        recoveryKey={newKey}
        titleKey="recoveryKey.newKeyTitle"
        showOldInvalidNote
        onAcknowledged={() => setNewKey(null)}
      />
    );
  }

  return (
    <Card>
      <h2>{t('security.recoveryKey.title')}</h2>
      <p className="muted">{t('security.recoveryKey.explain')}</p>
      {!unlocked ? <Alert tone="warning">{t('security.recoveryKey.vaultLocked')}</Alert> : null}
      {error ? <Alert tone="error">{error}</Alert> : null}
      <form
        className="stack"
        onSubmit={(e) => {
          e.preventDefault();
          rotate.mutate();
        }}
      >
        <Input
          label={t('security.recoveryKey.password')}
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <Button type="submit" busy={rotate.isPending} disabled={!unlocked}>
          {t('security.recoveryKey.rotate')}
        </Button>
      </form>
    </Card>
  );
}

function SessionsCard() {
  const { t } = useTranslation('dashboard');
  const queryClient = useQueryClient();
  const { data: sessions } = useQuery({ queryKey: ['sessions'], queryFn: fetchSessions });
  const [passwordFor, setPasswordFor] = useState<string | null>(null);
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const revoke = useMutation({
    mutationFn: ({ id, pw }: { id: string; pw?: string }) => revokeSession(id, pw),
    onSuccess: () => {
      setPasswordFor(null);
      setPassword('');
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['sessions'] });
    },
    onError: (err) => setError(errorMessage(err, t('common:errors.generic'))),
  });

  return (
    <Card>
      <h2>{t('security.sessions.title')}</h2>
      {error ? <Alert tone="error">{error}</Alert> : null}
      {!sessions?.length ? <p className="muted">{t('security.sessions.empty')}</p> : null}
      <ul className="security-sessions">
        {sessions?.map((session) => (
          <li key={session.id} className="security-sessions__item">
            <div>
              <p>
                {session.deviceLabel ?? session.userAgent ?? '—'}{' '}
                {session.isCurrent ? <strong>· {t('security.sessions.current')}</strong> : null}
                {session.deviceTrusted ? <span className="muted"> · {t('security.sessions.trusted')}</span> : null}
              </p>
              <p className="muted">
                {session.ipAddress} · {t('security.sessions.lastActive')}:{' '}
                {new Date(session.lastActiveAt).toLocaleString()}
              </p>
              {passwordFor === session.id ? (
                <form
                  className="stack"
                  onSubmit={(e) => {
                    e.preventDefault();
                    revoke.mutate({ id: session.id, pw: password });
                  }}
                >
                  <Input
                    label={t('security.sessions.revokeCurrentNeedsPassword')}
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                  <Button type="submit" variant="secondary" busy={revoke.isPending}>
                    {t('security.sessions.revoke')}
                  </Button>
                </form>
              ) : (
                <Button
                  variant="ghost"
                  onClick={() =>
                    session.isCurrent ? setPasswordFor(session.id) : revoke.mutate({ id: session.id })
                  }
                >
                  {t('security.sessions.revoke')}
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}

function LoginHistoryCard() {
  const { t } = useTranslation('dashboard');
  const { data: entries } = useQuery({ queryKey: ['login-history'], queryFn: fetchLoginHistory });

  return (
    <Card>
      <h2>{t('security.history.title')}</h2>
      {!entries?.length ? <p className="muted">{t('security.history.empty')}</p> : null}
      <ul className="security-history">
        {entries?.map((entry, index) => (
          <li key={index} className="security-history__item">
            <span className={entry.eventType === 'auth.login_failed' ? 'security-history__failed' : ''}>
              {entry.eventType === 'auth.login_failed'
                ? t('security.history.failed')
                : t('security.history.success')}
            </span>
            <span className="muted">
              {entry.ipAddress} · {new Date(entry.createdAt).toLocaleString()}
            </span>
          </li>
        ))}
      </ul>
    </Card>
  );
}
