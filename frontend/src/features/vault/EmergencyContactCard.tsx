import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Dialog } from '../../components/Dialog';
import { Input } from '../../components/Input';
import { METADATA_LIMITS } from '../../crypto/constants';
import { ApiRequestError } from '../../lib/api/client';
import { emergencyContactSchema, fieldErrors } from './recipientSchema';
import {
  normalizePhone,
  type EmergencyContactInput,
  type EmergencyContactView,
  type RecipientView,
} from './vaultApi';
import './EmergencyContactCard.css';

interface EmergencyContactCardProps {
  contact: EmergencyContactView | null;
  recipients: RecipientView[];
  onSave: (input: EmergencyContactInput) => Promise<void>;
  onRemove: (password: string) => Promise<void>;
}

/**
 * Emergency contact — EC-1 (distinct role, may overlap with a recipient), EC-4 (change any
 * time). States plainly what happens without one (docs/11_CheckIn_Engine.md §6) without
 * surfacing any countdown (docs/03_UI_UX_Guidelines.md §7).
 */
export function EmergencyContactCard({ contact, recipients, onSave, onRemove }: EmergencyContactCardProps) {
  const { t } = useTranslation('vault');
  const [editing, setEditing] = useState(false);
  const [removing, setRemoving] = useState(false);

  return (
    <Card className="emergency-card">
      <h2>{t('emergency.title')}</h2>
      <p className="muted">{t('emergency.explain')}</p>

      {editing ? (
        <EmergencyContactForm
          initial={contact}
          recipients={recipients}
          onSubmit={async (input) => {
            await onSave(input);
            setEditing(false);
          }}
          onCancel={() => setEditing(false)}
        />
      ) : contact ? (
        <>
          <dl className="emergency-card__details">
            <dt className="visually-hidden">{t('form.name')}</dt>
            <dd className="emergency-card__name">
              <bdi>{contact.name ?? '—'}</bdi>
            </dd>
            <dt className="visually-hidden">{t('form.email')}</dt>
            <dd dir="ltr">{contact.email}</dd>
            {contact.phone ? (
              <>
                <dt className="visually-hidden">{t('form.phone')}</dt>
                <dd dir="ltr">{contact.phone}</dd>
              </>
            ) : null}
          </dl>
          {contact.name === null ? <Alert tone="warning">{t('recipients.integrityError')}</Alert> : null}
          {contact.linkedRecipientId !== null ? <p className="muted">{t('emergency.linked')}</p> : null}
          <div className="actions-row">
            <Button variant="secondary" onClick={() => setEditing(true)}>
              {t('emergency.change')}
            </Button>
            <Button variant="ghost" onClick={() => setRemoving(true)}>
              {t('emergency.remove')}
            </Button>
          </div>
        </>
      ) : (
        <>
          <Alert tone="warning">{t('emergency.none')}</Alert>
          <div className="actions-row">
            <Button onClick={() => setEditing(true)}>{t('emergency.set')}</Button>
          </div>
        </>
      )}

      <Dialog open={removing} title={t('emergency.removeTitle')} onClose={() => setRemoving(false)}>
        <RemoveEmergencyContactForm onConfirm={onRemove} onClose={() => setRemoving(false)} />
      </Dialog>
    </Card>
  );
}

interface EmergencyContactFormProps {
  initial: EmergencyContactView | null;
  recipients: RecipientView[];
  onSubmit: (input: EmergencyContactInput) => Promise<void>;
  onCancel: () => void;
}

function EmergencyContactForm({ initial, recipients, onSubmit, onCancel }: EmergencyContactFormProps) {
  const { t } = useTranslation('vault');
  const selectId = useId();
  const [recipientId, setRecipientId] = useState<number | null>(initial?.linkedRecipientId ?? null);
  const [name, setName] = useState(initial?.name ?? '');
  const [email, setEmail] = useState(initial?.email ?? '');
  const [phone, setPhone] = useState(initial?.phone ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /** Picking a recipient copies their details in; the copy is independent afterwards. */
  const pickRecipient = (value: string) => {
    const picked = recipients.find((r) => String(r.id) === value) ?? null;
    setRecipientId(picked?.id ?? null);
    if (picked) {
      setName(picked.name ?? '');
      setEmail(picked.email ?? '');
      setPhone(picked.phone ?? '');
    }
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const parsed = emergencyContactSchema.safeParse({
      name: name.trim(),
      email: email.trim(),
      phone: normalizePhone(phone),
    });

    if (!parsed.success) {
      setErrors(fieldErrors(parsed.error));
      return;
    }

    setErrors({});
    setBusy(true);
    try {
      await onSubmit({ ...parsed.data, recipientId });
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
    } finally {
      setBusy(false);
    }
  };

  const message = (field: string) => (errors[field] ? t(errors[field]) : undefined);

  return (
    <form className="stack" onSubmit={submit} noValidate>
      {formError ? <Alert tone="error">{formError}</Alert> : null}

      {recipients.length > 0 ? (
        <div className="field">
          <label className="field__label" htmlFor={selectId}>
            {t('emergency.fromRecipient')}
          </label>
          <select
            id={selectId}
            className="field__input"
            value={recipientId === null ? '' : String(recipientId)}
            onChange={(e) => pickRecipient(e.target.value)}
          >
            <option value="">{t('emergency.fromRecipientNone')}</option>
            {recipients.map((r) => (
              <option key={r.id} value={String(r.id)}>
                {r.name ?? '—'}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <Input
        label={t('form.name')}
        required
        maxLength={METADATA_LIMITS.recipientName}
        autoComplete="off"
        value={name}
        error={message('name')}
        onChange={(e) => setName(e.target.value)}
      />
      <Input
        label={t('form.email')}
        hint={t('emergency.emailHint')}
        type="email"
        dir="ltr"
        required
        autoComplete="off"
        value={email}
        error={message('email')}
        onChange={(e) => setEmail(e.target.value)}
      />
      <Input
        label={`${t('form.phone')} (${t('form.optional')})`}
        hint={t('form.phoneHint')}
        type="tel"
        dir="ltr"
        autoComplete="off"
        value={phone}
        error={message('phone')}
        onChange={(e) => setPhone(e.target.value)}
      />

      <div className="actions-row">
        <Button type="submit" busy={busy}>
          {t('emergency.save')}
        </Button>
        <Button variant="ghost" onClick={onCancel} disabled={busy}>
          {t('form.cancel')}
        </Button>
      </div>
    </form>
  );
}

function RemoveEmergencyContactForm({
  onConfirm,
  onClose,
}: {
  onConfirm: (password: string) => Promise<void>;
  onClose: () => void;
}) {
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
      onClose();
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      setBusy(false);
    }
  };

  return (
    <form className="stack" onSubmit={submit}>
      <Alert tone="warning">{t('emergency.removeBody')}</Alert>
      {error ? <Alert tone="error">{error}</Alert> : null}
      <Input
        label={t('delete.password')}
        type="password"
        autoComplete="current-password"
        required
        value={password}
        onChange={(e) => setPassword(e.target.value)}
      />
      <div className="actions-row">
        <Button type="submit" busy={busy} disabled={password === ''}>
          {t('emergency.removeSubmit')}
        </Button>
        <Button variant="ghost" onClick={onClose} disabled={busy}>
          {t('form.cancel')}
        </Button>
      </div>
    </form>
  );
}
