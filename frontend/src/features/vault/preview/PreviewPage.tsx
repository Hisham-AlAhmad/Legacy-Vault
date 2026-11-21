import { useId } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Alert } from '../../../components/Alert';
import { Button } from '../../../components/Button';
import { RecipientContentView } from '../../recipientView/RecipientContentView';
import type { DownloadFile } from '../../recipientView/types';
import { downloadAttachment } from '../attachmentsApi';
import { isolate } from '../bidi';
import { VaultUnlockGate } from '../VaultUnlockGate';
import { requireMasterKey, useRecipients, useUserId } from '../vaultQueries';
import { loadOwnerPreview } from './ownerContentSource';
import '../../../components/Input.css'; // .field__label / .field__input for the picker
import './PreviewPage.css';

/**
 * "Preview as recipient" — VAULT-8, docs/19_Decision_Log.md Decision #30. The owner picks a
 * recipient and sees exactly what that person will receive, rendered by the same component
 * the Phase 3 recipient page uses, from freshly fetched and decrypted stored ciphertext.
 * The banner above the renderer is the owner's only: the recipient never sees it.
 */
export function PreviewPage() {
  return (
    <VaultUnlockGate>
      <PreviewContent />
    </VaultUnlockGate>
  );
}

function PreviewContent() {
  const { t } = useTranslation('vault');
  const params = useParams();
  const navigate = useNavigate();
  const pickerId = useId();
  const recipientId = Number(params.recipientId);
  const userId = useUserId();

  const recipientsQuery = useRecipients(userId);
  const recipients = recipientsQuery.data ?? [];
  const recipient = recipients.find((r) => r.id === recipientId);

  // No plaintext cache: decrypted preview content leaves memory when the page closes, and
  // every visit decrypts what is stored right now (docs/06_Cryptography.md §16).
  const previewQuery = useQuery({
    queryKey: ['preview', userId, recipientId],
    queryFn: () => loadOwnerPreview(requireMasterKey(), userId!, recipientId, recipient!.name),
    enabled: userId !== undefined && recipient !== undefined,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  });

  const labelFor = (r: (typeof recipients)[number]) => r.name ?? r.email ?? r.phone ?? t('preview.unnamed');
  const displayName = recipient ? labelFor(recipient) : t('items.writingToUnknown');

  const onDownloadFile: DownloadFile = async (entryId, fileId, onProgress) => {
    const file = previewQuery.data?.files.get(fileId);
    if (!file || file.itemId !== entryId) {
      throw new Error('Unknown file.');
    }
    await downloadAttachment(requireMasterKey(), { userId: userId!, recipientId, itemId: entryId }, file.record, onProgress);
  };

  const notFound = !Number.isSafeInteger(recipientId) || (recipientsQuery.isSuccess && !recipient);

  return (
    <main className="page page--wide preview-page">
      <p className="preview-page__back">
        <Link to={recipient ? `/recipients/${recipientId}/messages` : '/recipients'}>
          {recipient ? t('items.backToMessages') : t('items.back')}
        </Link>
      </p>

      <div className="preview-banner" role="note" aria-labelledby={`${pickerId}-banner`}>
        <p className="preview-banner__text" id={`${pickerId}-banner`}>
          <strong>{t('preview.banner', { name: isolate(displayName) })}</strong>
        </p>
        <p className="muted preview-banner__hint">{t('preview.bannerHint')}</p>

        {recipients.length > 1 ? (
          <div className="preview-banner__picker">
            <label className="field__label" htmlFor={pickerId}>
              {t('preview.pick')}
            </label>
            <select
              id={pickerId}
              className="field__input preview-banner__select"
              value={recipient ? String(recipientId) : ''}
              onChange={(event) => navigate(`/recipients/${event.target.value}/preview`)}
            >
              {recipient ? null : <option value="">—</option>}
              {recipients.map((r) => (
                <option key={r.id} value={String(r.id)}>
                  {labelFor(r)}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        {previewQuery.isSuccess ? (
          <Button variant="secondary" busy={previewQuery.isFetching} onClick={() => void previewQuery.refetch()}>
            {t('preview.refresh')}
          </Button>
        ) : null}
      </div>

      {notFound ? <Alert tone="error">{t('items.notFound')}</Alert> : null}
      {recipientsQuery.isError || previewQuery.isError ? <Alert tone="error">{t('preview.loadError')}</Alert> : null}
      {previewQuery.isPending && !notFound && !recipientsQuery.isError ? (
        <p className="muted" role="status">
          {t('preview.loading', { name: isolate(displayName) })}
        </p>
      ) : null}
      {previewQuery.isSuccess && previewQuery.data.content.entries.some((e) => e.status === 'damaged' || e.files.some((f) => f.status === 'damaged')) ? (
        <Alert tone="warning">{t('preview.damagedSummary')}</Alert>
      ) : null}

      {previewQuery.isSuccess ? (
        <div className="preview-page__frame">
          <RecipientContentView content={previewQuery.data.content} onDownloadFile={onDownloadFile} />
        </div>
      ) : null}
    </main>
  );
}

/** Lazy route module export (react-router `lazy`). */
export const Component = PreviewPage;
