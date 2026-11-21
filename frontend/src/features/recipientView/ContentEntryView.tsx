import { useTranslation } from 'react-i18next';
import { Alert } from '../../components/Alert';
import { ContentFileRow } from './ContentFileRow';
import { SanitizedHtml } from './SanitizedHtml';
import type { ContentEntry, DownloadFile } from './types';
import './ContentEntryView.css';

interface ContentEntryViewProps {
  entry: ContentEntry;
  onDownloadFile: DownloadFile;
}

/**
 * One message or file collection, exactly as delivered: title, body, files. A damaged
 * entry says so — it is never shown as empty or partial content.
 */
export function ContentEntryView({ entry, onDownloadFile }: ContentEntryViewProps) {
  const { t } = useTranslation('recipientView');
  const isMessage = entry.kind === 'message';

  if (entry.status === 'damaged') {
    return (
      <article className="content-entry content-entry--damaged" aria-labelledby={`entry-${entry.id}`}>
        <h2 className="content-entry__title" id={`entry-${entry.id}`}>
          {t(isMessage ? 'entry.damagedMessageTitle' : 'entry.damagedBundleTitle')}
        </h2>
        <Alert tone="warning">{t(isMessage ? 'entry.damagedMessage' : 'entry.damagedBundle')}</Alert>
      </article>
    );
  }

  return (
    <article className="content-entry" aria-labelledby={`entry-${entry.id}`}>
      <h2 className="content-entry__title" id={`entry-${entry.id}`} dir="auto">
        {entry.title}
      </h2>

      {isMessage && entry.bodyHtml !== null ? <SanitizedHtml className="content-entry__body" html={entry.bodyHtml} /> : null}

      {entry.files.length > 0 ? (
        <section className="content-entry__files" aria-label={t('entry.files')}>
          <h3 className="content-entry__files-title">{t('entry.files')}</h3>
          <ul className="content-entry__file-list">
            {entry.files.map((file) => (
              <ContentFileRow key={file.id} entryId={entry.id} file={file} onDownloadFile={onDownloadFile} />
            ))}
          </ul>
        </section>
      ) : !isMessage ? (
        <p className="muted">{t('entry.emptyBundle')}</p>
      ) : null}
    </article>
  );
}
