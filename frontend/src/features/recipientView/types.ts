/**
 * The boundary between where recipient content comes from and how it is shown —
 * docs/19_Decision_Log.md Decision #30, docs/13_Delivery_Engine.md §8.
 *
 * A content source (the owner's in-browser preview today, the Phase 3 recipient access
 * flow later) produces one `RecipientContent`; `RecipientContentView` renders it and
 * nothing else. Everything here is already opened: no ciphertext, no keys. `bodyHtml` is
 * the decrypted message body — the renderer sanitizes it again itself before rendering,
 * so a source cannot get unsanitized HTML onto the page even by mistake.
 */

/** One file, as it would be delivered. */
export interface ContentFile {
  id: string;
  /** `damaged`: the file failed its integrity check and would not be delivered. */
  status: 'ok' | 'damaged';
  /** Null when the name itself could not be opened. */
  filename: string | null;
  /** The original file size in bytes. */
  sizeBytes: number;
}

/** One message or file collection, in delivery order. */
export interface ContentEntry {
  id: string;
  kind: 'message' | 'file_bundle';
  /** `damaged`: the entry failed its integrity check and would not be delivered. */
  status: 'ok' | 'damaged';
  /** Null only when `status` is `damaged`. */
  title: string | null;
  /** Messages only, and null when damaged. Re-sanitized by the renderer. */
  bodyHtml: string | null;
  /** Completed files only; empty for a damaged entry. */
  files: ContentFile[];
}

export interface RecipientContent {
  /** The recipient's name, or null when it isn't available. Shown isolated (bidi). */
  recipientName: string | null;
  entries: ContentEntry[];
}

/**
 * Hand one file to the person as a download. Rejects when the file can't be opened —
 * nothing partial is ever saved. `onProgress` receives 0…1.
 */
export type DownloadFile = (entryId: string, fileId: string, onProgress: (fraction: number) => void) => Promise<void>;
