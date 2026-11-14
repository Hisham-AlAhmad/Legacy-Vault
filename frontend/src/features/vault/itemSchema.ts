/**
 * Client-side message validation — the part of StoreVaultItemRequest the browser can
 * check before encrypting (docs/10_Frontend_Architecture.md §7). The server only sees
 * ciphertext, so "is there a title, is there a message" can *only* be checked here.
 * Messages are i18n keys in the `vault` namespace.
 */
import { z } from 'zod';
import { ITEM_LIMITS } from '../../crypto/constants';
import { isHtmlBlank } from './sanitizeHtml';

export const itemSchema = z.object({
  title: z
    .string()
    .min(1, 'items.errors.titleRequired')
    // Counted in code points, matching the crypto module's cap.
    .refine((value) => [...value].length <= ITEM_LIMITS.titleChars, 'items.errors.titleTooLong'),
  /** Sanitized HTML. */
  body: z.string().refine((value) => !isHtmlBlank(value), 'items.errors.bodyRequired'),
});
