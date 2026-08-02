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

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(ConfirmDialogComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => close.mockReset());

  it('renders the title, message and confirm label', () => {
    const el: HTMLElement = mount().nativeElement;
    expect(el.textContent).toContain('Delete tag');
    expect(el.textContent).toContain('Sure?');
    expect(el.textContent).toContain('Delete');
  });

  it('closes true on confirm and false on cancel', () => {
    const el: HTMLElement = mount().nativeElement;
    const buttons = el.querySelectorAll('button');
    (buttons[0] as HTMLButtonElement).click(); // Cancel
    expect(close).toHaveBeenCalledWith(false);
    (buttons[1] as HTMLButtonElement).click(); // Confirm
    expect(close).toHaveBeenCalledWith(true);
  });

  // The CDK's focus trap calls focus() on whatever carries cdkFocusInitial, and
  // an <app-button> host is not focusable -- the marker has to reach the real
  // button inside it or the dialog opens with nothing focused.
  it('marks the real confirm button as the dialog focus target', () => {
    const el: HTMLElement = mount().nativeElement;
    const marked = el.querySelectorAll('[cdkFocusInitial]');
    expect(marked).toHaveLength(1);
    expect(marked[0].tagName).toBe('BUTTON');
    expect(marked[0].textContent?.trim()).toBe('Delete');
  });

  it('weights a destructive confirmation as danger', () => {
    const el: HTMLElement = mount().nativeElement;
    const confirm = el.querySelectorAll('button')[1];
    expect(confirm.classList.contains('danger')).toBe(true);
  });
});
