import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { IconPickerComponent } from './icon-picker.component';

describe('IconPickerComponent', () => {
  let fixture: ComponentFixture<IconPickerComponent>;

  const trigger = (): HTMLButtonElement =>
    fixture.nativeElement.querySelector('.trigger') as HTMLButtonElement;
  const grid = (): HTMLElement | null => fixture.nativeElement.querySelector('.grid');
  const options = (): HTMLButtonElement[] =>
    Array.from(fixture.nativeElement.querySelectorAll('.grid .opt'));

  /** Records the Escape presses that reach an ancestor of the picker. */
  const escapeOn = (target: HTMLElement): Event[] => {
    const seenByAncestors: Event[] = [];
    const listener = (event: Event) => seenByAncestors.push(event);
    document.addEventListener('keydown', listener);
    try {
      target.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
      );
      fixture.detectChanges();
    } finally {
      document.removeEventListener('keydown', listener);
    }
    return seenByAncestors;
  };

  const mount = (inline: boolean) => {
    fixture = TestBed.createComponent(IconPickerComponent);
    fixture.componentRef.setInput('value', 'science');
    fixture.componentRef.setInput('inline', inline);
    fixture.detectChanges();
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IconPickerComponent, provideTranslocoTesting()],
    }).compileComponents();
  });

  describe('popover mode', () => {
    beforeEach(() => mount(false));

    it('renders the current glyph and keeps the popover closed', () => {
      expect(trigger().querySelector('.material-symbols-outlined')?.textContent?.trim()).toBe(
        'science',
      );
      expect(grid()).toBeNull();
    });

    it('opens the grid on click and marks the current glyph selected', () => {
      trigger().click();
      fixture.detectChanges();

      const selected = options().find((option) => option.classList.contains('on'));
      expect(selected?.getAttribute('aria-label')).toBe('science');
    });

    it('emits the chosen glyph and closes', () => {
      const chosen: string[] = [];
      fixture.componentInstance.value.subscribe((value) => chosen.push(value));

      trigger().click();
      fixture.detectChanges();
      options()
        .find((option) => option.getAttribute('aria-label') === 'code')!
        .click();
      fixture.detectChanges();

      expect(chosen).toContain('code');
      expect(grid()).toBeNull();
    });

    it('swallows the Escape that closes the popover', () => {
      trigger().click();
      fixture.detectChanges();

      const seenByAncestors = escapeOn(trigger());

      expect(grid()).toBeNull();
      // A dialog hosting the picker listens further up; it must not also close.
      expect(seenByAncestors).toEqual([]);
    });

    it('lets Escape through while the popover is already closed', () => {
      expect(escapeOn(trigger())).toHaveLength(1);
    });

    it('clears the glyph through the no-icon option', () => {
      const chosen: string[] = [];
      fixture.componentInstance.value.subscribe((value) => chosen.push(value));

      trigger().click();
      fixture.detectChanges();
      // The first option is the "no icon" choice.
      options()[0].click();

      expect(chosen).toContain('');
    });
  });

  describe('inline mode', () => {
    beforeEach(() => mount(true));

    it('renders the grid expanded with no trigger', () => {
      expect(trigger()).toBeNull();
      expect(grid()).not.toBeNull();
      expect(options().length).toBeGreaterThan(1);
    });

    it('offers the same options as the popover and marks the current glyph', () => {
      const inlineLabels = options().map((option) => option.getAttribute('aria-label'));
      const selected = options().find((option) => option.classList.contains('on'));
      expect(selected?.getAttribute('aria-label')).toBe('science');

      mount(false);
      trigger().click();
      fixture.detectChanges();
      expect(options().map((option) => option.getAttribute('aria-label'))).toEqual(inlineLabels);
    });

    it('emits the chosen glyph and keeps the grid open', () => {
      const chosen: string[] = [];
      fixture.componentInstance.value.subscribe((value) => chosen.push(value));

      options()
        .find((option) => option.getAttribute('aria-label') === 'code')!
        .click();
      fixture.detectChanges();

      expect(chosen).toContain('code');
      expect(grid()).not.toBeNull();
    });

    it('lets Escape through so a hosting dialog still closes', () => {
      // Nothing to dismiss inline, so the keypress belongs to the dialog.
      expect(escapeOn(grid()!)).toHaveLength(1);
      expect(grid()).not.toBeNull();
    });
  });
});
