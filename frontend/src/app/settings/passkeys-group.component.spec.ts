import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { PasskeyService, PasskeySummary } from '../core/passkey.service';
import { Problem } from '../core/problem';
import { SetupService } from '../setup/setup.service';
import { ConfirmDialogComponent } from '../shared/confirm-dialog/confirm-dialog.component';
import { PasskeyNameDialogComponent } from './passkey-name-dialog.component';
import { PasskeysGroupComponent } from './passkeys-group.component';

const TOUCH_ID: PasskeySummary = {
  id: 1,
  label: 'MacBook Touch ID',
  createdAt: '2026-08-16T10:00:00Z',
  lastUsedAt: '2026-08-20T09:00:00Z',
};

const NEVER_USED: PasskeySummary = {
  id: 2,
  label: 'YubiKey 5C',
  createdAt: '2026-08-10T10:00:00Z',
  lastUsedAt: null,
};

interface PasskeyServiceStub {
  list: jest.Mock;
  enrol: jest.Mock;
  remove: jest.Mock;
}

function passkeyServiceStub(passkeys: PasskeySummary[] = []): PasskeyServiceStub {
  return {
    list: jest.fn(() => of(passkeys)),
    enrol: jest.fn(() => Promise.resolve()),
    remove: jest.fn(() => of(undefined)),
  };
}

describe('PasskeysGroupComponent', () => {
  let passkeyService: PasskeyServiceStub;
  // Enrol opens PasskeyNameDialogComponent, remove opens ConfirmDialogComponent
  // (#624) -- both stubbed via Dialog, matching AccountSectionComponent's own
  // dialog spec, rather than rendering the real CDK overlay.
  const dialogStub = { open: jest.fn() };
  const authService = { markPasskeyOfferAnswered: jest.fn() };

  /**
   * passkeySignInAvailable defaults to true so pre-existing tests need not
   * know about the toggle; the describe block below covers false and null.
   */
  function mount(passkeySignInAvailable: boolean | null = true) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: PasskeyService, useValue: passkeyService },
        { provide: Dialog, useValue: dialogStub },
        { provide: AuthService, useValue: authService },
        {
          provide: SetupService,
          useValue: {
            ensureLoaded: () => of(false),
            passkeySignInAvailable: signal(passkeySignInAvailable),
          },
        },
      ],
    });
    const f = TestBed.createComponent(PasskeysGroupComponent);
    f.detectChanges();
    return f;
  }

  function clickAdd(f: ReturnType<typeof mount>): void {
    const addButton = Array.from(f.nativeElement.querySelectorAll('button')).find((button) =>
      (button as HTMLButtonElement).textContent?.includes('Add a passkey'),
    ) as HTMLButtonElement;
    addButton.click();
  }

  function clickRemove(f: ReturnType<typeof mount>): void {
    const removeButton = f.nativeElement.querySelector(
      '[data-test="remove-passkey"]',
    ) as HTMLButtonElement;
    removeButton.click();
  }

  beforeEach(() => {
    dialogStub.open.mockReset();
    authService.markPasskeyOfferAnswered.mockReset();
  });

  afterEach(() => {
    // jsdom carries neither: leaving a stub behind would leak "supported"
    // into every spec that runs after one from the block below.
    delete (window as unknown as { PublicKeyCredential?: unknown }).PublicKeyCredential;
  });

  describe('when the browser has no WebAuthn support', () => {
    it('renders nothing at all', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount();

      expect(f.nativeElement.textContent.trim()).toBe('');
      expect(passkeyService.list).not.toHaveBeenCalled();
    });
  });

  /**
   * #624: fails CLOSED (null renders nothing), unlike the login page's
   * fail-open convention -- there the worst case is a stale link, here it's
   * a credential the user is stuck with.
   */
  describe('when the browser supports passkeys but the instance does not offer sign-in', () => {
    beforeEach(() => {
      (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {};
    });

    it('renders nothing when the instance reports it unavailable', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount(false);

      expect(f.nativeElement.textContent.trim()).toBe('');
      expect(passkeyService.list).not.toHaveBeenCalled();
    });

    it('renders nothing while availability is still unknown', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount(null);

      expect(f.nativeElement.textContent.trim()).toBe('');
      expect(passkeyService.list).not.toHaveBeenCalled();
    });

    it('renders the group once availability is confirmed', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount(true);

      expect(f.nativeElement.textContent.trim()).not.toBe('');
      expect(passkeyService.list).toHaveBeenCalledTimes(1);
    });
  });

  describe('when the browser supports passkeys', () => {
    beforeEach(() => {
      // jsdom has no PublicKeyCredential at all; `isPasskeySupported()` only
      // checks `'PublicKeyCredential' in window`, so any value stubs it in --
      // matching `webauthn.spec.ts`'s own convention.
      (window as unknown as { PublicKeyCredential: unknown }).PublicKeyCredential = {};
    });

    it('renders one row per credential with its label and creation date', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID, NEVER_USED]);
      const f = mount();

      const rows = f.nativeElement.querySelectorAll('app-settings-row');
      expect(rows.length).toBe(2);
      expect(rows[0].textContent).toContain('MacBook Touch ID');
      expect(rows[0].textContent).toContain('August 16, 2026');
      expect(rows[1].textContent).toContain('YubiKey 5C');
      expect(rows[1].textContent).toContain('August 10, 2026');
    });

    it('renders two credentials with different labels as two distinguishable rows', () => {
      // Before rename-on-create every enrolment sent the same default label,
      // so two credentials rendered with IDENTICAL titles -- telling them
      // apart required reading near-identical timestamps.
      passkeyService = passkeyServiceStub([TOUCH_ID, NEVER_USED]);
      const f = mount();

      const titles = Array.from(f.nativeElement.querySelectorAll('.row-title')).map((title) =>
        (title as HTMLElement).textContent?.trim(),
      );
      expect(titles).toEqual(['MacBook Touch ID', 'YubiKey 5C']);
    });

    it('shows the never-used copy for a credential with no lastUsedAt, not a blank', () => {
      passkeyService = passkeyServiceStub([NEVER_USED]);
      const f = mount();

      const row = f.nativeElement.querySelector('app-settings-row');
      expect(row.textContent).toContain('Never');
      // The row still names when it was added -- "never used" must not read
      // as "we have no data on this row at all".
      expect(row.textContent).toContain('August 10, 2026');
    });

    it('does not render a credential’s used-date twice for the one that has been used', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount();

      const row = f.nativeElement.querySelector('app-settings-row');
      expect(row.textContent).toContain('August 20, 2026');
      expect(row.textContent).not.toContain('Never');
    });

    it('opens the naming dialog and enrols with the name it returns', () => {
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const f = mount();

      clickAdd(f);

      expect(dialogStub.open).toHaveBeenCalledWith(
        PasskeyNameDialogComponent,
        expect.objectContaining({ panelClass: 'app-dialog' }),
      );
      expect(passkeyService.enrol).toHaveBeenCalledWith('MacBook Touch ID');
    });

    it('marks the first-login offer answered locally after a successful enrolment from Settings', async () => {
      // auth.user() isn't refetched on route change; without this call,
      // navigating to `/` reopens the first-login offer for a passkey that
      // already exists, whose ceremony then fails with InvalidStateError.
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const f = mount();

      clickAdd(f);
      await Promise.resolve();
      await Promise.resolve();

      expect(authService.markPasskeyOfferAnswered).toHaveBeenCalled();
    });

    it('does not enrol when the naming dialog is dismissed with no name', () => {
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of(undefined) });
      const f = mount();

      clickAdd(f);

      expect(passkeyService.enrol).not.toHaveBeenCalled();
    });

    it('does not open a second naming dialog on a fast double-click', () => {
      // A `Subject` that never emits, unlike the `of(...)` fixtures above --
      // it stands in for a dialog the user has not yet acted on, which is
      // exactly the window a double-click has to land in to matter.
      passkeyService = passkeyServiceStub([]);
      const closed = new Subject<string | undefined>();
      dialogStub.open.mockReturnValue({ closed });
      const f = mount();

      clickAdd(f);
      clickAdd(f);

      expect(dialogStub.open).toHaveBeenCalledTimes(1);
    });

    it('shows a translated message, not the raw DOMException text, when the authenticator is already enrolled', async () => {
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const alreadyEnrolled: Problem = {
        type: 'InvalidStateError',
        // The real, untranslated, browser-supplied text -- asserting it is
        // ABSENT from the rendered banner is what proves the branch fired.
        title:
          'The user attempted to register an authenticator that contains one of the credentials already registered with the relying party.',
        status: 0,
        ceremonyRejected: true,
      };
      passkeyService.enrol.mockRejectedValue(alreadyEnrolled);
      const f = mount();

      clickAdd(f);
      await Promise.resolve();
      await Promise.resolve();
      f.detectChanges();

      expect(f.nativeElement.textContent).toContain(
        'This device is already registered as a passkey for this account.',
      );
      expect(f.nativeElement.textContent).not.toContain('relying party');
    });

    it('shows a translated message, not the raw DOMException text, on any other ceremony failure', async () => {
      // ceremonyRejected flags "from the browser, not the server"; a
      // ConstraintError must not surface its own untranslated title, same as
      // InvalidStateError. Not keyed on status === 0 alone -- see below.
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const authenticatorError: Problem = {
        type: 'ConstraintError',
        title: 'The authenticator does not meet the requested criteria.',
        status: 0,
        ceremonyRejected: true,
      };
      passkeyService.enrol.mockRejectedValue(authenticatorError);
      const f = mount();

      clickAdd(f);
      await Promise.resolve();
      await Promise.resolve();
      f.detectChanges();

      expect(f.nativeElement.textContent).toContain('The passkey could not be added.');
      expect(f.nativeElement.textContent).not.toContain('requested criteria');
    });

    it('shows the server-unreachable message on a genuine network failure during enrolment, not the generic passkey fallback', async () => {
      // A dropped connection also reaches this component as status: 0 -- the
      // SAME status a rejected ceremony uses, so status alone can't tell them
      // apart. Overwriting this title would hide what actually happened.
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const networkFailure: Problem = {
        type: 'about:blank',
        title: 'Could not reach the server',
        status: 0,
      };
      passkeyService.enrol.mockRejectedValue(networkFailure);
      const f = mount();

      clickAdd(f);
      await Promise.resolve();
      await Promise.resolve();
      f.detectChanges();

      expect(f.nativeElement.textContent).toContain('Could not reach the server');
      expect(f.nativeElement.textContent).not.toContain('The passkey could not be added.');
    });

    it('opens a confirm dialog naming the passkey, and removes only once confirmed', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      dialogStub.open.mockReturnValue({ closed: of(true) });
      const f = mount();
      passkeyService.list.mockReturnValue(of([]));

      clickRemove(f);
      f.detectChanges();

      expect(dialogStub.open).toHaveBeenCalledWith(
        ConfirmDialogComponent,
        expect.objectContaining({
          role: 'alertdialog',
          panelClass: 'app-dialog',
          data: expect.objectContaining({
            message: expect.stringContaining('MacBook Touch ID'),
            danger: true,
          }),
        }),
      );
      expect(passkeyService.remove).toHaveBeenCalledWith(TOUCH_ID.id);
      // The refresh after a successful remove re-lists: the stub above now
      // reports none left, and the row for it is gone.
      expect(passkeyService.list).toHaveBeenCalledTimes(2);
      expect(f.nativeElement.querySelectorAll('app-settings-row').length).toBe(0);
    });

    it('sends no request when the removal confirmation is dismissed', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      dialogStub.open.mockReturnValue({ closed: of(false) });
      const f = mount();

      clickRemove(f);
      f.detectChanges();

      expect(passkeyService.remove).not.toHaveBeenCalled();
      expect(passkeyService.list).toHaveBeenCalledTimes(1);
      expect(f.nativeElement.querySelectorAll('app-settings-row').length).toBe(1);
    });

    it('renders the lock-out message from the problem body on a 409', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      dialogStub.open.mockReturnValue({ closed: of(true) });
      // remove() issues a plain HttpClient.delete() with no catch, so a
      // failure reaches this component as a raw HttpErrorResponse -- what
      // parseProblem() expects, matching AccountSectionComponent's 409 spec.
      const conflict: Problem = {
        type: 'last_credential',
        title: 'Last passkey',
        status: 409,
        detail: 'Removing this passkey would leave you unable to sign in. Add another one first.',
      };
      passkeyService.remove.mockReturnValue(
        throwError(
          () => new HttpErrorResponse({ error: conflict, status: 409, statusText: 'Conflict' }),
        ),
      );
      const f = mount();

      clickRemove(f);
      f.detectChanges();

      expect(f.nativeElement.textContent).toContain(
        'Removing this passkey would leave you unable to sign in. Add another one first.',
      );
      // The row survives: the server refused the delete, so the list is
      // unchanged rather than refreshed away.
      expect(f.nativeElement.querySelectorAll('app-settings-row').length).toBe(1);
    });

    it('does not render an error when the ceremony is cancelled', async () => {
      passkeyService = passkeyServiceStub([]);
      dialogStub.open.mockReturnValue({ closed: of('MacBook Touch ID') });
      const cancelled: Problem = {
        type: 'NotAllowedError',
        title: 'The operation either timed out or was not allowed.',
        status: 0,
        ceremonyRejected: true,
      };
      passkeyService.enrol.mockRejectedValue(cancelled);
      const f = mount();

      clickAdd(f);
      await Promise.resolve();
      await Promise.resolve();
      f.detectChanges();

      expect(f.nativeElement.querySelector('.banner')).toBeNull();
      expect(f.nativeElement.textContent).not.toContain('timed out');
    });
  });
});
