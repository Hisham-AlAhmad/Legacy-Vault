import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';
import { Input } from '../../components/Input';
import { ApiRequestError } from '../../lib/api/client';
import { isolate } from './bidi';

interface DeleteRecipientDialogProps {
  open: boolean;
  /** Decrypted name, or null if it failed its integrity check. */
  name: string | null;
  /** The undo window from the server; the note is left out until it is known. */
  undoWindowDays: number | undefined;
  onConfirm: (password: string) => Promise<void>;
  onClose: () => void;
}

/**
 * Destructive confirmation — the name must be typed, never a single click
 * (docs/03_UI_UX_Guidelines.md §4), plus step-up password re-entry
 * (docs/05_Security_Architecture.md §3).
 */
export function DeleteRecipientDialog({ open, name, undoWindowDays, onConfirm, onClose }: DeleteRecipientDialogProps) {
  const { t } = useTranslation('vault');

  return (
    <Dialog open={open} title={t('delete.title')} onClose={onClose}>
      <DeleteRecipientForm name={name} undoWindowDays={undoWindowDays} onConfirm={onConfirm} onClose={onClose} />
    </Dialog>
  );
}

function DeleteRecipientForm({ name, undoWindowDays, onConfirm, onClose }: Omit<DeleteRecipientDialogProps, 'open'>) {
  const { t } = useTranslation('vault');
  const expected = name ?? t('delete.fallbackWord');
  const [typed, setTyped] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const matches = typed.trim() === expected.trim();

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!matches) {
      return;
    }

    setBusy(true);
    setError(null);
    try {
      await onConfirm(password);
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      setBusy(false);
    }
  };

  return (
    <form className="stack" onSubmit={submit}>
      <p>{t('delete.body', { name: isolate(expected) })}</p>
      {undoWindowDays !== undefined ? <p className="muted">{t('delete.undoNote', { days: undoWindowDays })}</p> : null}
      {error ? <Alert tone="error">{error}</Alert> : null}
      <Input
        label={t('delete.confirmLabel')}
        hint={t('delete.confirmHint', { expected: isolate(expected) })}
        autoComplete="off"
        required
        value={typed}
        onChange={(e) => setTyped(e.target.value)}
      />
      <Input
        label={t('delete.password')}
        type="password"
        autoComplete="current-password"
        required
        value={password}
        onChange={(e) => setPassword(e.target.value)}
      />
      <div className="actions-row">
        <Button type="submit" busy={busy} disabled={!matches || password === ''}>
          {t('delete.submit')}
        </Button>
        <Button variant="ghost" onClick={onClose} disabled={busy}>
          {t('form.cancel')}
        </Button>
      </div>
    </form>
  );
}
