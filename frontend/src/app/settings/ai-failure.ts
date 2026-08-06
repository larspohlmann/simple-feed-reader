// src/app/settings/ai-failure.ts
import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from '../core/problem';

/**
 * What went wrong, in the terms the section has to answer in.
 *
 * `provider` is the ordinary refusal — the endpoint did not answer, or it
 * rejected the key — and carries the server's own sentence, which already says
 * which of the two happened. The other kinds get a message of their own
 * because the account's next move differs: wait, configure first, or enter the
 * key again.
 */
export type AiFailureKind =
  'unreadableKey' | 'rateLimited' | 'notConfigured' | 'provider' | 'unknown';

export interface AiFailure {
  readonly kind: AiFailureKind;
  /** The server's own reason, shown only for `provider`. */
  readonly detail: string | null;
}

/**
 * The one sentence the API sends when the stored key cannot be decrypted
 * (AiProviderApiException::forUnreadableStoredKey). It shares the
 * `ai_provider_rejected` type with the ordinary refusals, so the detail is the
 * only thing that separates it — and it has to be separated, because the
 * account cannot fix it by retrying, only by entering the key again, while
 * `GET /api/me/ai` still reports a healthy `configured: true`.
 *
 * A reworded backend detail degrades to `provider`, which still shows this
 * exact sentence verbatim. So the worst case is an untranslated message, never
 * a wrong or missing one.
 */
const STORED_KEY_UNREADABLE = 'The stored API key can no longer be read.';

export function aiFailure(error: HttpErrorResponse): AiFailure {
  const problem = parseProblem(error);
  const detail = problem.detail ?? null;

  if (problem.status === 429) return { kind: 'rateLimited', detail };
  if (problem.type === 'ai_not_configured') return { kind: 'notConfigured', detail };
  if (detail?.startsWith(STORED_KEY_UNREADABLE)) return { kind: 'unreadableKey', detail };
  if (problem.type === 'ai_provider_rejected') return { kind: 'provider', detail };

  return { kind: 'unknown', detail: null };
}
