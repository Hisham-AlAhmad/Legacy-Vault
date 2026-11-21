// @vitest-environment jsdom
/**
 * The shared recipient renderer — docs/19_Decision_Log.md Decision #30. Rendered with
 * react-dom in jsdom (no extra test library). It must sanitize whatever HTML it is given,
 * say plainly when something would not be delivered, keep names and titles
 * direction-safe, and hand files over only as downloads.
 */
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '../../i18n';
import { RecipientContentView } from './RecipientContentView';
import type { RecipientContent } from './types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const MESSAGE_ID = '0b7f3a52-6c1e-4d8a-9f2b-3c4d5e6f7a8b';
const BUNDLE_ID = '1c8e4b63-7d2f-4e9b-8a3c-4d5e6f7a8b9c';
const FILE_ID = '2d9f5c74-8e3a-4fac-9b4d-5e6f7a8b9cad';

const content = (overrides: Partial<RecipientContent> = {}): RecipientContent => ({
  recipientName: 'Layla',
  entries: [
    {
      id: MESSAGE_ID,
      kind: 'message',
      status: 'ok',
      title: 'رسالة أخيرة',
      bodyHtml: '<p>Hello <strong>Layla</strong></p>',
      files: [],
    },
    {
      id: BUNDLE_ID,
      kind: 'file_bundle',
      status: 'ok',
      title: 'Documents',
      bodyHtml: null,
      files: [{ id: FILE_ID, status: 'ok', filename: 'deed.pdf', sizeBytes: 2048 }],
    },
  ],
  ...overrides,
});

let container: HTMLDivElement;
let root: Root;

async function render(value: RecipientContent, onDownloadFile = vi.fn().mockResolvedValue(undefined)) {
  await act(async () => root.render(<RecipientContentView content={value} onDownloadFile={onDownloadFile} />));
  return onDownloadFile;
}

describe('RecipientContentView', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en');
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
  });

  afterEach(async () => {
    await act(async () => root.unmount());
    container.remove();
  });

  it('renders titles, bodies and files in order', async () => {
    await render(content());

    expect(container.querySelector('h1')!.textContent).toBe('For ⁨Layla⁩'); // name isolated (bidi)
    const titles = [...container.querySelectorAll('h2')];
    expect(titles.map((h) => h.textContent)).toEqual(['رسالة أخيرة', 'Documents']);
    expect(titles.every((h) => h.getAttribute('dir') === 'auto')).toBe(true);
    expect(container.querySelector('.sanitized-html')!.innerHTML).toBe('<p dir="auto">Hello <strong>Layla</strong></p>');
    expect(container.querySelector('.content-file__name bdi')!.textContent).toBe('deed.pdf');
  });

  it('sanitizes HTML itself, whatever the source hands it', async () => {
    await render(
      content({
        entries: [
          {
            id: MESSAGE_ID,
            kind: 'message',
            status: 'ok',
            title: 'x',
            bodyHtml:
              '<p onclick="steal()" style="color:red">Hi<script>steal()</script><img src=x onerror="steal()"></p><a href="javascript:steal()">bad</a><a href="https://example.com">good</a><iframe src="https://evil.test"></iframe>',
            files: [],
          },
        ],
      }),
    );

    const body = container.querySelector('.sanitized-html')!;
    expect(body.querySelector('script, img, iframe, [onclick], [style]')).toBeNull();
    expect(body.innerHTML).not.toContain('javascript:');
    const link = body.querySelector('a')!;
    expect(link.getAttribute('href')).toBe('https://example.com');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
    expect(link.getAttribute('target')).toBe('_blank');
  });

  it('says a damaged entry or file would not be delivered, and shows none of it', async () => {
    await render(
      content({
        entries: [
          { id: MESSAGE_ID, kind: 'message', status: 'damaged', title: null, bodyHtml: null, files: [] },
          {
            id: BUNDLE_ID,
            kind: 'file_bundle',
            status: 'ok',
            title: 'Documents',
            bodyHtml: null,
            files: [{ id: FILE_ID, status: 'damaged', filename: 'deed.pdf', sizeBytes: 10 }],
          },
        ],
      }),
    );

    const [damaged, bundle] = [...container.querySelectorAll('article')];
    expect(damaged.textContent).toContain('would not be delivered');
    expect(damaged.querySelector('.sanitized-html')).toBeNull();
    expect(bundle.textContent).toContain('This file failed its integrity check');
    expect(bundle.querySelector('button')).toBeNull(); // no download for a damaged file
  });

  it('hands a file over through onDownloadFile and reports a failure', async () => {
    const onDownload = await render(content());
    const button = container.querySelector<HTMLButtonElement>('.content-file button')!;
    expect(button.getAttribute('aria-label')).toBe('Download ⁨deed.pdf⁩');

    await act(async () => button.click());
    expect(onDownload).toHaveBeenCalledWith(BUNDLE_ID, FILE_ID, expect.any(Function));
    expect(container.querySelector('[role="alert"]')).toBeNull();

    onDownload.mockRejectedValueOnce(new Error('integrity'));
    await act(async () => button.click());
    expect(container.querySelector('[role="alert"]')!.textContent).toContain('could not be downloaded');
  });

  it('renders in Arabic with the same structure', async () => {
    await i18n.changeLanguage('ar');
    await render(content({ entries: [] }));

    expect(container.querySelector('h1')!.textContent).toBe('إلى ⁨Layla⁩');
    expect(container.textContent).toContain('لم يُترك هنا شيء بعد.');
  });

  it('greets without a name when none is available', async () => {
    await render(content({ recipientName: null }));
    expect(container.querySelector('h1')!.textContent).toBe('Left for you');
  });
});
