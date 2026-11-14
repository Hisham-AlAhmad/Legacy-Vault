import { useEffect, useId, useRef, type ReactNode } from 'react';
import './Dialog.css';

interface DialogProps {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}

/**
 * Modal dialog on the native <dialog> element: showModal() makes the rest of the page
 * inert (focus trap) and Escape closes it; focus returns to the element that opened it
 * (docs/03_UI_UX_Guidelines.md §6). Content mounts only while open, so forms start fresh.
 */
export function Dialog({ open, title, onClose, children }: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const returnFocusTo = useRef<HTMLElement | null>(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) {
      return;
    }

    if (open && !dialog.open) {
      returnFocusTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  }, [open]);

  return (
    <dialog
      ref={ref}
      className="dialog"
      aria-labelledby={titleId}
      onClose={() => {
        onClose();
        returnFocusTo.current?.focus();
      }}
    >
      {open ? (
        <div className="dialog__panel">
          <h2 id={titleId} className="dialog__title">
            {title}
          </h2>
          {children}
        </div>
      ) : null}
    </dialog>
  );
}
