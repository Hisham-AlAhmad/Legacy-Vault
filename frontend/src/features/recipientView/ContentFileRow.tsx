import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { isolate } from '../vault/bidi';
import { formatBytes } from '../vault/formatBytes';
import type { ContentFile, DownloadFile } from './types';
import './ContentFileRow.css';

interface ContentFileRowProps {
  entryId: string;
  file: ContentFile;
  onDownloadFile: DownloadFile;
}

/**
 * One file: its name and size and a download button. Files are only ever handed over as
 * downloads, never rendered inside the page (docs/19_Decision_Log.md Decision #23).
 */
export function ContentFileRow({ entryId, file, onDownloadFile }: ContentFileRowProps) {
  const { t, i18n } = useTranslation('recipientView');
  const [progress, setProgress] = useState<number | null>(null);
  const [failed, setFailed] = useState(false);
  const name = file.filename ?? t('file.unnamed');

  const download = async () => {
    setFailed(false);
    setProgress(0);
    try {
      await onDownloadFile(entryId, file.id, setProgress);
    } catch {
      setFailed(true);
    } finally {
      setProgress(null);
    }
  };

  return (
    <li className="content-file">
      <div className="content-file__main">
        <span className="content-file__name">
          <bdi>{name}</bdi>
        </span>
        <span className="muted content-file__size">{formatBytes(file.sizeBytes, i18n.language)}</span>
      </div>

      {file.status === 'damaged' ? (
        <Alert tone="warning">{t('file.damaged')}</Alert>
      ) : (
        <div className="content-file__actions">
          <Button
            variant="secondary"
            busy={progress !== null}
            aria-label={t('file.downloadNamed', { name: isolate(name) })}
            onClick={() => void download()}
          >
            {t('file.download')}
          </Button>
          {progress !== null ? (
            <progress className="content-file__progress" max={1} value={progress} aria-label={t('file.downloading', { name: isolate(name) })} />
          ) : null}
        </div>
      )}

      {failed ? <Alert tone="error">{t('file.downloadFailed')}</Alert> : null}
    </li>
  );
}
