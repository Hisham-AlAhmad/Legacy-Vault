import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import './RecoveryKeyScreen.css';

interface RecoveryKeyScreenProps {
  recoveryKey: string;
  titleKey?: 'recoveryKey.title' | 'recoveryKey.newKeyTitle';
  showOldInvalidNote?: boolean;
  onAcknowledged: () => void;
  busy?: boolean;
}

/**
 * Full-screen, undismissable Recovery Key display — docs/03_UI_UX_Guidelines.md §7:
 * monospace, copy + download-as-file, explicit acknowledgment checkbox, plain statement
 * of what losing it means. No dark patterns to rush past this screen.
 */
export function RecoveryKeyScreen({
  recoveryKey,
  titleKey = 'recoveryKey.title',
  showOldInvalidNote = false,
  onAcknowledged,
  busy = false,
}: RecoveryKeyScreenProps) {
  const { t } = useTranslation('auth');
  const { t: tCommon } = useTranslation('common');
  const [saved, setSaved] = useState(false);
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    await navigator.clipboard.writeText(recoveryKey);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const download = () => {
    const blob = new Blob([`Legacy Vault Recovery Key\n\n${recoveryKey}\n`], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'legacy-vault-recovery-key.txt';
    anchor.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="recovery-screen">
      <div className="recovery-screen__panel stack">
        <h1>{t(titleKey)}</h1>
        {showOldInvalidNote ? <Alert tone="warning">{t('recoveryKey.oldInvalid')}</Alert> : null}
        <p className="muted">{t('recoveryKey.explain')}</p>

        <output className="recovery-screen__key mono" aria-label={t('recoveryKey.title')}>
          {recoveryKey}
        </output>

        <div className="recovery-screen__actions">
          <Button variant="secondary" onClick={copy}>
            {copied ? tCommon('actions.copied') : tCommon('actions.copy')}
          </Button>
          <Button variant="secondary" onClick={download}>
            {tCommon('actions.download')}
          </Button>
        </div>

        <Alert tone="warning">{t('recoveryKey.lossWarning')}</Alert>

        <label className="recovery-screen__ack">
          <input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} />
          <span>{t('recoveryKey.ackLabel')}</span>
        </label>

        <Button onClick={onAcknowledged} disabled={!saved} busy={busy}>
          {t('recoveryKey.finish')}
        </Button>
      </div>
    </div>
  );
}
