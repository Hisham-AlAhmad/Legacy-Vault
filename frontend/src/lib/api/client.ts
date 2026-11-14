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

export async function api<T>(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  body?: Record<string, unknown>,
): Promise<T> {
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
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    credentials: 'include', // session cookie — never tokens in localStorage (AI_RULES.md #2)
    body: body !== undefined ? JSON.stringify(convertKeys(body, toSnake)) : undefined,
  });

  const json = response.status === 204 ? {} : await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = (convertKeys(json, toCamel) as { error?: ApiError }).error ?? {
      code: 'UNKNOWN',
      message: 'Something went wrong.',
      fields: {},
    };
    throw new ApiRequestError(response.status, error);
  }

  return convertKeys(json, toCamel) as T;
}
