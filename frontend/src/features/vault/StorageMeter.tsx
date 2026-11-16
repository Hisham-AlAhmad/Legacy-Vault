import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import type { StorageUsage } from '../../lib/api/types';
import { formatBytes } from './formatBytes';
import './StorageMeter.css';

interface StorageMeterProps {
  storage: StorageUsage;
}

/**
 * How much of the attachment quota is used — numbers from the server only, never a
 * client-side constant (docs/19_Decision_Log.md Decision #19). A native <meter>, so
 * assistive technology gets the value without extra ARIA, styled from CSS (AI_RULES.md #17).
 */
export function StorageMeter({ storage }: StorageMeterProps) {
  const { t, i18n } = useTranslation('vault');
  const labelId = useId();
  const used = formatBytes(storage.usedBytes, i18n.language);
  const limit = formatBytes(storage.limitBytes, i18n.language);

  return (
    <div className="storage-meter">
      <div className="storage-meter__header">
        <span id={labelId} className="storage-meter__label">
          {t('storage.label')}
        </span>
        <span className="storage-meter__value">{t('storage.used', { used, limit })}</span>
      </div>
      <meter
        className="storage-meter__bar"
        aria-labelledby={labelId}
        aria-valuetext={t('storage.used', { used, limit })}
        min={0}
        max={storage.limitBytes}
        value={Math.min(storage.usedBytes, storage.limitBytes)}
        low={storage.limitBytes * 0.8}
        high={storage.limitBytes * 0.95}
        optimum={0}
      />
      {storage.trashedBytes > 0 ? (
        <p className="muted storage-meter__note">{t('storage.trashed', { size: formatBytes(storage.trashedBytes, i18n.language) })}</p>
      ) : null}
    </div>
  );
}
