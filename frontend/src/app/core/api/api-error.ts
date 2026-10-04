import { HttpErrorResponse } from '@angular/common/http';

/** Error body returned by the PHP API: { error: "code", details?: {...} } */
export interface ApiErrorBody {
  error: string;
  details?: Record<string, string>;
}

export interface TranslatedError {
  key: string;
  params?: Record<string, unknown>;
}

export function apiErrorCode(err: unknown): string {
  if (err instanceof HttpErrorResponse) {
    if (err.status === 0) {
      return 'network';
    }
    const body = err.error as Partial<ApiErrorBody> | null;
    return typeof body?.error === 'string' ? body.error : 'unknown';
  }
  return 'unknown';
}

/** Translation key (+ params) for a failed API call, e.g. { key: 'errors.invalid_credentials' }. */
export function apiError(err: unknown): TranslatedError {
  const code = apiErrorCode(err);
  const details = err instanceof HttpErrorResponse ? (err.error as Partial<ApiErrorBody> | null)?.details : undefined;
  return { key: `errors.${code}`, params: details };
}

/**
 * Field errors from a 422 validation_failed response.
 * The API sends e.g. { email: "taken", password: "min:8" }; returns { email: { key, params } }.
 */
export function apiFieldErrors(err: unknown): Record<string, TranslatedError> {
  if (!(err instanceof HttpErrorResponse) || err.status !== 422) {
    return {};
  }
  const details = (err.error as Partial<ApiErrorBody> | null)?.details ?? {};
  const result: Record<string, TranslatedError> = {};
  for (const [field, rule] of Object.entries(details)) {
    const [name, value] = String(rule).split(':', 2);
    result[field] = { key: `validation.${name}`, params: value === undefined ? undefined : { value } };
  }
  return result;
}
