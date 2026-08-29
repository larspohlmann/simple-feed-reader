// src/app/reader/passkey-offer-dialog.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { PasskeyService } from '../core/passkey.service';
import { Problem } from '../core/problem';
import { ToastService } from '../shared/toast/toast.service';
import { PasskeyOfferDialogComponent } from './passkey-offer-dialog.component';

/** Drains every pending microtask a ceremony's promise chain needs -- mirrors
 *  `passkey.service.spec.ts`'s own helper, needed here for the same reason:
 *  a fixed number of `await Promise.resolve()` calls is fragile against that
 *  chain's depth changing. */
const flushMicrotasks = async (): Promise<void> => {
  await Promise.resolve();
  await Promise.resolve();
};

describe('PasskeyOfferDialogComponent', () => {
  const close = jest.fn();
  // A fresh Subject per test (reassigned in beforeEach): DialogRef stays a
  // module-level `const`, but each mounted component subscribes to whatever
  // `closed` currently points at, so a shared, never-completing Subject would
  // let a later test's `closed.next()` also fire every earlier test's
  // still-subscribed component.
  let closed = new Subject<void>();
  const passkeyService = { enrol: jest.fn() };
  const authService = { answerPasskeyOffer: jest.fn(), markPasskeyOfferAnswered: jest.fn() };
  const toast = { show: jest.fn() };
  let userAgent: jest.SpyInstance;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close, closed } },
        { provide: PasskeyService, useValue: passkeyService },
        { provide: AuthService, useValue: authService },
        { provide: ToastService, useValue: toast },
      ],
    });
    const f = TestBed.createComponent(PasskeyOfferDialogComponent);
    f.detectChanges();
    return f;
  }

  function findButton(f: ReturnType<typeof mount>, text: string): HTMLButtonElement {
    return Array.from(f.nativeElement.querySelectorAll('button')).find((button) =>
      (button as HTMLButtonElement).textContent?.includes(text),
    ) as HTMLButtonElement;
  }

  beforeEach(() => {
    close.mockReset();
    closed = new Subject<void>();
    passkeyService.enrol.mockReset();
    authService.answerPasskeyOffer.mockReset().mockReturnValue({ subscribe: jest.fn() });
    authService.markPasskeyOfferAnswered.mockReset();
    toast.show.mockReset();
    userAgent = jest.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue('test-agent/1.0');
  });

  afterEach(() => userAgent.mockRestore());

  it('offers both actions in state one', () => {
    const f = mount();

    expect(findButton(f, 'Set up a passkey')).toBeTruthy();
    expect(findButton(f, 'Not now')).toBeTruthy();
  });

  it('calls enrol and closes on success, marking the offer answered without a second POST', async () => {
    passkeyService.enrol.mockResolvedValue(undefined);
    const f = mount();

    findButton(f, 'Set up a passkey').click();
    await flushMicrotasks();
    f.detectChanges();

    expect(passkeyService.enrol).toHaveBeenCalledWith(expect.any(String));
    // The enrol endpoint has already stamped the flag server-side (design
    // spec §5.2) -- only the local signal catches up, never a second POST.
    expect(authService.markPasskeyOfferAnswered).toHaveBeenCalled();
    expect(authService.answerPasskeyOffer).not.toHaveBeenCalled();
    expect(toast.show).toHaveBeenCalled();
    expect(close).toHaveBeenCalled();
  });

  it('keeps the dialog open and shows no scary error when the authenticator sheet is cancelled', async () => {
    const cancelled: Problem = {
      type: 'NotAllowedError',
      title: 'The operation either timed out or was not allowed.',
      status: 0,
    };
    passkeyService.enrol.mockRejectedValue(cancelled);
    const f = mount();

    findButton(f, 'Set up a passkey').click();
    await flushMicrotasks();
    f.detectChanges();

    expect(close).not.toHaveBeenCalled();
    expect(f.nativeElement.textContent).not.toContain('timed out');
    // A cancelled sheet does not count as an answer.
    expect(authService.answerPasskeyOffer).not.toHaveBeenCalled();
    expect(authService.markPasskeyOfferAnswered).not.toHaveBeenCalled();
    // "Not now" must still be there to take.
    expect(findButton(f, 'Not now')).toBeTruthy();
  });

  it('shows the failure and keeps Not now available on a real failure', async () => {
    const failure: Problem = { type: 'about:blank', title: 'Something went wrong', status: 500 };
    passkeyService.enrol.mockRejectedValue(failure);
    const f = mount();

    findButton(f, 'Set up a passkey').click();
    await flushMicrotasks();
    f.detectChanges();

    expect(close).not.toHaveBeenCalled();
    expect(f.nativeElement.textContent).toContain('Something went wrong');
    expect(findButton(f, 'Not now')).toBeTruthy();
  });

  it('swaps to state two when Not now is chosen', () => {
    const f = mount();

    findButton(f, 'Not now').click();
    f.detectChanges();

    expect(findButton(f, 'Set up a passkey')).toBeFalsy();
    expect(f.nativeElement.querySelector('[data-test="passkey-offer-ok"]')).toBeTruthy();
  });

  it('marks the offer answered the moment state two opens, not when OK is pressed', () => {
    const f = mount();

    findButton(f, 'Not now').click();
    f.detectChanges();

    expect(authService.answerPasskeyOffer).toHaveBeenCalledTimes(1);

    // Pressing OK must not send a second, redundant answer.
    const ok = (f.nativeElement as HTMLElement).querySelector<HTMLButtonElement>(
      '[data-test="passkey-offer-ok"]',
    )!;
    ok.click();
    expect(authService.answerPasskeyOffer).toHaveBeenCalledTimes(1);
  });

  it('names the Settings path in state two', () => {
    const f = mount();

    findButton(f, 'Not now').click();
    f.detectChanges();

    expect(f.nativeElement.textContent).toContain('Settings');
    expect(f.nativeElement.textContent).toContain('Passkeys');
  });

  it('marks the offer answered on close even when the dialog is dismissed without a choice (Escape/backdrop)', () => {
    mount();

    closed.next();

    expect(authService.answerPasskeyOffer).toHaveBeenCalledTimes(1);
  });
});
