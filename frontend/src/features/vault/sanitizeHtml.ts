/**
 * The documented sanitizer for vault rich text — docs/05_Security_Architecture.md §9,
 * docs/19_Decision_Log.md Decision #16.
 *
 * Message bodies are user-authored HTML that will one day be rendered to a recipient, so
 * they pass through this strict allow-list twice: on the editor's output before it is
 * encrypted, and on decrypted HTML before anything else touches it. Never render stored
 * HTML that hasn't been through sanitizeHtml().
 *
 * Allowed tags:   p br strong em u s h2 h3 ul ol li blockquote a
 * Allowed attrs:  href on <a> — and nothing else is ever copied from the input.
 *                 rel/target on links and dir on blocks are *set here*, to fixed values.
 * Allowed links:  https:, http:, mailto: only. A link with any other target is unwrapped
 *                 to plain text.
 */
import createDOMPurify, { type Config } from 'dompurify';

const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a'];

/** Elements that get dir="auto": each takes its direction from its own first strong character. */
const BLOCK_TAGS = new Set(['P', 'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE']);

/** Absolute http(s) and mailto links only — no javascript:, data:, relative or protocol-relative URLs. */
const SAFE_LINK = /^(?:https?:\/\/|mailto:)\S/i;

/**
 * The opened page must not get a handle on the recipient's page (noopener), must not learn
 * the delivery URL (noreferrer), and gets no ranking credit (nofollow).
 */
export const LINK_REL = 'noopener noreferrer nofollow';
export const LINK_TARGET = '_blank';

/** Whether a URL may be used as a link target — shared by the editor's link dialog. */
export function isSafeLinkUrl(url: string): boolean {
  return SAFE_LINK.test(url.trim());
}

const CONFIG = {
  ALLOWED_TAGS,
  ALLOWED_ATTR: ['href'],
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
  ALLOW_UNKNOWN_PROTOCOLS: false,
  ALLOWED_URI_REGEXP: SAFE_LINK,
  RETURN_DOM_FRAGMENT: true,
} as const satisfies Config;

// A dedicated instance: these hooks never leak into (or get removed by) any other
// DOMPurify use in the app.
const purifier = createDOMPurify(window);

purifier.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    // Second, library-independent check of the link target.
    const href = node.getAttribute('href');
    if (href !== null && !isSafeLinkUrl(href)) {
      node.removeAttribute('href');
    }
    if (node.hasAttribute('href')) {
      node.setAttribute('rel', LINK_REL);
      node.setAttribute('target', LINK_TARGET);
    }
  }

  if (BLOCK_TAGS.has(node.tagName)) {
    node.setAttribute('dir', 'auto');
  }
});

/** Reduce untrusted HTML to the allow-list. Safe to call on its own output (idempotent). */
export function sanitizeHtml(dirty: string): string {
  const fragment = purifier.sanitize(dirty, CONFIG);

  // A link that lost its href (disallowed scheme) is just text — drop the dead <a>.
  fragment.querySelectorAll('a:not([href])').forEach((anchor) => {
    anchor.replaceWith(...Array.from(anchor.childNodes));
  });

  const container = document.createElement('div');
  container.appendChild(fragment);

  return container.innerHTML;
}

/** True when sanitized HTML contains no visible text — e.g. the editor's empty "<p></p>". */
export function isHtmlBlank(html: string): boolean {
  // <template> content is inert: nothing in it loads or runs while we read its text.
  const template = document.createElement('template');
  template.innerHTML = html;

  return (template.content.textContent ?? '').trim() === '';
}
