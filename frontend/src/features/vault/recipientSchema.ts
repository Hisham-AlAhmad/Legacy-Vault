/**
 * Client-side recipient validation — mirrors StoreRecipientRequest + RecipientReachability
 * on the server (docs/10_Frontend_Architecture.md §7). Fast feedback only; the server is
 * authoritative. Messages are i18n keys in the `vault` namespace.
 */
import { z } from 'zod';
import { METADATA_LIMITS } from '../../crypto/constants';

export const E164 = /^\+[1-9][0-9]{6,14}$/;

const emailField = z.string().email('form.errors.email').max(255, 'form.errors.email').nullable();
const phoneField = z.string().regex(E164, 'form.errors.phone').nullable();

export const recipientSchema = z
  .object({
    name: z.string().min(1, 'form.errors.nameRequired').max(METADATA_LIMITS.recipientName, 'form.errors.nameTooLong'),
    relationship: z
      .string()
      .min(1, 'form.errors.relationshipRequired')
      .max(METADATA_LIMITS.relationship, 'form.errors.relationshipTooLong'),
    email: emailField,
    phone: phoneField,
    preferredMethods: z.array(z.enum(['email', 'sms', 'link'])).min(1, 'form.errors.methodRequired'),
  })
  .superRefine((value, ctx) => {
    if (value.preferredMethods.includes('email') && value.email === null) {
      ctx.addIssue({ code: 'custom', path: ['email'], message: 'form.errors.emailForMethod' });
    }
    if (value.preferredMethods.includes('sms') && value.phone === null) {
      ctx.addIssue({ code: 'custom', path: ['phone'], message: 'form.errors.phoneForSms' });
    }
    if (value.email === null && value.phone === null) {
      ctx.addIssue({ code: 'custom', path: ['email'], message: 'form.errors.contactRequired' });
    }
  });

export const emergencyContactSchema = z.object({
  name: z.string().min(1, 'form.errors.nameRequired').max(METADATA_LIMITS.recipientName, 'form.errors.nameTooLong'),
  email: z.string().email('form.errors.email').max(255, 'form.errors.email'),
  phone: phoneField,
});

/** Flatten zod issues to { field: firstMessageKey }. */
export function fieldErrors(error: z.ZodError): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const issue of error.issues) {
    const field = String(issue.path[0] ?? 'form');
    errors[field] ??= issue.message;
  }
  return errors;
}
