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
 * `InvalidStateError` -- this authenticator is already enrolled on the
 * account, produced by the server's exclude list -- gets its own translated,
 * actionable message. Any other `problem.ceremonyRejected` failure gets the
 * same treatment: the fallback path renders `error.title`, which for a
 * `DOMException` or a plain `Error` is the browser's own untranslated,
 * locale-dependent text. Keyed on `ceremonyRejected` rather than
 * `problem.status === 0`, because a genuine dropped connection during one of
 * `PasskeyService`'s own HTTP calls produces that same status with an
 * already-app-owned title ("Could not reach the server"), which must reach
 * the user unchanged rather than get overwritten by the generic passkey
 * message. Overwriting `detail` works because every banner in this app reads
 * `error.detail || error.title`.
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
