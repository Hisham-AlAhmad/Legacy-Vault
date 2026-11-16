import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { fetchMe } from '../auth/authApi';
import { EmergencyContactCard } from './EmergencyContactCard';
import { RecipientCard } from './RecipientCard';
import { RecipientForm } from './RecipientForm';
import { VaultUnlockGate } from './VaultUnlockGate';
import { restoreFromTrash } from './trashApi';
import { requireMasterKey, useStorageInfo, vaultKeys } from './vaultQueries';
import {
  createRecipient,
  deleteRecipient,
  fetchEmergencyContact,
  fetchRecipients,
  removeEmergencyContact,
  setEmergencyContact,
  updateRecipient,
  type EmergencyContactInput,
  type RecipientInput,
} from './vaultApi';
import './RecipientsPage.css';

/** Recipients + emergency contact — Phase 2a (VAULT-4, EC-1, EC-4; Flow 2 steps 1–3). */
export function RecipientsPage() {
  return (
    <VaultUnlockGate>
      <RecipientsContent />
    </VaultUnlockGate>
  );
}

function RecipientsContent() {
  const { t } = useTranslation('vault');
  const queryClient = useQueryClient();
  const { data: user } = useQuery({ queryKey: ['me'], queryFn: fetchMe });
  const userId = user?.id;

  // Cache keys are scoped per user (docs/10_Frontend_Architecture.md §3).
  const recipientsKey = ['recipients', userId];
  const contactKey = ['emergency-contact', userId];

  const recipientsQuery = useQuery({
    queryKey: recipientsKey,
    queryFn: () => fetchRecipients(requireMasterKey(), userId!),
    enabled: userId !== undefined,
  });

  const contactQuery = useQuery({
    queryKey: contactKey,
    queryFn: () => fetchEmergencyContact(requireMasterKey(), userId!),
    enabled: userId !== undefined,
  });

  const [adding, setAdding] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  // The recipient just deleted, offered for undo in the notice.
  const [deletedId, setDeletedId] = useState<number | null>(null);
  const storageQuery = useStorageInfo(userId);

  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: recipientsKey }),
      queryClient.invalidateQueries({ queryKey: contactKey }),
    ]);
  };

  // Mutations throw back to the forms, which render field-level errors.
  const save = useMutation({
    mutationFn: ({ id, input }: { id: number | null; input: RecipientInput }) =>
      id === null
        ? createRecipient(requireMasterKey(), userId!, input)
        : updateRecipient(requireMasterKey(), userId!, id, input),
    onSuccess: async () => {
      setNotice(t('form.saved'));
      await refresh();
    },
  });

  const remove = useMutation({
    mutationFn: ({ id, password }: { id: number; password: string }) => deleteRecipient(id, password),
    onSuccess: async (_data, { id }) => {
      await refresh();
      await queryClient.invalidateQueries({ queryKey: vaultKeys.trash(userId) });
      setNotice(t('recipients.deleted'));
      setDeletedId(id);
    },
  });

  const undo = useMutation({
    mutationFn: (id: number) => restoreFromTrash('recipients', id),
    onSuccess: async () => {
      setDeletedId(null);
      await Promise.all([refresh(), queryClient.invalidateQueries({ queryKey: vaultKeys.trash(userId) })]);
      setNotice(t('recipients.restored'));
    },
    onError: () => {
      setDeletedId(null);
      setNotice(t('items.undoFailed'));
    },
  });

  const saveContact = useMutation({
    mutationFn: (input: EmergencyContactInput) => setEmergencyContact(requireMasterKey(), userId!, input),
    onSuccess: async () => {
      setNotice(t('emergency.saved'));
      await refresh();
    },
  });

  const removeContact = useMutation({
    mutationFn: (password: string) => removeEmergencyContact(password),
    onSuccess: refresh,
  });

  const recipients = recipientsQuery.data ?? [];

  return (
    <main className="page page--wide">
      <div className="recipients-page__header">
        <div>
          <h1>{t('recipients.title')}</h1>
          <p className="muted">{t('recipients.intro')}</p>
        </div>
        {!adding ? (
          <Button
            onClick={() => {
              setNotice(null);
              setDeletedId(null);
              setAdding(true);
            }}
          >
            {t('recipients.add')}
          </Button>
        ) : null}
      </div>

      {notice ? (
        <Alert tone="success">
          <span>{notice}</span>
          {deletedId !== null ? (
            <>
              {' '}
              <Button variant="ghost" busy={undo.isPending} onClick={() => undo.mutate(deletedId)}>
                {t('items.undo')}
              </Button>
            </>
          ) : null}
        </Alert>
      ) : null}

      <div className="recipients-page__layout">
        <section className="recipients-page__list" aria-label={t('recipients.title')}>
          {adding ? (
            <Card>
              <RecipientForm
                title={t('form.addTitle')}
                onSubmit={async (input) => {
                  await save.mutateAsync({ id: null, input });
                  setAdding(false);
                }}
                onCancel={() => setAdding(false)}
              />
            </Card>
          ) : null}

          {recipientsQuery.isError ? <Alert tone="error">{t('recipients.loadError')}</Alert> : null}

          {recipientsQuery.isSuccess && recipients.length === 0 && !adding ? (
            <Card className="recipients-page__empty">
              <h2>{t('recipients.emptyTitle')}</h2>
              <p className="muted">{t('recipients.emptyBody')}</p>
            </Card>
          ) : null}

          {recipients.map((recipient) => (
            <RecipientCard
              key={recipient.id}
              recipient={recipient}
              undoWindowDays={storageQuery.data?.undoWindowDays}
              onSave={(input) => save.mutateAsync({ id: recipient.id, input })}
              onDelete={(password) => remove.mutateAsync({ id: recipient.id, password })}
            />
          ))}
        </section>

        <aside className="recipients-page__aside">
          {contactQuery.isError ? <Alert tone="error">{t('emergency.loadError')}</Alert> : null}
          {contactQuery.isSuccess ? (
            <EmergencyContactCard
              contact={contactQuery.data}
              recipients={recipients}
              onSave={(input) => saveContact.mutateAsync(input)}
              onRemove={(password) => removeContact.mutateAsync(password)}
            />
          ) : null}
        </aside>
      </div>
    </main>
  );
}
