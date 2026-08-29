// src/app/core/passkey-device-name.ts

/**
 * A sensible default name for a passkey being enrolled right now, e.g.
 * "Chrome on macOS" (#624 fix round 1 -- issue #624's scope names
 * "rename-on-create" explicitly, and a fixed label for every enrolment
 * defeats the point of listing several side by side). Other passkey managers
 * default to the same shape, because the name's whole job is to remind the
 * user which device this credential lives on, and "the device this was
 * created on" is the one fact the user always knows at that moment.
 *
 * Pure and Angular-free, like `webauthn.ts`'s capability checks, so browser
 * and platform sniffing -- inherently a pile of special cases -- is tested
 * against real user-agent strings directly rather than only through a
 * rendered dialog. `PasskeyNameDialogComponent` is the only caller.
 */
export function defaultPasskeyName(userAgent: string): string {
  return `${browserName(userAgent)} on ${platformName(userAgent)}`;
}

/** Order matters: Edge and Opera both carry a `Chrome/` token, and every iOS
 *  browser (Chrome, Firefox included) is a Safari webview wearing its own
 *  token in front of `Safari/` -- so the distinguishing token has to be
 *  checked before the generic ones it rides along with. */
function browserName(userAgent: string): string {
  if (/Edg\//.test(userAgent)) return 'Edge';
  if (/OPR\//.test(userAgent)) return 'Opera';
  if (/Firefox\/|FxiOS\//.test(userAgent)) return 'Firefox';
  if (/CriOS\/|Chrome\//.test(userAgent)) return 'Chrome';
  if (/Safari\//.test(userAgent)) return 'Safari';
  return 'Browser';
}

/** Android's UA also carries `Linux`, so it has to be checked first. */
function platformName(userAgent: string): string {
  if (/iPhone|iPod/.test(userAgent)) return 'iPhone';
  if (/iPad/.test(userAgent)) return 'iPad';
  if (/Android/.test(userAgent)) return 'Android';
  if (/Mac OS X/.test(userAgent)) return 'macOS';
  if (/Windows/.test(userAgent)) return 'Windows';
  if (/CrOS/.test(userAgent)) return 'ChromeOS';
  if (/Linux/.test(userAgent)) return 'Linux';
  return 'this device';
}
