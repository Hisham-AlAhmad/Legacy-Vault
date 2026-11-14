import { useEffect, useId, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { ButtonLink } from '../../components/ButtonLink';
import { Card } from '../../components/Card';
import { Dialog } from '../../components/Dialog';
import { Input } from '../../components/Input';
import { ITEM_LIMITS } from '../../crypto/constants';
import { ItemIntegrityError, ItemTooLargeError } from '../../crypto/items';
import { ApiRequestError } from '../../lib/api/client';
import { fieldErrors } from './recipientSchema';
import { itemSchema } from './itemSchema';
import { createMessage, fetchItem, updateMessage, type ItemInput } from './itemsApi';
import { RichTextEditor } from './RichTextEditor';
import { sanitizeHtml } from './sanitizeHtml';
import { VaultUnlockGate } from './VaultUnlockGate';
import { requireMasterKey, useRecipients, useUserId, vaultKeys } from './vaultQueries';
import { WritingToBanner } from './WritingToBanner';
import './ItemEditorPage.css';

/**
 * Write or edit one message for one recipient — Phase 2b (VAULT-6 text/links, VAULT-9).
 * Locked behind the vault: after a page reload the password is asked for again before
 * anything is decrypted or written (docs/19_Decision_Log.md Decision #12).
 */
export function ItemEditorPage() {
  return (
    <VaultUnlockGate>
      <ItemEditorContent />
    </VaultUnlockGate>
  );
}

/** Route-level lazy loading entry point (the editor is the app's largest dependency). */
export const Component = ItemEditorPage;

function ItemEditorContent() {
  const { t } = useTranslation('vault');
  const params = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const recipientId = Number(params.recipientId);
  const itemId = params.itemId; // undefined = new message
  const userId = useUserId();
  const listPath = `/recipients/${recipientId}/messages`;

  const recipientsQuery = useRecipients(userId);
  const recipient = recipientsQuery.data?.find((r) => r.id === recipientId);

  const itemQuery = useQuery({
    queryKey: vaultKeys.item(userId, itemId ?? ''),
    queryFn: () => fetchItem(requireMasterKey(), userId!, recipientId, itemId!),
    enabled: userId !== undefined && recipient !== undefined && itemId !== undefined,
    retry: false,
    // Decrypted message text is not kept in the cache once the editor is closed.
    gcTime: 0,
    staleTime: 0,
  });

  const save = useMutation({
    mutationFn: (input: ItemInput) =>
      itemId === undefined
        ? createMessage(requireMasterKey(), userId!, recipientId, input)
        : updateMessage(requireMasterKey(), userId!, recipientId, itemId, input),
    // The list is refetched; this item's own cache entry is dropped when the editor
    // unmounts (gcTime 0) — evicting it here would unmount the form mid-save.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: vaultKeys.items(userId, recipientId) }),
  });

  const notFound =
    !Number.isSafeInteger(recipientId) ||
    (recipientsQuery.isSuccess && !recipient) ||
    (itemQuery.isError && !(itemQuery.error instanceof ItemIntegrityError));

  if (notFound || recipientsQuery.isError) {
    return (
      <main className="page page--wide">
        <Alert tone="error">{recipientsQuery.isError ? t('items.loadError') : t('items.notFound')}</Alert>
        <p className="item-editor__back">
          <Link to="/recipients">{t('items.back')}</Link>
        </p>
      </main>
    );
  }

  const loading = !recipient || (itemId !== undefined && itemQuery.isPending);

  return (
    <main className="page page--wide">
      <p className="item-editor__back">
        <Link to={listPath}>{t('items.backToMessages')}</Link>
      </p>

      {loading ? <p className="muted">{t('common:loading')}</p> : null}

      {/* A failed GCM check is a hard stop: the editor never opens on unauthenticated content. */}
      {itemQuery.error instanceof ItemIntegrityError ? (
        <Alert tone="error">
          <p>
            <strong>{t('items.integrityTitle')}</strong>
          </p>
          <p>{t('items.integrityError')}</p>
        </Alert>
      ) : null}

      {recipient && (itemId === undefined || itemQuery.isSuccess) ? (
        <ItemForm
          // A different item (or a refetched one) starts a fresh form and editor.
          key={itemId ?? 'new'}
          mode={itemId === undefined ? 'new' : 'edit'}
          recipientName={recipient.name ?? recipient.email ?? recipient.phone}
          recipientNameUnreadable={recipient.name === null}
          relationship={recipient.relationship}
          initial={itemQuery.data ? { title: itemQuery.data.title, body: itemQuery.data.body } : { title: '', body: '' }}
          cancelTo={listPath}
          onSubmit={async (input) => {
            await save.mutateAsync(input);
          }}
          onSaved={() =>
            navigate(listPath, { state: { notice: itemId === undefined ? 'items.created' : 'items.updated' } })
          }
        />
      ) : null}
    </main>
  );
}

interface ItemFormProps {
  mode: 'new' | 'edit';
  recipientName: string | null;
  recipientNameUnreadable: boolean;
  relationship: string | null;
  initial: ItemInput;
  cancelTo: string;
  onSubmit: (input: ItemInput) => Promise<void>;
  onSaved: () => void;
}

function ItemForm({
  mode,
  recipientName,
  recipientNameUnreadable,
  relationship,
  initial,
  cancelTo,
  onSubmit,
  onSaved,
}: ItemFormProps) {
  const { t } = useTranslation('vault');
  const [title, setTitle] = useState(initial.title);
  const bodyRef = useRef(initial.body);
  const [dirty, setDirty] = useState(false);
  const [saved, setSaved] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const bannerId = useId();
  const bodyLabelId = useId();
  const bodyHintId = useId();
  const bodyErrorId = useId();

  // Unsaved work: confirm before in-app navigation, and warn on reload/close.
  const blocker = useBlocker(dirty && !saved);

  useEffect(() => {
    if (!dirty || saved) {
      return;
    }
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty, saved]);

  // Leave only after the "saved" state has turned the navigation blocker off.
  useEffect(() => {
    if (saved) {
      onSaved();
    }
  }, [saved, onSaved]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const parsed = itemSchema.safeParse({ title: title.trim(), body: sanitizeHtml(bodyRef.current) });
    if (!parsed.success) {
      setErrors(fieldErrors(parsed.error));
      return;
    }

    setErrors({});
    setBusy(true);
    try {
      await onSubmit(parsed.data);
      setSaved(true);
    } catch (err) {
      if (err instanceof ItemTooLargeError || (err instanceof ApiRequestError && err.status === 413)) {
        setErrors({ [err instanceof ItemTooLargeError && err.part === 'item.title' ? 'title' : 'body']: 'items.errors.tooLarge' });
      } else {
        setFormError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      }
      setBusy(false);
    }
  };

  const message = (field: string) => (errors[field] ? t(errors[field]) : undefined);
  const bodyError = message('body');

  return (
    <>
      <WritingToBanner id={bannerId} name={recipientName} relationship={relationship} />

      {recipientNameUnreadable ? <Alert tone="warning">{t('recipients.integrityError')}</Alert> : null}

      <Card>
        <form className="stack" onSubmit={submit} noValidate aria-describedby={bannerId}>
          <h1 className="item-editor__heading">{t(mode === 'new' ? 'items.newTitle' : 'items.editTitle')}</h1>
          {formError ? <Alert tone="error">{formError}</Alert> : null}

          <Input
            label={t('items.fieldTitle')}
            hint={t('items.fieldTitleHint')}
            required
            dir="auto"
            maxLength={ITEM_LIMITS.titleChars}
            autoComplete="off"
            value={title}
            error={message('title')}
            onChange={(e) => {
              setTitle(e.target.value);
              setDirty(true);
            }}
          />

          <div className="field">
            <span className="field__label" id={bodyLabelId}>
              {t('items.fieldBody')}
            </span>
            <RichTextEditor
              initialHtml={initial.body}
              labelId={bodyLabelId}
              describedBy={bodyError ? `${bodyHintId} ${bodyErrorId}` : bodyHintId}
              invalid={bodyError !== undefined}
              onChange={(html) => {
                bodyRef.current = html;
                setDirty(true);
              }}
            />
            <p className="field__hint" id={bodyHintId}>
              {t('items.fieldBodyHint')}
            </p>
            {bodyError ? (
              <p className="field__error" id={bodyErrorId} role="alert">
                {bodyError}
              </p>
            ) : null}
          </div>

          <p className="muted">{t('items.encryptedNote')}</p>

          <div className="actions-row">
            <Button type="submit" busy={busy}>
              {t(mode === 'new' ? 'items.save' : 'items.saveChanges')}
            </Button>
            <ButtonLink variant="ghost" to={cancelTo}>
              {t('items.cancel')}
            </ButtonLink>
          </div>
        </form>
      </Card>

      <Dialog open={blocker.state === 'blocked'} title={t('items.unsavedTitle')} onClose={() => blocker.reset?.()}>
        <div className="stack">
          <p>{t('items.unsaved')}</p>
          <div className="actions-row">
            <Button onClick={() => blocker.reset?.()} autoFocus>
              {t('items.stay')}
            </Button>
            <Button variant="ghost" onClick={() => blocker.proceed?.()}>
              {t('items.leave')}
            </Button>
          </div>
        </div>
      </Dialog>
    </>
  );
}
