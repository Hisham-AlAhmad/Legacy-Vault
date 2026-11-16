import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Dialog } from '../../components/Dialog';
import { AttachmentIntegrityError, ciphertextSizeFor, EmptyAttachmentError } from '../../crypto/attachments';
import type { ItemContext } from '../../crypto/items';
import { ApiRequestError } from '../../lib/api/client';
import { deleteAttachment, downloadAttachment, fetchAttachments, uploadAttachment, type AttachmentView } from './attachmentsApi';
import { isolate } from './bidi';
import { formatBytes, formatDate } from './formatBytes';
import { StorageMeter } from './StorageMeter';
import { restoreFromTrash } from './trashApi';
import { requireMasterKey, useStorageInfo, vaultKeys } from './vaultQueries';
import './AttachmentList.css';

interface AttachmentListProps {
  /** The item the files belong to — also the AAD context (docs/06_Cryptography.md §9). */
  item: ItemContext;
  recipientName: string;
}

interface UploadState {
  name: string;
  sent: number;
  total: number;
  controller: AbortController;
}

type Notice =
  | { kind: 'uploaded'; name: string }
  | { kind: 'deleted'; id: string }
  | { kind: 'restored' }
  | { kind: 'cancelled' };

/**
 * An item's files: upload with progress and cancel, the list with decrypted names,
 * download, delete with undo, and the storage meter (Phase 2c — VAULT-6, VAULT-7).
 * Every file is encrypted in the browser before upload (docs/06_Cryptography.md §12).
 */
export function AttachmentList({ item, recipientName }: AttachmentListProps) {
  const { t, i18n } = useTranslation('vault');
  const queryClient = useQueryClient();
  const { userId, itemId } = item;
  const inputRef = useRef<HTMLInputElement>(null);

  const listKey = vaultKeys.attachments(userId, itemId);
  const listQuery = useQuery({ queryKey: listKey, queryFn: () => fetchAttachments(requireMasterKey(), item) });
  const storageQuery = useStorageInfo(userId);

  const [upload, setUpload] = useState<UploadState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [deleting, setDeleting] = useState<AttachmentView | null>(null);
  const [downloading, setDownloading] = useState<{ id: string; percent: number } | null>(null);

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: listKey }),
      queryClient.invalidateQueries({ queryKey: vaultKeys.storage(userId) }),
      queryClient.invalidateQueries({ queryKey: vaultKeys.trash(userId) }),
    ]);

  // Leaving the page mid-upload cancels it, which also releases its quota reservation.
  const uploadRef = useRef<UploadState | null>(null);
  uploadRef.current = upload;
  useEffect(() => () => uploadRef.current?.controller.abort(), []);

  // A reload or tab close would abandon the upload — warn first.
  useEffect(() => {
    if (!upload) {
      return;
    }
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [upload]);

  const startUpload = async (file: File) => {
    setError(null);
    setNotice(null);

    const storage = storageQuery.data?.storage;
    const needed = ciphertextSizeFor(file.size);
    // Saves a round trip with an obviously-too-big file. The server enforces the quota
    // regardless (NFR-3) — this check is a courtesy, not the control.
    if (storage && needed > storage.limitBytes - storage.usedBytes) {
      setError(
        t('files.errors.quota', {
          needed: formatBytes(needed, i18n.language),
          remaining: formatBytes(Math.max(0, storage.limitBytes - storage.usedBytes), i18n.language),
        }),
      );
      return;
    }

    const controller = new AbortController();
    setUpload({ name: file.name, sent: 0, total: 0, controller });
    let outcome: { notice: Notice } | { error: string };

    try {
      await uploadAttachment(requireMasterKey(), item, file, {
        signal: controller.signal,
        onProgress: ({ sentChunks, totalChunks }) =>
          setUpload((current) => (current ? { ...current, sent: sentChunks, total: totalChunks } : current)),
      });
      outcome = { notice: { kind: 'uploaded', name: file.name } };
    } catch (err) {
      outcome = controller.signal.aborted ? { notice: { kind: 'cancelled' } } : { error: uploadErrorMessage(err) };
    }

    // Announce only once the list and the storage numbers show the result.
    await refresh();
    setUpload(null);
    if ('notice' in outcome) {
      setNotice(outcome.notice);
    } else {
      setError(outcome.error);
    }
  };

  const uploadErrorMessage = (err: unknown): string => {
    if (err instanceof EmptyAttachmentError) {
      return t('files.errors.empty');
    }
    if (err instanceof ApiRequestError) {
      switch (err.error.code) {
        case 'QUOTA_EXCEEDED':
          return t('files.errors.quotaServer');
        case 'ATTACHMENT_LIMIT_REACHED':
          return t('files.errors.limit');
        case 'RATE_LIMITED':
          return t('files.errors.rateLimited');
      }
    }
    return t('files.errors.failed');
  };

  const download = async (file: AttachmentView) => {
    setError(null);
    setDownloading({ id: file.id, percent: 0 });
    try {
      await downloadAttachment(requireMasterKey(), item, file.record, (fraction) =>
        setDownloading({ id: file.id, percent: Math.round(fraction * 100) }),
      );
    } catch (err) {
      // A failed GCM check is a hard stop: nothing reaches the user's disk.
      setError(err instanceof AttachmentIntegrityError ? t('files.errors.integrity') : t('files.errors.downloadFailed'));
    } finally {
      setDownloading(null);
    }
  };

  const remove = useMutation({
    mutationFn: (id: string) => deleteAttachment(id),
    onSuccess: async (_storage, id) => {
      await refresh();
      setDeleting(null);
      setNotice({ kind: 'deleted', id });
    },
  });

  const undo = useMutation({
    mutationFn: (id: string) => restoreFromTrash('attachments', id),
    onSuccess: async () => {
      await refresh();
      setNotice({ kind: 'restored' });
    },
    onError: () => setError(t('items.undoFailed')),
  });

  const files = listQuery.data ?? [];
  const days = storageQuery.data?.undoWindowDays;

  return (
    <Card className="attachments">
      <div className="attachments__header">
        <div>
          <h2 className="attachments__title">{t('files.title')}</h2>
          <p className="muted attachments__intro">{t('files.intro')}</p>
        </div>
        <input
          ref={inputRef}
          type="file"
          hidden
          onChange={(event) => {
            const file = event.target.files?.[0];
            event.target.value = ''; // the same file can be chosen again
            if (file) {
              void startUpload(file);
            }
          }}
        />
        {!upload ? (
          <Button variant="secondary" onClick={() => inputRef.current?.click()}>
            {t('files.add')}
          </Button>
        ) : null}
      </div>

      {storageQuery.data ? <StorageMeter storage={storageQuery.data.storage} /> : null}

      {upload ? (
        <div className="attachments__upload" aria-live="polite">
          <p className="attachments__upload-text">
            {t('files.uploading', { name: isolate(upload.name) })}
            {upload.total > 0 ? <span className="muted"> · {t('files.progress', { sent: upload.sent, total: upload.total })}</span> : null}
          </p>
          <progress
            className="attachments__progress"
            aria-label={t('files.progressLabel', { name: isolate(upload.name) })}
            max={Math.max(upload.total, 1)}
            value={upload.sent}
          />
          <Button variant="ghost" onClick={() => upload.controller.abort()}>
            {t('files.cancel')}
          </Button>
        </div>
      ) : null}

      {error ? <Alert tone="error">{error}</Alert> : null}
      {notice ? (
        <Alert tone="success">
          <span>
            {notice.kind === 'uploaded'
              ? t('files.uploaded', { name: isolate(notice.name) })
              : notice.kind === 'deleted'
                ? t('files.deleted')
                : notice.kind === 'restored'
                  ? t('files.restored')
                  : t('files.cancelled')}
          </span>
          {notice.kind === 'deleted' ? (
            <>
              {' '}
              <Button variant="ghost" busy={undo.isPending} onClick={() => undo.mutate(notice.id)}>
                {t('items.undo')}
              </Button>
            </>
          ) : null}
        </Alert>
      ) : null}
      {listQuery.isError ? <Alert tone="error">{t('files.loadError')}</Alert> : null}

      {listQuery.isSuccess && files.length === 0 && !upload ? <p className="muted">{t('files.empty')}</p> : null}

      {files.length > 0 ? (
        <ul className="attachments__list" aria-label={t('files.title')}>
          {files.map((file) => (
            <li key={file.id} className="attachment-row">
              <div className="attachment-row__main">
                {file.filename !== null ? (
                  <p className="attachment-row__name" dir="auto">
                    {file.filename}
                  </p>
                ) : (
                  <>
                    <p className="attachment-row__name">{t('files.unreadable')}</p>
                    <Alert tone="warning">{t('files.unreadableBody')}</Alert>
                  </>
                )}
                <p className="muted attachment-row__meta">
                  {formatBytes(file.plainBytes, i18n.language)} · {t('files.added', { date: isolate(formatDate(file.createdAt, i18n.language)) })}
                </p>
              </div>
              <div className="attachment-row__actions">
                {file.filename !== null ? (
                  <Button
                    variant="secondary"
                    busy={downloading?.id === file.id}
                    disabled={downloading !== null}
                    aria-label={t('files.downloadNamed', { name: isolate(file.filename) })}
                    onClick={() => void download(file)}
                  >
                    {downloading?.id === file.id ? t('files.downloading', { percent: downloading.percent }) : t('files.download')}
                  </Button>
                ) : null}
                <Button
                  variant="ghost"
                  aria-label={file.filename !== null ? t('files.deleteNamed', { name: isolate(file.filename) }) : t('files.delete')}
                  onClick={() => {
                    setNotice(null);
                    setDeleting(file);
                  }}
                >
                  {t('files.delete')}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}

      <Dialog open={deleting !== null} title={t('files.deleteDialog.title')} onClose={() => setDeleting(null)}>
        <div className="stack">
          <p>
            {deleting?.filename
              ? t('files.deleteDialog.body', { name: isolate(deleting.filename), recipient: isolate(recipientName) })
              : t('files.deleteDialog.bodyUnreadable', { recipient: isolate(recipientName) })}
          </p>
          {days !== undefined ? <p className="muted">{t('files.deleteDialog.undoNote', { days })}</p> : null}
          {remove.isError ? (
            <Alert tone="error">
              {remove.error instanceof ApiRequestError ? remove.error.error.message : t('common:errors.generic')}
            </Alert>
          ) : null}
          <div className="actions-row">
            <Button busy={remove.isPending} onClick={() => deleting && remove.mutate(deleting.id)}>
              {t('files.deleteDialog.submit')}
            </Button>
            <Button variant="ghost" onClick={() => setDeleting(null)} disabled={remove.isPending} autoFocus>
              {t('items.cancel')}
            </Button>
          </div>
        </div>
      </Dialog>
    </Card>
  );
}
