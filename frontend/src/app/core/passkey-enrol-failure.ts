// src/app/core/passkey-enrol-failure.ts
import { TranslocoService } from '@jsverse/transloco';
import { Problem } from './problem';

/**
 * Turns a failed `PasskeyService.enrol()` into the `Problem` an enrolment
 * surface should render, or `null` when there is nothing to show. Shared by
 * `PasskeysGroupComponent` and `PasskeyOfferDialogComponent` -- both run the
 * identical ceremony and so face the identical failure shapes.
 *
 * A cancelled ceremony (the user dismissed the platform sheet, or it timed
 * out) is not a failure to report -- `PasskeyService`'s own docblock names
 * `NotAllowedError` as exactly that case.
 *
 * `InvalidStateError` -- already enrolled on this account, from the server's
 * exclude list -- gets its own translated, actionable message; any other
 * `ceremonyRejected` failure gets a generic one, since the fallback would
 * otherwise render the browser's raw, untranslated `DOMException`/`Error`
 * text. Keyed on `ceremonyRejected`, not `status === 0`: a genuine dropped
 * connection produces the same status but an already-app-owned title ("Could
 * not reach the server") that must reach the user unchanged. Overwriting
 * `detail` works because every banner reads `error.detail || error.title`.
 */
export function toEnrolFailureProblem(problem: Problem, i18n: TranslocoService): Problem | null {
  if (problem.type === 'NotAllowedError') return null;
  if (problem.type === 'InvalidStateError') {
    return { ...problem, detail: i18n.translate('settings.passkeys.alreadyEnrolled') };
  }
  if (problem.ceremonyRejected) {
    return { ...problem, detail: i18n.translate('settings.passkeys.addFailed') };
  }
  return problem;
}
