import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useTranslation } from 'react-i18next';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';
import { Input } from '../../components/Input';
import { isSafeLinkUrl, LINK_REL, LINK_TARGET } from './sanitizeHtml';
import './RichTextEditor.css';

interface RichTextEditorProps {
  /** Already-sanitized HTML to start from. Read once, when the editor is created. */
  initialHtml: string;
  /** id of the visible label element — a contenteditable can't be the target of <label for>. */
  labelId: string;
  /** ids of hint/error text, space-separated. */
  describedBy?: string;
  invalid?: boolean;
  onChange: (html: string) => void;
}

/**
 * The editor's schema is the first allow-list (docs/19_Decision_Log.md Decision #16): only
 * these node and mark types exist, so pasted markup is reduced to them on the way in. The
 * sanitizer is the second one, and the one that is relied on.
 */
const extensions = [
  StarterKit.configure({
    code: false,
    codeBlock: false,
    horizontalRule: false,
    heading: { levels: [2, 3] },
    link: {
      openOnClick: false, // a click inside the editor places the cursor, it never navigates
      autolink: true,
      linkOnPaste: true,
      defaultProtocol: 'https',
      protocols: ['http', 'https', 'mailto'],
      HTMLAttributes: { rel: LINK_REL, target: LINK_TARGET },
      isAllowedUri: (url) => isSafeLinkUrl(url),
    },
  }),
];

type Command = 'bold' | 'italic' | 'underline' | 'strike' | 'heading' | 'subheading' | 'bulletList' | 'numberedList' | 'quote';

interface ToolbarButton {
  key: Command | 'link' | 'unlink' | 'undo' | 'redo';
  /** Short visible label; the full name is the accessible name and tooltip. */
  glyph?: string;
  group: number;
}

const BUTTONS: ToolbarButton[] = [
  { key: 'bold', glyph: 'B', group: 0 },
  { key: 'italic', glyph: 'I', group: 0 },
  { key: 'underline', glyph: 'U', group: 0 },
  { key: 'strike', glyph: 'S', group: 0 },
  { key: 'heading', glyph: 'H1', group: 1 },
  { key: 'subheading', glyph: 'H2', group: 1 },
  { key: 'bulletList', glyph: '•', group: 1 },
  { key: 'numberedList', glyph: '1.', group: 1 },
  { key: 'quote', glyph: '❝', group: 1 },
  { key: 'link', group: 2 },
  { key: 'unlink', group: 2 },
  { key: 'undo', glyph: '↶', group: 3 },
  { key: 'redo', glyph: '↷', group: 3 },
];

function runCommand(editor: Editor, key: Command | 'unlink' | 'undo' | 'redo'): void {
  const chain = editor.chain().focus();

  switch (key) {
    case 'bold':
      chain.toggleBold().run();
      break;
    case 'italic':
      chain.toggleItalic().run();
      break;
    case 'underline':
      chain.toggleUnderline().run();
      break;
    case 'strike':
      chain.toggleStrike().run();
      break;
    case 'heading':
      chain.toggleHeading({ level: 2 }).run();
      break;
    case 'subheading':
      chain.toggleHeading({ level: 3 }).run();
      break;
    case 'bulletList':
      chain.toggleBulletList().run();
      break;
    case 'numberedList':
      chain.toggleOrderedList().run();
      break;
    case 'quote':
      chain.toggleBlockquote().run();
      break;
    case 'unlink':
      chain.extendMarkRange('link').unsetLink().run();
      break;
    case 'undo':
      chain.undo().run();
      break;
    case 'redo':
      chain.redo().run();
      break;
  }
}

/** Accept what people actually type: "example.com" → https://, "a@b.com" → mailto:. */
function normalizeLinkInput(value: string): string {
  const trimmed = value.trim();
  if (trimmed === '' || /^[a-z][a-z0-9+.-]*:/i.test(trimmed)) {
    return trimmed;
  }
  if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(trimmed)) {
    return `mailto:${trimmed}`;
  }
  return `https://${trimmed.replace(/^\/+/, '')}`;
}

/**
 * Rich-text editor for message bodies (VAULT-6 text + links). Keyboard: the toolbar is a
 * single tab stop with arrow-key navigation (WAI-ARIA toolbar pattern), the usual
 * shortcuts (Ctrl/⌘+B, I, U, Z…) work in the text, and every control has a visible focus
 * ring. Direction is resolved per paragraph (dir="auto"), so Arabic and English can be
 * mixed in one message (docs/03_UI_UX_Guidelines.md §5–6).
 */
export function RichTextEditor({ initialHtml, labelId, describedBy, invalid = false, onChange }: RichTextEditorProps) {
  const { t } = useTranslation('vault');
  const [linkOpen, setLinkOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const buttonRefs = useRef<Array<HTMLButtonElement | null>>([]);
  const onChangeRef = useRef(onChange);

  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  const editor = useEditor({
    extensions,
    content: initialHtml,
    // The stylesheet carries ProseMirror's base rules; no <style> is injected at runtime,
    // so the CSP's style-src 'self' holds (docs/05_Security_Architecture.md §6, §9).
    injectCSS: false,
    textDirection: 'auto',
    editorProps: {
      attributes: {
        class: 'rich-text__content',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-labelledby': labelId,
      },
    },
    onUpdate: ({ editor: current }) => onChangeRef.current(current.getHTML()),
  });

  // Keep the accessible description and validity in step with the form.
  useEffect(() => {
    editor.setOptions({
      editorProps: {
        attributes: {
          class: 'rich-text__content',
          role: 'textbox',
          'aria-multiline': 'true',
          'aria-labelledby': labelId,
          ...(describedBy ? { 'aria-describedby': describedBy } : {}),
          ...(invalid ? { 'aria-invalid': 'true' } : {}),
        },
      },
    });
  }, [editor, labelId, describedBy, invalid]);

  const state = useEditorState({
    editor,
    selector: ({ editor: current }) => ({
      bold: current.isActive('bold'),
      italic: current.isActive('italic'),
      underline: current.isActive('underline'),
      strike: current.isActive('strike'),
      heading: current.isActive('heading', { level: 2 }),
      subheading: current.isActive('heading', { level: 3 }),
      bulletList: current.isActive('bulletList'),
      numberedList: current.isActive('orderedList'),
      quote: current.isActive('blockquote'),
      link: current.isActive('link'),
      canUndo: current.can().undo(),
      canRedo: current.can().redo(),
      currentHref: (current.getAttributes('link').href as string | undefined) ?? '',
    }),
  });

  const isDisabled = (key: ToolbarButton['key']) =>
    (key === 'unlink' && !state.link) || (key === 'undo' && !state.canUndo) || (key === 'redo' && !state.canRedo);

  const isPressed = (key: ToolbarButton['key']): boolean | undefined =>
    key === 'unlink' || key === 'undo' || key === 'redo' ? undefined : state[key];

  const moveFocus = (index: number) => {
    setActiveIndex(index);
    buttonRefs.current[index]?.focus();
  };

  const onToolbarKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    // Arrow keys follow the visual order, which is mirrored in RTL.
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const step = event.key === 'ArrowRight' ? (rtl ? -1 : 1) : event.key === 'ArrowLeft' ? (rtl ? 1 : -1) : 0;

    if (step !== 0) {
      event.preventDefault();
      moveFocus((activeIndex + step + BUTTONS.length) % BUTTONS.length);
    } else if (event.key === 'Home') {
      event.preventDefault();
      moveFocus(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      moveFocus(BUTTONS.length - 1);
    }
  };

  const applyLink = (href: string) => {
    const { empty } = editor.state.selection;

    if (empty && !editor.isActive('link')) {
      // Nothing selected: insert the address itself as the link text.
      editor
        .chain()
        .insertContent({ type: 'text', text: href.replace(/^mailto:/i, ''), marks: [{ type: 'link', attrs: { href } }] })
        .run();
    } else {
      editor.chain().extendMarkRange('link').setLink({ href }).run();
    }
  };

  return (
    <div className={`rich-text${invalid ? ' rich-text--invalid' : ''}`}>
      <div
        className="rich-text__toolbar"
        role="toolbar"
        aria-label={t('editor.toolbar')}
        aria-orientation="horizontal"
        onKeyDown={onToolbarKeyDown}
      >
        {BUTTONS.map((button, index) => {
          const label = t(`editor.${button.key}`);
          const startsGroup = index > 0 && BUTTONS[index - 1].group !== button.group;

          return (
            <button
              key={button.key}
              ref={(element) => {
                buttonRefs.current[index] = element;
              }}
              type="button"
              className={`rich-text__button rich-text__button--${button.key}${startsGroup ? ' rich-text__button--group-start' : ''}`}
              tabIndex={index === activeIndex ? 0 : -1}
              aria-label={button.glyph ? label : undefined}
              aria-pressed={isPressed(button.key)}
              // aria-disabled rather than disabled: the button keeps its place in the arrow-key order.
              aria-disabled={isDisabled(button.key) || undefined}
              title={label}
              onFocus={() => setActiveIndex(index)}
              onClick={() => {
                if (isDisabled(button.key)) {
                  return;
                }
                if (button.key === 'link') {
                  setLinkOpen(true);
                } else {
                  runCommand(editor, button.key);
                }
              }}
            >
              {button.glyph ? <span aria-hidden="true">{button.glyph}</span> : label}
            </button>
          );
        })}
      </div>

      <EditorContent editor={editor} className="rich-text__surface" />

      <Dialog open={linkOpen} title={t('editor.linkDialog.title')} onClose={() => setLinkOpen(false)}>
        <LinkForm
          initial={state.currentHref}
          onSubmit={(href) => {
            applyLink(href);
            setLinkOpen(false);
          }}
          onCancel={() => setLinkOpen(false)}
        />
      </Dialog>
    </div>
  );
}

interface LinkFormProps {
  initial: string;
  onSubmit: (href: string) => void;
  onCancel: () => void;
}

function LinkForm({ initial, onSubmit, onCancel }: LinkFormProps) {
  const { t } = useTranslation('vault');
  const [value, setValue] = useState(initial);
  const [error, setError] = useState<string | undefined>();

  return (
    <form
      className="stack"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        event.stopPropagation(); // the dialog sits inside the message form — don't submit that too
        const href = normalizeLinkInput(value);

        if (!isSafeLinkUrl(href)) {
          setError(t('editor.linkDialog.invalid'));
          return;
        }
        onSubmit(href);
      }}
    >
      <Input
        label={t('editor.linkDialog.url')}
        hint={t('editor.linkDialog.hint')}
        error={error}
        type="url"
        inputMode="url"
        dir="ltr"
        autoComplete="off"
        autoFocus
        required
        value={value}
        onChange={(event) => setValue(event.target.value)}
      />
      <div className="actions-row">
        <Button type="submit">{t('editor.linkDialog.save')}</Button>
        <Button variant="ghost" onClick={onCancel}>
          {t('editor.linkDialog.cancel')}
        </Button>
      </div>
    </form>
  );
}
