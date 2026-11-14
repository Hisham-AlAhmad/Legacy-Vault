# 03 — UI/UX Guidelines

## 1. Design philosophy

Dark, elegant, restrained. This product deals with mortality — the tone is closer to a
well-designed estate-planning firm than a consumer app. Confidence and calm, not gimmick.
Skull motifs, if used at all, are subtle line-art accents (e.g. a small mark in the empty-state
illustration), never a dominant visual element, never cartoonish.

Note on the CSS/markup preference: components ship with logic/markup and styling in **separate
files** (e.g. `RecipientCard.tsx` + `RecipientCard.css` or a Tailwind config/class list kept out
of inline `<style>` blocks) — never a single file mixing both. See `AI_RULES.md` #17 and
`10_Frontend_Architecture.md` §Component Structure.

## 2. Color palette

| Token | Hex (reference) | Usage |
|---|---|---|
| `--bg-base` | `#0B0B0D` | App background |
| `--bg-surface` | `#17171A` | Cards, panels |
| `--bg-surface-raised` | `#212124` | Modals, popovers |
| `--charcoal` | `#2A2A2E` | Borders, dividers |
| `--text-primary` | `#F2F1EE` | Primary text |
| `--text-secondary` | `#A6A5A2` | Secondary text |
| `--accent-deep-red` | `#8C1C1C` | Primary actions, danger, delivery-state indicators |
| `--accent-deep-red-hover` | `#A32222` | Hover state |
| `--soft-gray` | `#5C5C60` | Disabled states, subtle UI chrome |
| `--success` | `#3F7A57` | Confirmations (deliberately desaturated, not a bright green) |
| `--warning` | `#B8862F` | Grace-period / attention states |

Contrast: all text/background pairs must meet WCAG AA (4.5:1 body text, 3:1 large text).
Deep red on dark backgrounds must be checked specifically — use `--accent-deep-red-hover` for
text-on-red rather than base red where contrast is marginal.

## 3. Typography

- Primary typeface: a humanist sans (e.g. Inter or IBM Plex Sans) for both Latin and Arabic
  contexts — must pick a single family with genuinely good Arabic glyph support (e.g. IBM Plex
  Sans Arabic) rather than falling back to a system Arabic font that clashes stylistically with
  the Latin typeface.
- Type scale: 12 / 14 / 16 / 20 / 24 / 32 / 40px, 1.5 line-height for body, 1.2 for headings.
- Never set body text below 14px.

## 4. Layout & components

- 8px base spacing unit.
- Cards: `--bg-surface`, 1px `--charcoal` border, 12px radius, no drop shadows heavier than a
  subtle ambient shadow (this isn't a playful/skeuomorphic product).
- Primary buttons: `--accent-deep-red`, white text, 8px radius.
- Destructive actions (delete recipient, delete legacy item) always require a confirmation
  modal with the item's name typed or explicitly restated, never a single-click delete.
- Empty states get real illustration/copy, not a bare "No items yet."

## 5. RTL / Arabic support

RTL is not "mirror the layout and call it done." Requirements:

- Full logical-property CSS (`margin-inline-start`, not `margin-left`) so mirroring is
  automatic and correct, not hand-patched per component.
- Icons that imply direction (arrows, chevrons) must flip in RTL; icons that don't (a trash
  can, a checkmark) must not.
- Numbers and dates: Arabic locale uses Arabic-Indic or Western Arabic numerals per actual
  regional convention (Western Arabic numerals — 0123456789 — are standard in most Arabic
  digital products; do not assume Eastern Arabic numerals without a specific product decision).
- Bidirectional text (e.g. an English name inside an Arabic sentence) must render correctly —
  test this specifically, it's the most commonly broken RTL case.
- Every layout is built RTL-testable from the start: use CSS logical properties and test both
  directions before a component is considered done, not RTL as a follow-up pass.

## 6. Accessibility

- Semantic HTML first; ARIA only to fill gaps semantic HTML can't cover.
- All interactive elements keyboard-reachable in a sensible tab order; visible focus ring
  (not `outline: none` without a replacement).
- Color is never the only signal (grace-period state uses color + icon + text label).
- Forms: every input has a associated `<label>`, error messages are programmatically
  associated (`aria-describedby`), not just visually adjacent.
- Modals trap focus and restore it to the trigger element on close.
- Minimum touch target 44×44px on mobile.

## 7. Key screens — specific notes

- **Dashboard:** Last/next check-in is the single most prominent element above the fold —
  users should never wonder "when do I need to check in next."
  - Note on the check-in date shown to the user: never surface the internal grace-period
    countdown as a specific delivery date/time to the account owner or in any UI. This avoids
    creating a literal, dwelt-upon "countdown to my assumed death" experience, and avoids
    letting an attacker who reads a screen learn exact trigger timing. State should read
    like "Next check-in due July 20" — plain and forward-looking, not a doomsday clock.
- **Recipient content editor:** clearly scoped "You are writing to: [Name]" banner, persistent
  while editing, to prevent the single biggest possible mistake — writing the wrong content to
  the wrong recipient.
- **Preview:** rendered in a distinctly bordered "this is what they'll see" frame so it's never
  confused with the editor.
- **Recovery Key display (Flow 1 step 4):** full-screen, undismissable without explicit
  acknowledgment, monospace font, copy button, download-as-file button, and a one-line
  explanation of what happens if it's lost — no dark patterns to rush past this screen.

## 8. Voice & tone

Plain, warm, direct. No euphemism-stacking ("passed away unexpectedly" is fine; avoid
corporate-soft language that obscures what the product does). No forced cheerfulness in empty
states or onboarding — calm confidence instead of enthusiasm ("Add your first recipient" not
"Let's get this party started! 🎉").
