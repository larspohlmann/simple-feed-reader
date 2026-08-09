// src/app/settings/ai-failure.ts
import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from '../core/problem';

/**
 * What went wrong, in the terms the section has to answer in.
 *
 * `provider` is the ordinary refusal — the endpoint did not answer, or it
 * rejected the key — and carries the server's own sentence, which already says
 * which of the two happened. The other kinds get a message of their own
 * because the account's next move differs: wait, configure first, enter the
 * key again, or delete a configuration to make room for a new one.
 *
 * Every kind is decided by the problem type alone. The detail is prose the
 * backend is free to reword or a proxy to reflow, so classifying on it would
 * put one rule on both sides of the wire with no test able to see them drift.
 */
export type AiFailureKind =
  'unreadableKey' | 'rateLimited' | 'notConfigured' | 'provider' | 'limit' | 'unknown';

export interface AiFailure {
  readonly kind: AiFailureKind;
  /** The server's own reason, shown only for `provider`. */
  readonly detail: string | null;
}

export function aiFailure(error: HttpErrorResponse): AiFailure {
  const problem = parseProblem(error);
  const detail = problem.detail ?? null;

  if (problem.status === 429) return { kind: 'rateLimited', detail };
  if (problem.type === 'ai_not_configured') return { kind: 'notConfigured', detail };
  if (problem.type === 'ai_key_unreadable') return { kind: 'unreadableKey', detail };
  if (problem.type === 'ai_provider_rejected') return { kind: 'provider', detail };
  if (problem.type === 'ai_configuration_limit') return { kind: 'limit', detail };

  return { kind: 'unknown', detail: null };
}
