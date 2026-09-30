import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { ConfirmDialogComponent, ConfirmData } from './confirm-dialog.component';

describe('ConfirmDialogComponent', () => {
  const close = jest.fn();
  const data: ConfirmData = {
    title: 'Delete tag',
    message: 'Sure?',
    confirmLabel: 'Delete',
    danger: true,
  };

  function render(dialogData: ConfirmData) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: dialogData },
      ],
    });
    const fixture = TestBed.createComponent(ConfirmDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => close.mockReset());

  it('renders the title, message and confirm label', () => {
    const element: HTMLElement = render(data).nativeElement;
    expect(element.textContent).toContain('Delete tag');
    expect(element.textContent).toContain('Sure?');
    expect(element.textContent).toContain('Delete');
  });

  it('closes true on confirm and false on cancel', () => {
    const element: HTMLElement = render(data).nativeElement;
    const buttons = element.querySelectorAll('button');
    (buttons[0] as HTMLButtonElement).click(); // Cancel
    expect(close).toHaveBeenCalledWith(false);
    (buttons[1] as HTMLButtonElement).click(); // Confirm
    expect(close).toHaveBeenCalledWith(true);
  });

  // The CDK's focus trap calls focus() on whatever carries cdkFocusInitial, and
  // an <app-button> host is not focusable -- the marker has to reach the real
  // button inside it or the dialog opens with nothing focused.
  it('marks the real confirm button as the dialog focus target', () => {
    const element: HTMLElement = render(data).nativeElement;
    const marked = element.querySelectorAll('[cdkFocusInitial]');
    expect(marked).toHaveLength(1);
    expect(marked[0].tagName).toBe('BUTTON');
    expect(marked[0].textContent?.trim()).toBe('Delete');
  });

  it('weights a destructive confirmation as danger', () => {
    const element: HTMLElement = render(data).nativeElement;
    const confirm = element.querySelectorAll('button')[1];
    expect(confirm.classList.contains('danger')).toBe(true);
  });

  it('keeps confirm disabled until the required text matches', () => {
    const fixture = render({
      title: 'Delete account',
      message: 'This cannot be undone.',
      confirmLabel: 'Delete',
      danger: true,
      requireText: 'user@example.com',
    });

    const confirmButton = () =>
      fixture.nativeElement.querySelector('app-button[data-testid="confirm"] button');

    expect(confirmButton().disabled).toBe(true);

    // One character short of the required text: a `requireText.startsWith(typed)`
    // implementation would wrongly accept this as a valid prefix.
    fixture.componentInstance.typed.set('user@example.co');
    fixture.detectChanges();
    expect(confirmButton().disabled).toBe(true);

    // Same length, differing case: a case-insensitive compare would wrongly pass.
    fixture.componentInstance.typed.set('User@example.com');
    fixture.detectChanges();
    expect(confirmButton().disabled).toBe(true);

    fixture.componentInstance.typed.set('user@example.com');
    fixture.detectChanges();
    expect(confirmButton().disabled).toBe(false);
  });

  it('enables confirm immediately when no text is required', () => {
    const fixture = render({
      title: 'Remove tag',
      message: 'Sure?',
      confirmLabel: 'Remove',
    });

    const confirmButton = fixture.nativeElement.querySelector(
      'app-button[data-testid="confirm"] button',
    );
    expect(confirmButton.disabled).toBe(false);
  });

  // A disabled confirm button cannot receive focus -- observed in the running
  // app as focus falling through to the dialog container. Moving the focus
  // target to the text input keeps the dialog usable from the keyboard.
  it('moves the initial focus target to the text input when text is required', () => {
    const element: HTMLElement = render({
      title: 'Delete account',
      message: 'This cannot be undone.',
      confirmLabel: 'Delete',
      requireText: 'user@example.com',
    }).nativeElement;

    const marked = element.querySelectorAll('[cdkFocusInitial]');
    expect(marked).toHaveLength(1);
    expect(marked[0].tagName).toBe('INPUT');
  });
});
