/**
 * API client — the single fetch boundary (docs/16_Coding_Standards.md §2: no direct fetch
 * in components). Sanctum cookie sessions + CSRF double-submit
 * (docs/05_Security_Architecture.md §4–5); snake_case ⇄ camelCase mapping happens here,
 * not scattered through features (docs/16_Coding_Standards.md §4).
 */
import type { ApiError } from './types';

const API_BASE = '/api/v1';

export class ApiRequestError extends Error {
  readonly status: number;
  readonly error: ApiError;

  constructor(status: number, error: ApiError) {
    super(error.message);
    this.status = status;
    this.error = error;
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

let csrfReady = false;

async function ensureCsrfCookie(): Promise<void> {
  if (!csrfReady || !readCookie('XSRF-TOKEN')) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });
    csrfReady = true;
  }
}

type JsonValue = unknown;

function convertKeys(value: JsonValue, convert: (key: string) => string): JsonValue {
  if (Array.isArray(value)) {
    return value.map((item) => convertKeys(item, convert));
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, JsonValue>).map(([key, val]) => [
        convert(key),
        convertKeys(val, convert),
      ]),
    );
  }
  return value;
}

const toCamel = (key: string) => key.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase());
const toSnake = (key: string) => key.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`);

type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

async function baseHeaders(method: Method): Promise<Record<string, string>> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  if (method !== 'GET') {
    await ensureCsrfCookie();
    const token = readCookie('XSRF-TOKEN');
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
  }

  return headers;
}

/** Turn a non-2xx response into an ApiRequestError carrying the standard error envelope. */
async function throwForStatus(response: Response): Promise<void> {
  if (response.ok) {
    return;
  }
  const json = await response.json().catch(() => ({}));
  const error = (convertKeys(json, toCamel) as { error?: ApiError }).error ?? {
    code: 'UNKNOWN',
    message: 'Something went wrong.',
    fields: {},
  };
  throw new ApiRequestError(response.status, error);
}

export async function api<T>(
  method: Method,
  path: string,
  body?: Record<string, unknown>,
  options: { signal?: AbortSignal } = {},
): Promise<T> {
  const headers = await baseHeaders(method);
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    credentials: 'include', // session cookie — never tokens in localStorage (AI_RULES.md #2)
    body: body !== undefined ? JSON.stringify(convertKeys(body, toSnake)) : undefined,
    signal: options.signal,
  });

  await throwForStatus(response);
  const json = response.status === 204 ? {} : await response.json().catch(() => ({}));

  return convertKeys(json, toCamel) as T;
}

/**
 * POST raw bytes (an encrypted attachment chunk) as `application/octet-stream`, with extra
 * headers (docs/08_API_Specification.md §Attachments). The JSON response is camelCased.
 */
export async function apiUploadBytes<T>(
  path: string,
  bytes: Uint8Array,
  extraHeaders: Record<string, string>,
  options: { signal?: AbortSignal } = {},
): Promise<T> {
  const headers = { ...(await baseHeaders('POST')), ...extraHeaders, 'Content-Type': 'application/octet-stream' };

  const response = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers,
    credentials: 'include',
    body: bytes as BufferSource,
    signal: options.signal,
  });

  await throwForStatus(response);
  return convertKeys(await response.json().catch(() => ({})), toCamel) as T;
}

/**
 * GET a binary resource as a byte stream (an encrypted attachment file). The caller reads
 * the stream; nothing is buffered here.
 */
export async function apiStream(path: string, options: { signal?: AbortSignal } = {}): Promise<AsyncIterable<Uint8Array>> {
  const response = await fetch(`${API_BASE}${path}`, {
    method: 'GET',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'include',
    signal: options.signal,
  });

  await throwForStatus(response);

  const reader = response.body?.getReader();
  if (!reader) {
    throw new ApiRequestError(response.status, { code: 'UNKNOWN', message: 'Empty response.', fields: {} });
  }

  return {
    async *[Symbol.asyncIterator]() {
      try {
        while (true) {
          const { done, value } = await reader.read();
          if (done) {
            return;
          }
          yield value;
        }
      } finally {
        // Stops the download if the consumer gave up (e.g. an integrity failure).
        await reader.cancel().catch(() => undefined);
      }
    },
  };
}
