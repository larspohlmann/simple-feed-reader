import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from '../core/problem';

/** What went wrong, in terms the section can act on.
 *
 *  `provider` and `validation` carry the server's own sentence -- an ordinary
 *  refusal, or a validation body that never reached the provider. The other
 *  kinds get their own message because the next move differs (wait, configure,
 *  re-enter the key, delete a config) and the server's prose doesn't say
 *  that -- showing it would be an English downgrade in a translated UI.
 *
 *  Classified by problem type alone, never by `detail`: that's prose the
 *  backend or a proxy can reword, which would put one rule on both sides of
 *  the wire with no test able to see them drift. */
export type AiFailureKind =
  | 'unreadableKey'
  | 'rateLimited'
  | 'notConfigured'
  | 'provider'
  | 'limit'
  | 'validation'
  | 'unknown';

/** One rejected field, as `validation_error` reports it. */
export interface AiFieldError {
  readonly field: string;
  readonly messages: readonly string[];
}

export interface AiFailure {
  readonly kind: AiFailureKind;
  /** The server's own reason. Null only when the server sent none: a
   *  production 500, which withholds it deliberately, or no answer at all. */
  readonly detail: string | null;
  /** Empty for every kind but `validation`. */
  readonly fieldErrors: readonly AiFieldError[];
}

/** The kinds whose banner shows the server's sentence instead of a translated
 *  one. Kept as data, not a chain of `if`s, so a test can read the choice
 *  back as one list. */
export const SERVER_TEXT_KINDS: ReadonlySet<AiFailureKind> = new Set<AiFailureKind>([
  'provider',
  'validation',
  'unknown',
]);

/** Which surface a failure belongs to, so each renders its own banner instead
 *  of one shared line above the wrong card. Assigned by the service, which
 *  knows the call -- never by the mapper below, which sees only the response. */
export type AiFailureScope =
  | { readonly action: 'load' }
  | { readonly action: 'add' }
  | { readonly action: 'row'; readonly configId: number };

export interface ScopedAiFailure {
  readonly failure: AiFailure;
  readonly scope: AiFailureScope;
}

export function aiFailure(error: HttpErrorResponse): AiFailure {
  const problem = parseProblem(error);
  const detail = problem.detail ?? null;
  const kind = kindOf(problem.type, problem.status);

  return {
    kind,
    detail,
    fieldErrors: kind === 'validation' ? fieldErrors(problem.errors) : [],
  };
}

function kindOf(type: string, status: number): AiFailureKind {
  if (status === 429) return 'rateLimited';
  if (type === 'ai_not_configured') return 'notConfigured';
  if (type === 'ai_key_unreadable') return 'unreadableKey';
  if (type === 'ai_provider_rejected') return 'provider';
  if (type === 'ai_configuration_limit') return 'limit';
  if (type === 'validation_error') return 'validation';

  return 'unknown';
}

function fieldErrors(errors: Record<string, string[]> | undefined): readonly AiFieldError[] {
  if (!errors) return [];

  return Object.entries(errors)
    .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
    .map(([field, messages]) => ({ field, messages }));
}
