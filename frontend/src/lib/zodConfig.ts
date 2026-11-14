/**
 * zod normally probes `new Function()` the first time it builds a validator, to decide
 * whether it can compile a faster one. Our CSP forbids eval (docs/05_Security_Architecture.md
 * §6), so the probe is reported as a `securitypolicyviolation` even though zod recovers from
 * it. `jitless` skips the probe (docs/19_Decision_Log.md Decision #20).
 *
 * This must run before any schema is created, so it is its own module, imported first in
 * main.tsx — module bodies run in import order, and feature modules build their schemas
 * when they load.
 */
import { z } from 'zod';

z.config({ jitless: true });
