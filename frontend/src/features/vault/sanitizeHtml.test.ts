// @vitest-environment jsdom
/**
 * The vault rich-text allow-list — docs/05_Security_Architecture.md §9,
 * docs/19_Decision_Log.md Decision #16. Runs against a real DOM (jsdom) and the real
 * DOMPurify; nothing is mocked.
 */
import { describe, expect, it } from 'vitest';
import { isHtmlBlank, isSafeLinkUrl, LINK_REL, sanitizeHtml } from './sanitizeHtml';

const ALLOWED_TAGS = new Set(['P', 'BR', 'STRONG', 'EM', 'U', 'S', 'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A']);
const BLOCK_TAGS = new Set(['P', 'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE']);

/** Parse sanitizer output and return every element in it. */
function elementsOf(html: string): Element[] {
  const template = document.createElement('template');
  template.innerHTML = html;
  return Array.from(template.content.querySelectorAll('*'));
}

/**
 * The invariant every output must satisfy, whatever went in: only allow-listed tags, only
 * the attributes the sanitizer itself controls, only http(s)/mailto link targets.
 */
function expectClean(html: string): void {
  for (const el of elementsOf(html)) {
    expect(ALLOWED_TAGS, `unexpected <${el.tagName.toLowerCase()}> in: ${html}`).toContain(el.tagName);

    const allowedAttrs = el.tagName === 'A' ? ['href', 'rel', 'target'] : BLOCK_TAGS.has(el.tagName) ? ['dir'] : [];
    for (const attr of Array.from(el.attributes)) {
      expect(allowedAttrs, `unexpected ${attr.name}="${attr.value}" in: ${html}`).toContain(attr.name);
    }

    if (el.tagName === 'A') {
      expect(el.getAttribute('href')).toMatch(/^(https?:\/\/|mailto:)/i);
      expect(el.getAttribute('rel')).toBe(LINK_REL);
      expect(el.getAttribute('target')).toBe('_blank');
    }
    if (BLOCK_TAGS.has(el.tagName)) {
      expect(el.getAttribute('dir')).toBe('auto');
    }
  }
}

describe('sanitizeHtml — what is removed', () => {
  it('strips script elements together with their content', () => {
    const out = sanitizeHtml('<p>Hello</p><script>alert(document.cookie)</script><p>World</p>');

    expect(out).toBe('<p dir="auto">Hello</p><p dir="auto">World</p>');
    expect(out).not.toContain('alert');
  });

  it('strips event handlers from allowed and disallowed elements alike', () => {
    const out = sanitizeHtml(
      '<p onclick="steal()" onmouseover="steal()">Text</p>' +
        '<img src="x" onerror="steal()">' +
        '<a href="https://example.com" onclick="steal()" onfocus="steal()">link</a>' +
        '<strong onpointerdown="steal()">bold</strong>',
    );

    expectClean(out);
    expect(out).not.toMatch(/on\w+\s*=/i);
    expect(out).not.toContain('steal');
    expect(out).not.toContain('<img');
    expect(out).toContain('<strong>bold</strong>');
  });

  it.each([
    ['plain', 'javascript:alert(1)'],
    ['mixed case', 'JaVaScRiPt:alert(1)'],
    ['leading whitespace', '  javascript:alert(1)'],
    ['embedded tab', 'java\tscript:alert(1)'],
    ['embedded newline', 'java\nscript:alert(1)'],
    ['entity-encoded', '&#106;avascript:alert(1)'],
    ['hex-entity colon', 'javascript&#x3A;alert(1)'],
    ['vbscript', 'vbscript:msgbox(1)'],
    ['data URL', 'data:text/html,<script>alert(1)</script>'],
    ['relative path', '/account/delete'],
    ['protocol-relative', '//evil.example/x'],
    ['bare word', 'evil.example'],
    ['other scheme', 'ftp://example.com/file'],
  ])('removes a %s link target and unwraps the link to text', (_label, href) => {
    const out = sanitizeHtml(`<p>Before <a href="${href}">click me</a> after</p>`);

    expectClean(out);
    expect(out).toBe('<p dir="auto">Before click me after</p>');
    expect(out).not.toMatch(/script:/i);
  });

  it('strips style, class, id, data-* and aria-* attributes', () => {
    const out = sanitizeHtml(
      '<p style="position:fixed;inset:0;background:url(https://evil.example/x)" class="a" id="b" data-x="1" aria-hidden="true" title="t">Text</p>',
    );

    expect(out).toBe('<p dir="auto">Text</p>');
  });

  it('removes embedding, form and media elements', () => {
    const out = sanitizeHtml(
      '<p>Keep</p>' +
        '<iframe src="https://evil.example"></iframe>' +
        '<object data="x"></object><embed src="x">' +
        '<form action="https://evil.example"><input name="password"><button>Go</button></form>' +
        '<img src="https://evil.example/pixel.gif"><video src="x"></video><audio src="x"></audio>' +
        '<link rel="stylesheet" href="https://evil.example/x.css"><meta http-equiv="refresh" content="0;url=https://evil.example">' +
        '<style>p{display:none}</style><base href="https://evil.example/">',
    );

    expectClean(out);
    expect(elementsOf(out).map((el) => el.tagName)).toEqual(['P']);
    expect(out).not.toContain('evil.example');
  });

  it('survives namespace-confusion (mXSS) payloads', () => {
    const payloads = [
      '<svg><script>alert(1)</script></svg>',
      '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
      '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;">',
      '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
      '<p><svg><style><a id="</style><img src=x onerror=alert(1)>"></a></style></svg></p>',
      '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
      '<a href="https://ok.example" target="_self" rel="opener"><img src=x onerror=alert(1)>x</a>',
    ];

    for (const payload of payloads) {
      const out = sanitizeHtml(payload);
      expectClean(out);
      // Cleaning the output again changes nothing — it has no second interpretation.
      expect(sanitizeHtml(out)).toBe(out);
    }
  });

  it('drops disallowed formatting tags but keeps their text', () => {
    const out = sanitizeHtml('<h1>Title</h1><div><span>Some</span> <code>text</code></div><table><tr><td>cell</td></tr></table>');

    expectClean(out);
    expect(out).toBe('TitleSome textcell');
  });
});

describe('sanitizeHtml — what is kept and enforced', () => {
  it('keeps the allowed formatting', () => {
    const html =
      '<h2 dir="auto">Heading</h2><h3 dir="auto">Sub</h3>' +
      '<p dir="auto"><strong>bold</strong> <em>italic</em> <u>under</u> <s>struck</s><br>next line</p>' +
      '<ul dir="auto"><li dir="auto"><p dir="auto">one</p></li></ul>' +
      '<ol dir="auto"><li dir="auto"><p dir="auto">two</p></li></ol>' +
      '<blockquote dir="auto"><p dir="auto">quoted</p></blockquote>';

    expect(sanitizeHtml(html)).toBe(html);
  });

  it('keeps http, https and mailto links and forces rel and target', () => {
    const out = sanitizeHtml(
      '<p><a href="https://example.com/a?b=1&amp;c=2#frag">https</a> ' +
        '<a href="http://example.com">http</a> ' +
        '<a href="mailto:layla@example.com">mail</a> ' +
        '<a href="HTTPS://EXAMPLE.COM">upper</a></p>',
    );

    expectClean(out);
    const links = elementsOf(out).filter((el) => el.tagName === 'A');
    expect(links.map((a) => a.getAttribute('href'))).toEqual([
      'https://example.com/a?b=1&c=2#frag',
      'http://example.com',
      'mailto:layla@example.com',
      'HTTPS://EXAMPLE.COM',
    ]);
  });

  it('never copies rel or target from the input', () => {
    const out = sanitizeHtml('<p><a href="https://example.com" rel="opener" target="_top">x</a></p>');

    expect(out).toBe(`<p dir="auto"><a href="https://example.com" rel="${LINK_REL}" target="_blank">x</a></p>`);
  });

  it('forces dir="auto" on every block, overriding any supplied direction', () => {
    const out = sanitizeHtml('<p dir="rtl">Hello</p><p>مرحبا</p><ul dir="ltr"><li>x</li></ul><strong dir="rtl">y</strong>');

    expect(out).toBe('<p dir="auto">Hello</p><p dir="auto">مرحبا</p><ul dir="auto"><li dir="auto">x</li></ul><strong>y</strong>');
  });

  it('preserves Arabic and mixed-direction text exactly', () => {
    const text = 'إلى ليلى — my dearest, اقرئي هذا أولاً (read this first) 123';
    const out = sanitizeHtml(`<p>${text}</p>`);

    expect(elementsOf(out)[0].textContent).toBe(text);
  });

  it('escapes text that merely looks like markup', () => {
    const out = sanitizeHtml('<p>1 &lt; 2 &amp;&amp; &lt;script&gt;alert(1)&lt;/script&gt;</p>');

    expectClean(out);
    expect(elementsOf(out)).toHaveLength(1);
    expect(elementsOf(out)[0].textContent).toBe('1 < 2 && <script>alert(1)</script>');
  });

  it('is idempotent on its own output', () => {
    const once = sanitizeHtml(
      '<h2>Hi</h2><p onclick="x()">A <a href="https://example.com" target="_self">link</a> and <a href="javascript:x()">bad</a></p>',
    );

    expect(sanitizeHtml(once)).toBe(once);
  });

  it('returns an empty string for empty or script-only input', () => {
    expect(sanitizeHtml('')).toBe('');
    expect(sanitizeHtml('<script>alert(1)</script>')).toBe('');
  });
});

describe('helpers', () => {
  it('isSafeLinkUrl accepts only absolute http(s) and mailto URLs', () => {
    for (const ok of ['https://example.com', 'http://example.com/x', 'mailto:a@example.com', ' https://example.com ']) {
      expect(isSafeLinkUrl(ok)).toBe(true);
    }
    for (const bad of ['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,x', '/relative', '//evil.example', 'example.com', 'https://', '']) {
      expect(isSafeLinkUrl(bad)).toBe(false);
    }
  });

  it('isHtmlBlank detects content with no visible text', () => {
    expect(isHtmlBlank('')).toBe(true);
    expect(isHtmlBlank('<p dir="auto"></p>')).toBe(true);
    expect(isHtmlBlank('<p dir="auto">  </p><p dir="auto"><br></p>')).toBe(true);
    expect(isHtmlBlank('<p dir="auto">x</p>')).toBe(false);
    expect(isHtmlBlank('<p dir="auto">ليلى</p>')).toBe(false);
  });
});
