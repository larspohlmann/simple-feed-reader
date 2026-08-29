// src/app/settings/passkeys-group.component.spec.ts
import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PasskeyService, PasskeySummary } from '../core/passkey.service';
import { Problem } from '../core/problem';
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

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: PasskeyService, useValue: passkeyService }],
    });
    const f = TestBed.createComponent(PasskeysGroupComponent);
    f.detectChanges();
    return f;
  }

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

    it('calls enrol when "Add a passkey" is clicked', () => {
      passkeyService = passkeyServiceStub([]);
      const f = mount();

      const addButton = Array.from(f.nativeElement.querySelectorAll('button')).find((button) =>
        (button as HTMLButtonElement).textContent?.includes('Add a passkey'),
      ) as HTMLButtonElement;
      addButton.click();

      expect(passkeyService.enrol).toHaveBeenCalledTimes(1);
    });

    it('calls remove for the clicked row and refreshes the list', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      const f = mount();
      passkeyService.list.mockReturnValue(of([]));

      const removeButton = f.nativeElement.querySelector(
        '[data-test="remove-passkey"]',
      ) as HTMLButtonElement;
      removeButton.click();
      f.detectChanges();

      expect(passkeyService.remove).toHaveBeenCalledWith(TOUCH_ID.id);
      // The refresh after a successful remove re-lists: the stub above now
      // reports none left, and the row for it is gone.
      expect(passkeyService.list).toHaveBeenCalledTimes(2);
      expect(f.nativeElement.querySelectorAll('app-settings-row').length).toBe(0);
    });

    it('renders the lock-out message from the problem body on a 409', () => {
      passkeyService = passkeyServiceStub([TOUCH_ID]);
      // `PasskeyService.remove()` issues a plain `HttpClient.delete()` with no
      // catch of its own, so a failure reaches this component as the raw
      // `HttpErrorResponse` -- exactly what `parseProblem()` expects, and what
      // `AccountSectionComponent`'s own 409 spec exercises the same way.
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

      const removeButton = f.nativeElement.querySelector(
        '[data-test="remove-passkey"]',
      ) as HTMLButtonElement;
      removeButton.click();
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
      const cancelled: Problem = {
        type: 'NotAllowedError',
        title: 'The operation either timed out or was not allowed.',
        status: 0,
      };
      passkeyService.enrol.mockRejectedValue(cancelled);
      const f = mount();

      const addButton = Array.from(f.nativeElement.querySelectorAll('button')).find((button) =>
        (button as HTMLButtonElement).textContent?.includes('Add a passkey'),
      ) as HTMLButtonElement;
      addButton.click();
      await Promise.resolve();
      await Promise.resolve();
      f.detectChanges();

      expect(f.nativeElement.querySelector('.banner')).toBeNull();
      expect(f.nativeElement.textContent).not.toContain('timed out');
    });
  });
});
