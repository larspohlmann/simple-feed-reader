// src/app/settings/passkey-name-dialog.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PasskeyNameDialogComponent } from './passkey-name-dialog.component';

describe('PasskeyNameDialogComponent', () => {
  const close = jest.fn();
  let userAgent: jest.SpyInstance;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [{ provide: DialogRef, useValue: { close } }],
    });
    const f = TestBed.createComponent(PasskeyNameDialogComponent);
    f.detectChanges();
    return f;
  }

  function nameInput(f: ReturnType<typeof mount>): HTMLInputElement {
    return f.nativeElement.querySelector('#passkey-name');
  }

  function confirmButton(f: ReturnType<typeof mount>): HTMLButtonElement {
    return f.nativeElement.querySelector('button[type="submit"]');
  }

  beforeEach(() => {
    close.mockReset();
    userAgent = jest.spyOn(window.navigator, 'userAgent', 'get');
  });

  afterEach(() => userAgent.mockRestore());

  it('pre-fills the name with a device-derived default', () => {
    userAgent.mockReturnValue(
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
        '(KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
    );
    const f = mount();

    expect(nameInput(f).value).toBe('Chrome on macOS');
  });

  it('closes with the edited name when the user changes it and confirms', () => {
    userAgent.mockReturnValue('some-unusual-client/1.0');
    const f = mount();

    const input = nameInput(f);
    input.value = "Lars's YubiKey";
    input.dispatchEvent(new Event('input'));
    f.detectChanges();

    confirmButton(f).click();

    expect(close).toHaveBeenCalledWith("Lars's YubiKey");
  });

  it('trims the name before closing', () => {
    userAgent.mockReturnValue('some-unusual-client/1.0');
    const f = mount();

    const input = nameInput(f);
    input.value = '  Spaced out  ';
    input.dispatchEvent(new Event('input'));
    f.detectChanges();

    confirmButton(f).click();

    expect(close).toHaveBeenCalledWith('Spaced out');
  });

  it('disables the confirm action when the name is cleared, and does not close', () => {
    userAgent.mockReturnValue('some-unusual-client/1.0');
    const f = mount();

    const input = nameInput(f);
    input.value = '';
    input.dispatchEvent(new Event('input'));
    f.detectChanges();

    expect(confirmButton(f).disabled).toBe(true);

    f.componentInstance.confirm();
    expect(close).not.toHaveBeenCalled();
  });

  it('closes with nothing when cancelled', () => {
    userAgent.mockReturnValue('some-unusual-client/1.0');
    const f = mount();

    const cancelButton = Array.from(f.nativeElement.querySelectorAll('button')).find((button) =>
      (button as HTMLButtonElement).textContent?.includes('Cancel'),
    ) as HTMLButtonElement;
    cancelButton.click();

    expect(close).toHaveBeenCalledWith();
  });
});
