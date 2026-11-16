import { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Input } from '../../components/Input';
import { ITEM_LIMITS } from '../../crypto/constants';
import { ItemIntegrityError, ItemTooLargeError } from '../../crypto/items';
import { ApiRequestError } from '../../lib/api/client';
import { AttachmentList } from './AttachmentList';
import { createFileBundle, fetchBundle, renameFileBundle } from './itemsApi';
import { VaultUnlockGate } from './VaultUnlockGate';
import { requireMasterKey, useRecipients, useUserId, vaultKeys } from './vaultQueries';
import { WritingToBanner } from './WritingToBanner';
import './BundlePage.css';

/**
 * A file collection for one recipient — a titled `file_bundle` item that holds files and no
 * message text (docs/19_Decision_Log.md Decision #27). Kept apart from the message editor so
 * it never loads the rich-text editor. Locked behind the vault like every page that decrypts.
 */
export function BundlePage() {
  return (
    <VaultUnlockGate>
      <BundleContent />
    </VaultUnlockGate>
  );
}

function BundleContent() {
  const { t } = useTranslation('vault');
  const params = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const recipientId = Number(params.recipientId);
  const itemId = params.itemId; // undefined = new collection
  const userId = useUserId();
  const listPath = `/recipients/${recipientId}/messages`;
  const bannerId = useId();

  const recipientsQuery = useRecipients(userId);
  const recipient = recipientsQuery.data?.find((r) => r.id === recipientId);

  const bundleQuery = useQuery({
    queryKey: vaultKeys.item(userId, itemId ?? ''),
    queryFn: () => fetchBundle(requireMasterKey(), userId!, recipientId, itemId!),
    enabled: userId !== undefined && recipient !== undefined && itemId !== undefined,
    retry: false,
  });

  const [created] = useState((location.state as { created?: boolean } | null)?.created === true);

  const notFound =
    !Number.isSafeInteger(recipientId) ||
    (recipientsQuery.isSuccess && !recipient) ||
    (bundleQuery.isError && !(bundleQuery.error instanceof ItemIntegrityError));

  if (notFound || recipientsQuery.isError) {
    return (
      <main className="page page--wide">
        <Alert tone="error">{recipientsQuery.isError ? t('items.loadError') : t('items.notFound')}</Alert>
        <p className="bundle-page__back">
          <Link to="/recipients">{t('items.back')}</Link>
        </p>
      </main>
    );
  }

  const recipientName = recipient?.name ?? recipient?.email ?? recipient?.phone ?? t('items.writingToUnknown');

  return (
    <main className="page page--wide">
      <p className="bundle-page__back">
        <Link to={listPath}>{t('items.backToMessages')}</Link>
      </p>

      {recipient ? (
        <WritingToBanner id={bannerId} name={recipient.name ?? recipient.email ?? recipient.phone} relationship={recipient.relationship} textKey="bundle.for" />
      ) : null}

      {!recipient || (itemId !== undefined && bundleQuery.isPending) ? <p className="muted">{t('common:loading')}</p> : null}

      {bundleQuery.error instanceof ItemIntegrityError ? (
        <Alert tone="error">
          <p>
            <strong>{t('items.integrityTitle')}</strong>
          </p>
          <p>{t('items.integrityError')}</p>
        </Alert>
      ) : null}

      {created && itemId ? <Alert tone="success">{t('bundle.created')}</Alert> : null}

      {recipient && (itemId === undefined || bundleQuery.isSuccess) ? (
        <TitleForm
          key={itemId ?? 'new'}
          mode={itemId === undefined ? 'new' : 'edit'}
          describedBy={bannerId}
          initialTitle={bundleQuery.data?.title ?? ''}
          onSubmit={async (title) => {
            if (itemId === undefined) {
              const newId = await createFileBundle(requireMasterKey(), userId!, recipientId, title);
              await queryClient.invalidateQueries({ queryKey: vaultKeys.items(userId, recipientId) });
              navigate(`/recipients/${recipientId}/files/${newId}`, { replace: true, state: { created: true } });
            } else {
              await renameFileBundle(requireMasterKey(), userId!, recipientId, itemId, title);
              await Promise.all([
                queryClient.invalidateQueries({ queryKey: vaultKeys.items(userId, recipientId) }),
                queryClient.invalidateQueries({ queryKey: vaultKeys.item(userId, itemId) }),
              ]);
            }
          }}
        />
      ) : null}

      {recipient && itemId !== undefined && bundleQuery.isSuccess && userId !== undefined ? (
        <AttachmentList item={{ userId, recipientId, itemId }} recipientName={recipientName} />
      ) : null}
    </main>
  );
}

interface TitleFormProps {
  mode: 'new' | 'edit';
  describedBy: string;
  initialTitle: string;
  onSubmit: (title: string) => Promise<void>;
}

function TitleForm({ mode, describedBy, initialTitle, onSubmit }: TitleFormProps) {
  const { t } = useTranslation('vault');
  const [title, setTitle] = useState(initialTitle);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const save = useMutation({ mutationFn: onSubmit });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormError(null);
    setSaved(false);

    const trimmed = title.trim();
    if (trimmed === '') {
      setError('items.errors.titleRequired');
      return;
    }
    if ([...trimmed].length > ITEM_LIMITS.titleChars) {
      setError('items.errors.titleTooLong');
      return;
    }
    setError(null);

    try {
      await save.mutateAsync(trimmed);
      setSaved(mode === 'edit');
    } catch (err) {
      if (err instanceof ItemTooLargeError) {
        setError('items.errors.titleTooLong');
      } else {
        setFormError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      }
    }
  };

  return (
    <Card>
      <form className="stack" onSubmit={submit} noValidate aria-describedby={describedBy}>
        <h1 className="bundle-page__heading">{t(mode === 'new' ? 'bundle.newTitle' : 'bundle.editTitle')}</h1>
        {formError ? <Alert tone="error">{formError}</Alert> : null}
        {saved ? <Alert tone="success">{t('bundle.renamed')}</Alert> : null}
        <Input
          label={t('bundle.fieldTitle')}
          hint={t('bundle.fieldTitleHint')}
          required
          dir="auto"
          maxLength={ITEM_LIMITS.titleChars}
          autoComplete="off"
          value={title}
          error={error ? t(error) : undefined}
          onChange={(e) => {
            setTitle(e.target.value);
            setSaved(false);
          }}
        />
        <p className="muted">{t('bundle.encryptedNote')}</p>
        <div className="actions-row">
          <Button type="submit" busy={save.isPending}>
            {t(mode === 'new' ? 'bundle.create' : 'bundle.rename')}
          </Button>
        </div>
      </form>
    </Card>
  );
}

/** Route-level lazy loading entry point. */
export const Component = BundlePage;
