import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useLocation, useParams } from 'react-router-dom';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { ButtonLink } from '../../components/ButtonLink';
import { Card } from '../../components/Card';
import { ITEM_LIMITS } from '../../crypto/constants';
import { isolate } from './bidi';
import { DeleteItemDialog } from './DeleteItemDialog';
import { deleteMessage, fetchItems, reorderMessages, type ItemSummaryView } from './itemsApi';
import { VaultUnlockGate } from './VaultUnlockGate';
import { requireMasterKey, useRecipients, useUserId, vaultKeys } from './vaultQueries';
import './RecipientItemsPage.css';

/** Messages addressed to one recipient — Phase 2b (VAULT-5, VAULT-9). */
export function RecipientItemsPage() {
  return (
    <VaultUnlockGate>
      <RecipientItemsContent />
    </VaultUnlockGate>
  );
}

export type NoticeKey = 'items.created' | 'items.updated' | 'items.deleted';

type MoveFocus = { id: string; direction: 'up' | 'down' } | null;

function RecipientItemsContent() {
  const { t, i18n } = useTranslation('vault');
  const params = useParams();
  const location = useLocation();
  const queryClient = useQueryClient();
  const recipientId = Number(params.recipientId);
  const userId = useUserId();

  const recipientsQuery = useRecipients(userId);
  const recipient = recipientsQuery.data?.find((r) => r.id === recipientId);

  const itemsKey = vaultKeys.items(userId, recipientId);
  const itemsQuery = useQuery({
    queryKey: itemsKey,
    queryFn: () => fetchItems(requireMasterKey(), userId!, recipientId),
    enabled: userId !== undefined && recipient !== undefined,
  });

  // An i18n key, not translated text, so the notice follows a language switch. It may be
  // handed over by the editor after a save ("Message saved.").
  const [notice, setNotice] = useState<NoticeKey | null>(
    (location.state as { notice?: NoticeKey } | null)?.notice ?? null,
  );
  const [error, setError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<ItemSummaryView | null>(null);

  // After a move, keyboard focus follows the item to its new place.
  const [moveFocus, setMoveFocus] = useState<MoveFocus>(null);
  const listRef = useRef<HTMLOListElement>(null);

  const items = itemsQuery.data ?? [];

  useEffect(() => {
    if (!moveFocus) {
      return;
    }
    const row = listRef.current?.querySelector<HTMLElement>(`[data-item-id="${moveFocus.id}"]`);
    const preferred = row?.querySelector<HTMLButtonElement>(`[data-move="${moveFocus.direction}"]:not(:disabled)`);
    (preferred ?? row?.querySelector<HTMLButtonElement>('[data-move]:not(:disabled)'))?.focus();
  }, [moveFocus, itemsQuery.dataUpdatedAt]);

  const remove = useMutation({
    mutationFn: (id: string) => deleteMessage(id),
    onSuccess: async (_data, id) => {
      queryClient.removeQueries({ queryKey: vaultKeys.item(userId, id) });
      await queryClient.invalidateQueries({ queryKey: itemsKey });
      // Announce it only once the list no longer shows the item.
      setDeleting(null);
      setError(null);
      setNotice('items.deleted');
    },
  });

  const reorder = useMutation({
    mutationFn: (order: string[]) => reorderMessages(recipientId, order),
    onError: () => setError(t('items.reorderError')),
    // Success or failure, the server's order is the truth.
    onSettled: () => queryClient.invalidateQueries({ queryKey: itemsKey }),
  });

  const move = (index: number, direction: 'up' | 'down') => {
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= items.length) {
      return;
    }
    const order = items.map((item) => item.id);
    [order[index], order[target]] = [order[target], order[index]];

    setNotice(null);
    setError(null);
    setMoveFocus({ id: items[index].id, direction });
    reorder.mutate(order);
  };

  if (!Number.isSafeInteger(recipientId) || (recipientsQuery.isSuccess && !recipient)) {
    return (
      <main className="page page--wide">
        <Alert tone="error">{t('items.notFound')}</Alert>
        <p className="items-page__back">
          <Link to="/recipients">{t('items.back')}</Link>
        </p>
      </main>
    );
  }

  const displayName = recipient?.name ?? recipient?.email ?? recipient?.phone ?? t('items.writingToUnknown');
  // Western digits in both locales (docs/03_UI_UX_Guidelines.md §5).
  const dateFormat = new Intl.DateTimeFormat(i18n.language === 'ar' ? 'ar-u-nu-latn' : 'en', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
  const atLimit = items.length >= ITEM_LIMITS.perRecipient;

  return (
    <main className="page page--wide">
      <p className="items-page__back">
        <Link to="/recipients">{t('items.back')}</Link>
      </p>

      <div className="items-page__header">
        <div>
          <h1>{recipient ? t('items.title', { name: isolate(displayName) }) : t('common:loading')}</h1>
          <p className="muted">{t('items.intro')}</p>
        </div>
        {recipient && !atLimit ? <ButtonLink to={`/recipients/${recipientId}/messages/new`}>{t('items.write')}</ButtonLink> : null}
      </div>

      {recipient && recipient.name === null ? <Alert tone="warning">{t('recipients.integrityError')}</Alert> : null}
      {notice ? <Alert tone="success">{t(notice)}</Alert> : null}
      {error ? <Alert tone="error">{error}</Alert> : null}
      {recipientsQuery.isError || itemsQuery.isError ? <Alert tone="error">{t('items.loadError')}</Alert> : null}
      {atLimit ? <Alert tone="info">{t('items.limitReached')}</Alert> : null}

      {itemsQuery.isSuccess && items.length === 0 ? (
        <Card className="items-page__empty">
          <h2>{t('items.emptyTitle')}</h2>
          <p className="muted">{t('items.emptyBody', { name: isolate(displayName) })}</p>
        </Card>
      ) : null}

      <ol className="items-page__list" ref={listRef} aria-label={t('items.title', { name: isolate(displayName) })}>
        {items.map((item, index) => (
          <li key={item.id} data-item-id={item.id}>
            <Card className="item-row">
              <div className="item-row__main">
                {item.title !== null ? (
                  <h2 className="item-row__title" dir="auto">
                    {item.title}
                  </h2>
                ) : (
                  <>
                    <h2 className="item-row__title">{t('items.integrityTitle')}</h2>
                    <Alert tone="warning">{t('items.integrityError')}</Alert>
                  </>
                )}
                <p className="muted item-row__meta">
                  {t('items.lastEdited', { date: isolate(dateFormat.format(new Date(item.updatedAt))) })}
                </p>
              </div>

              <div className="item-row__actions">
                {item.title !== null ? (
                  <ButtonLink variant="secondary" to={`/recipients/${recipientId}/messages/${item.id}`}>
                    {t('items.edit')}
                  </ButtonLink>
                ) : null}
                <Button
                  variant="ghost"
                  data-move="up"
                  disabled={index === 0 || reorder.isPending}
                  aria-label={item.title !== null ? t('items.moveUpNamed', { title: isolate(item.title) }) : t('items.moveUp')}
                  onClick={() => move(index, 'up')}
                >
                  <span aria-hidden="true">↑</span> {t('items.moveUp')}
                </Button>
                <Button
                  variant="ghost"
                  data-move="down"
                  disabled={index === items.length - 1 || reorder.isPending}
                  aria-label={item.title !== null ? t('items.moveDownNamed', { title: isolate(item.title) }) : t('items.moveDown')}
                  onClick={() => move(index, 'down')}
                >
                  <span aria-hidden="true">↓</span> {t('items.moveDown')}
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => {
                    setNotice(null);
                    setDeleting(item);
                  }}
                >
                  {t('items.delete')}
                </Button>
              </div>
            </Card>
          </li>
        ))}
      </ol>

      <DeleteItemDialog
        open={deleting !== null}
        title={deleting?.title ?? null}
        recipientName={displayName}
        onConfirm={() => remove.mutateAsync(deleting!.id)}
        onClose={() => setDeleting(null)}
      />
    </main>
  );
}
