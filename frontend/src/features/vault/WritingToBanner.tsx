import { useTranslation } from 'react-i18next';
import { isolate } from './bidi';
import './WritingToBanner.css';

interface WritingToBannerProps {
  id: string;
  /** Decrypted recipient name, or null if it failed its integrity check. */
  name: string | null;
  relationship: string | null;
  /** Sentence to use; defaults to "You are writing to: {{name}}". */
  textKey?: 'items.writingTo' | 'bundle.for';
}

/**
 * "You are writing to: [Name]" — docs/03_UI_UX_Guidelines.md §7. Stays pinned to the top
 * of the viewport for as long as the editor is open, because writing the right words to
 * the wrong person is the worst mistake this product allows. The name is wrapped in
 * Unicode isolates so an English name in the Arabic sentence (or the reverse) can't
 * reorder the text around it (§5).
 */
export function WritingToBanner({ id, name, relationship, textKey = 'items.writingTo' }: WritingToBannerProps) {
  const { t } = useTranslation('vault');

  return (
    <div className="writing-to" id={id}>
      <span className="writing-to__mark" aria-hidden="true">
        ✎
      </span>
      <p className="writing-to__text">
        <strong>{t(textKey, { name: isolate(name ?? t('items.writingToUnknown')) })}</strong>
        {relationship ? (
          <span className="writing-to__relationship">
            {' '}
            · <bdi>{relationship}</bdi>
          </span>
        ) : null}
      </p>
    </div>
  );
}
