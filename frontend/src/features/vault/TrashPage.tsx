import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Dialog } from '../../components/Dialog';
import { Input } from '../../components/Input';
import { ApiRequestError } from '../../lib/api/client';
import { isolate } from './bidi';
import { formatBytes, formatDate } from './formatBytes';
import { StorageMeter } from './StorageMeter';
import { eraseFromTrash, fetchTrash, restoreFromTrash, type TrashKind } from './trashApi';
import { VaultUnlockGate } from './VaultUnlockGate';
import { requireMasterKey, useUserId, vaultKeys } from './vaultQueries';
import './TrashPage.css';

/** Recently deleted — restore within the undo window, or erase now (docs/19_Decision_Log.md Decision #28). */
export function TrashPage() {
  return (
    <VaultUnlockGate>
      <TrashContent />
    </VaultUnlockGate>
  );
}

/** One entry on the page, whatever its kind. */
interface Entry {
  kind: TrashKind;
  id: string | number;
  /** Decrypted name/title, or null when it failed its integrity check. */
  label: string | null;
  detail: string | null;
  deletedAt: string;
  purgeAt: string;
}

function TrashContent() {
  const { t, i18n } = useTranslation('vault');
  const queryClient = useQueryClient();
  const userId = useUserId();
  const trashKey = vaultKeys.trash(userId);

  const trashQuery = useQuery({
    queryKey: trashKey,
    queryFn: () => fetchTrash(requireMasterKey(), userId!),
    enabled: userId !== undefined,
  });

  const [notice, setNotice] = useState<'trash.restored' | 'trash.erased' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [erasing, setErasing] = useState<Entry | null>(null);

  // Restoring or erasing changes every vault view; refetch them all.
  const refreshAll = () =>
    Promise.all(
      ['trash', 'recipients', 'items', 'item', 'attachments', 'storage'].map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
    );

  const restore = useMutation({
    mutationFn: (entry: Entry) => restoreFromTrash(entry.kind, entry.id),
    onMutate: () => {
      setNotice(null);
      setError(null);
    },
    onSuccess: async () => {
      await refreshAll();
      setNotice('trash.restored');
    },
    onError: (err) =>
      setError(
        err instanceof ApiRequestError && err.status === 403 ? t('trash.errors.limit') : t('trash.errors.restore'),
      ),
  });

  const view = trashQuery.data;
  const date = (iso: string) => isolate(formatDate(iso, i18n.language, false));

  const groups: Array<{ heading: string; entries: Entry[] }> = view
    ? [
        {
          heading: t('trash.recipients'),
          entries: view.recipients.map((r) => ({
            kind: 'recipients',
            id: r.id,
            label: r.name,
            detail: t('trash.itemCount', { n: r.itemCount }),
            deletedAt: r.deletedAt,
            purgeAt: r.purgeAt,
          })),
        },
        {
          heading: t('trash.items'),
          entries: view.items.map((i) => ({
            kind: 'items',
            id: i.id,
            label: i.title,
            detail: t(i.itemType === 'file_bundle' ? 'items.typeBundle' : 'items.typeMessage'),
            deletedAt: i.deletedAt,
            purgeAt: i.purgeAt,
          })),
        },
        {
          heading: t('trash.files'),
          entries: view.attachments.map((a) => ({
            kind: 'attachments',
            id: a.id,
            label: a.filename,
            detail: formatBytes(a.plainBytes, i18n.language),
            deletedAt: a.deletedAt,
            purgeAt: a.purgeAt,
          })),
        },
      ]
    : [];
  const empty = view !== undefined && groups.every((g) => g.entries.length === 0);

  return (
    <main className="page page--wide">
      <h1>{t('trash.title')}</h1>
      {view ? <p className="muted">{t('trash.intro', { days: view.undoWindowDays })}</p> : null}

      {view ? (
        <Card className="trash-page__storage">
          <StorageMeter storage={view.storage} />
        </Card>
      ) : null}

      {notice ? <Alert tone="success">{t(notice)}</Alert> : null}
      {error ? <Alert tone="error">{error}</Alert> : null}
      {trashQuery.isError ? <Alert tone="error">{t('trash.loadError')}</Alert> : null}
      {trashQuery.isPending ? <p className="muted">{t('common:loading')}</p> : null}

      {empty ? (
        <Card className="trash-page__empty">
          <p className="muted">{t('trash.empty')}</p>
        </Card>
      ) : null}

      {groups
        .filter((group) => group.entries.length > 0)
        .map((group) => (
          <section key={group.heading} className="trash-page__group" aria-label={group.heading}>
            <h2 className="trash-page__heading">{group.heading}</h2>
            <ul className="trash-page__list">
              {group.entries.map((entry) => {
                const name = entry.label ?? t('trash.unreadable');
                return (
                  <li key={`${entry.kind}-${entry.id}`}>
                    <Card className="trash-row">
                      <div className="trash-row__main">
                        <p className="trash-row__name" dir={entry.label !== null ? 'auto' : undefined}>
                          {name}
                        </p>
                        <p className="muted trash-row__meta">
                          {entry.detail ? <>{entry.detail} · </> : null}
                          {t('trash.deletedOn', { date: date(entry.deletedAt) })} · {t('trash.erasedOn', { date: date(entry.purgeAt) })}
                        </p>
                      </div>
                      <div className="trash-row__actions">
                        <Button
                          busy={restore.isPending && restore.variables?.id === entry.id}
                          disabled={restore.isPending}
                          aria-label={t('trash.restoreNamed', { name: isolate(name) })}
                          onClick={() => restore.mutate(entry)}
                        >
                          {t('trash.restore')}
                        </Button>
                        <Button
                          variant="ghost"
                          aria-label={t('trash.eraseNamed', { name: isolate(name) })}
                          onClick={() => {
                            setNotice(null);
                            setErasing(entry);
                          }}
                        >
                          {t('trash.erase')}
                        </Button>
                      </div>
                    </Card>
                  </li>
                );
              })}
            </ul>
          </section>
        ))}

      <Dialog open={erasing !== null} title={t('trash.eraseDialog.title')} onClose={() => setErasing(null)}>
        {erasing ? (
          <EraseForm
            name={erasing.label ?? t('trash.unreadable')}
            onConfirm={async (password) => {
              await eraseFromTrash(erasing.kind, erasing.id, password);
              await refreshAll();
              setErasing(null);
              setNotice('trash.erased');
            }}
            onClose={() => setErasing(null)}
          />
        ) : null}
      </Dialog>
    </main>
  );
}

/**
 * Irreversible, so the password is asked again (step-up, docs/05_Security_Architecture.md
 * §3) and the dialog says plainly that it can't be undone.
 */
function EraseForm({ name, onConfirm, onClose }: { name: string; onConfirm: (password: string) => Promise<void>; onClose: () => void }) {
  const { t } = useTranslation('vault');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
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
      <p>{t('trash.eraseDialog.body', { name: isolate(name) })}</p>
      {error ? <Alert tone="error">{error}</Alert> : null}
      <Input
        label={t('trash.eraseDialog.password')}
        type="password"
        autoComplete="current-password"
        required
        value={password}
        onChange={(e) => setPassword(e.target.value)}
      />
      <div className="actions-row">
        <Button type="submit" busy={busy} disabled={password === ''}>
          {t('trash.eraseDialog.submit')}
        </Button>
        <Button variant="ghost" onClick={onClose} disabled={busy} autoFocus>
          {t('items.cancel')}
        </Button>
      </div>
    </form>
  );
}
