import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { DeleteRecipientDialog } from './DeleteRecipientDialog';
import { RecipientForm } from './RecipientForm';
import type { RecipientInput, RecipientView } from './vaultApi';
import './RecipientCard.css';

interface RecipientCardProps {
  recipient: RecipientView;
  onSave: (input: RecipientInput) => Promise<void>;
  onDelete: (password: string) => Promise<void>;
}

/** One recipient, with inline edit and a guarded delete. */
export function RecipientCard({ recipient, onSave, onDelete }: RecipientCardProps) {
  const { t } = useTranslation('vault');
  const [editing, setEditing] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const integrityOk = recipient.name !== null && recipient.relationship !== null;

  if (editing) {
    return (
      <Card>
        <RecipientForm
          title={t('form.editTitle')}
          initial={{
            name: recipient.name ?? '',
            relationship: recipient.relationship ?? '',
            email: recipient.email,
            phone: recipient.phone,
            preferredMethods: recipient.preferredMethods,
          }}
          onSubmit={async (input) => {
            await onSave(input);
            setEditing(false);
          }}
          onCancel={() => setEditing(false)}
        />
      </Card>
    );
  }

  return (
    <Card className="recipient-card">
      <div className="recipient-card__header">
        <div>
          <h3 className="recipient-card__name">
            <bdi>{recipient.name ?? '—'}</bdi>
          </h3>
          {recipient.relationship ? (
            <p className="muted recipient-card__relationship">
              <bdi>{recipient.relationship}</bdi>
            </p>
          ) : null}
        </div>
        {recipient.isEmergencyContact ? <span className="recipient-card__badge">{t('recipients.emergencyBadge')}</span> : null}
      </div>

      {!integrityOk ? <Alert tone="warning">{t('recipients.integrityError')}</Alert> : null}

      <dl className="recipient-card__details">
        {recipient.email ? (
          <div>
            <dt>{t('form.email')}</dt>
            <dd dir="ltr">{recipient.email}</dd>
          </div>
        ) : null}
        {recipient.phone ? (
          <div>
            <dt>{t('form.phone')}</dt>
            <dd dir="ltr">{recipient.phone}</dd>
          </div>
        ) : null}
        <div>
          <dt>{t('recipients.delivery')}</dt>
          <dd>{recipient.preferredMethods.map((method) => t(`methods.${method}`)).join(' · ')}</dd>
        </div>
      </dl>

      <div className="actions-row">
        <Button variant="secondary" onClick={() => setEditing(true)}>
          {t('recipients.edit')}
        </Button>
        <Button variant="ghost" onClick={() => setDeleting(true)}>
          {t('recipients.delete')}
        </Button>
      </div>

      <DeleteRecipientDialog
        open={deleting}
        name={recipient.name}
        onConfirm={onDelete}
        onClose={() => setDeleting(false)}
      />
    </Card>
  );
}
