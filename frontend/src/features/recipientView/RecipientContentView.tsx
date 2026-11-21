import { useTranslation } from 'react-i18next';
import { isolate } from '../vault/bidi';
import { ContentEntryView } from './ContentEntryView';
import type { DownloadFile, RecipientContent } from './types';
import './RecipientContentView.css';

interface RecipientContentViewProps {
  content: RecipientContent;
  onDownloadFile: DownloadFile;
}

/**
 * What a recipient sees — docs/13_Delivery_Engine.md §8, docs/19_Decision_Log.md Decision
 * #30. The owner's "Preview as recipient" and the Phase 3 recipient access page render
 * through this one component, so the preview can't drift from what is delivered.
 *
 * Props only: it never decrypts, fetches or reads keys, and it depends on nothing from the
 * authenticated app shell (router, query client, vault key, API client) — the import
 * boundary is pinned by boundary.test.ts.
 */
export function RecipientContentView({ content, onDownloadFile }: RecipientContentViewProps) {
  const { t } = useTranslation('recipientView');

  return (
    <section className="recipient-view" aria-labelledby="recipient-view-heading">
      <header className="recipient-view__header">
        <h1 id="recipient-view-heading" className="recipient-view__heading">
          {content.recipientName !== null ? t('heading', { name: isolate(content.recipientName) }) : t('headingNoName')}
        </h1>
        <p className="muted">{t('intro')}</p>
      </header>

      {content.entries.length === 0 ? (
        <p className="recipient-view__empty">{t('empty')}</p>
      ) : (
        <ol className="recipient-view__entries">
          {content.entries.map((entry) => (
            <li key={entry.id}>
              <ContentEntryView entry={entry} onDownloadFile={onDownloadFile} />
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
