import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Input } from '../../components/Input';
import { METADATA_LIMITS } from '../../crypto/constants';
import { ApiRequestError } from '../../lib/api/client';
import type { DeliveryMethod } from '../../lib/api/types';
import { fieldErrors, recipientSchema } from './recipientSchema';
import { normalizePhone, type RecipientInput } from './vaultApi';
import './RecipientForm.css';

const METHODS: DeliveryMethod[] = ['email', 'sms', 'link'];

/** Server field names (camelCased by the API client) → form field names. */
const SERVER_FIELD_MAP: Record<string, string> = {
  nameEnc: 'name',
  relationshipEnc: 'relationship',
  email: 'email',
  phone: 'phone',
  preferredMethods: 'preferredMethods',
};

interface RecipientFormProps {
  title: string;
  initial?: RecipientInput;
  onSubmit: (input: RecipientInput) => Promise<void>;
  onCancel: () => void;
}

/** Add/edit recipient — VAULT-4, docs/02_User_Flows.md Flow 2 step 2. */
export function RecipientForm({ title, initial, onSubmit, onCancel }: RecipientFormProps) {
  const { t } = useTranslation('vault');
  const [name, setName] = useState(initial?.name ?? '');
  const [relationship, setRelationship] = useState(initial?.relationship ?? '');
  const [email, setEmail] = useState(initial?.email ?? '');
  const [phone, setPhone] = useState(initial?.phone ?? '');
  const [methods, setMethods] = useState<DeliveryMethod[]>(initial?.preferredMethods ?? ['email']);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const methodsHintId = useId();

  const toggleMethod = (method: DeliveryMethod, checked: boolean) =>
    setMethods((current) => (checked ? [...current, method] : current.filter((m) => m !== method)));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const parsed = recipientSchema.safeParse({
      name: name.trim(),
      relationship: relationship.trim(),
      email: email.trim() === '' ? null : email.trim(),
      phone: normalizePhone(phone),
      // Keep the canonical order so an unchanged edit doesn't look like a change.
      preferredMethods: METHODS.filter((m) => methods.includes(m)),
    });

    if (!parsed.success) {
      setErrors(fieldErrors(parsed.error));
      return;
    }

    setErrors({});
    setBusy(true);
    try {
      await onSubmit(parsed.data);
    } catch (err) {
      if (err instanceof ApiRequestError && Object.keys(err.error.fields).length > 0) {
        // Server messages are already localized; show them as-is.
        setErrors(
          Object.fromEntries(
            Object.entries(err.error.fields).map(([field, messages]) => [SERVER_FIELD_MAP[field] ?? field, messages[0]]),
          ),
        );
      } else {
        setFormError(err instanceof ApiRequestError ? err.error.message : t('common:errors.generic'));
      }
    } finally {
      setBusy(false);
    }
  };

  // Client errors are i18n keys; server errors are already-translated text.
  const message = (field: string) => {
    const value = errors[field];
    return value === undefined ? undefined : value.startsWith('form.errors.') ? t(value) : value;
  };

  const optional = (label: string) => `${label} (${t('form.optional')})`;

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <h2>{title}</h2>
      {formError ? <Alert tone="error">{formError}</Alert> : null}

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
        label={t('form.relationship')}
        hint={t('form.relationshipHint')}
        required
        maxLength={METADATA_LIMITS.relationship}
        autoComplete="off"
        value={relationship}
        error={message('relationship')}
        onChange={(e) => setRelationship(e.target.value)}
      />
      <Input
        label={optional(t('form.email'))}
        type="email"
        dir="ltr"
        autoComplete="off"
        value={email}
        error={message('email')}
        onChange={(e) => setEmail(e.target.value)}
      />
      <Input
        label={optional(t('form.phone'))}
        hint={t('form.phoneHint')}
        type="tel"
        dir="ltr"
        autoComplete="off"
        value={phone}
        error={message('phone')}
        onChange={(e) => setPhone(e.target.value)}
      />

      <fieldset className="recipient-form__methods" aria-describedby={methodsHintId}>
        <legend className="field__label">{t('form.methods')}</legend>
        <p className="field__hint" id={methodsHintId}>
          {t('form.methodsHint')}
        </p>
        {METHODS.map((method) => (
          <label key={method} className="recipient-form__method">
            <input
              type="checkbox"
              checked={methods.includes(method)}
              onChange={(e) => toggleMethod(method, e.target.checked)}
            />
            {t(`methods.${method}`)}
          </label>
        ))}
        {message('preferredMethods') ? (
          <p className="field__error" role="alert">
            {message('preferredMethods')}
          </p>
        ) : null}
      </fieldset>

      <p className="muted">{t('form.encryptedNote')}</p>

      <div className="actions-row">
        <Button type="submit" busy={busy}>
          {t('form.save')}
        </Button>
        <Button variant="ghost" onClick={onCancel} disabled={busy}>
          {t('form.cancel')}
        </Button>
      </div>
    </form>
  );
}
