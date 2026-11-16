import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';
import { ApiRequestError } from '../../lib/api/client';
import { isolate } from './bidi';

interface DeleteItemDialogProps {
  open: boolean;
  /** Decrypted title, or null if the item failed its integrity check. */
  title: string | null;
  recipientName: string;
  /** The undo window from the server; the note is left out until it is known. */
  undoWindowDays: number | undefined;
  onConfirm: () => Promise<void>;
  onClose: () => void;
}

/**
 * Destructive confirmation with the message's title restated — never a single click
 * (docs/03_UI_UX_Guidelines.md §4). No password step: see docs/19_Decision_Log.md
 * Decision #17.
 */
export function DeleteItemDialog({ open, title, recipientName, undoWindowDays, onConfirm, onClose }: DeleteItemDialogProps) {
  const { t } = useTranslation('vault');

  return (
    <Dialog open={open} title={t('items.deleteDialog.title')} onClose={onClose}>
      <DeleteItemForm
        title={title}
        recipientName={recipientName}
        undoWindowDays={undoWindowDays}
        onConfirm={onConfirm}
        onClose={onClose}
      />
    </Dialog>
  );
}

function DeleteItemForm({ title, recipientName, undoWindowDays, onConfirm, onClose }: Omit<DeleteItemDialogProps, 'open'>) {
  const { t } = useTranslation('vault');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const confirm = async () => {
    setBusy(true);
    setError(null);
    try {
      await onConfirm();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      setBusy(false);
    }
  };

  return (
    <div className="stack">
      <p>
        {title === null
          ? t('items.deleteDialog.bodyUnreadable', { name: isolate(recipientName) })
          : t('items.deleteDialog.body', { title: isolate(title), name: isolate(recipientName) })}
      </p>
      {undoWindowDays !== undefined ? <p className="muted">{t('items.deleteDialog.undoNote', { days: undoWindowDays })}</p> : null}
      {error ? <Alert tone="error">{error}</Alert> : null}
      <div className="actions-row">
        <Button busy={busy} onClick={() => void confirm()}>
          {t('items.deleteDialog.submit')}
        </Button>
        <Button variant="ghost" onClick={onClose} disabled={busy} autoFocus>
          {t('items.cancel')}
        </Button>
      </div>
    </div>
  );
}
