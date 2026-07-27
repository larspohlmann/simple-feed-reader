import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { IconPickerComponent } from './icon-picker.component';

describe('IconPickerComponent', () => {
  let fixture: ComponentFixture<IconPickerComponent>;

  const trigger = (): HTMLButtonElement =>
    fixture.nativeElement.querySelector('.trigger') as HTMLButtonElement;
  const options = (): HTMLButtonElement[] =>
    Array.from(fixture.nativeElement.querySelectorAll('.pop .opt'));

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IconPickerComponent, provideTranslocoTesting()],
    }).compileComponents();

    fixture = TestBed.createComponent(IconPickerComponent);
    fixture.componentRef.setInput('value', 'science');
    fixture.detectChanges();
  });

  it('renders the current glyph and keeps the popover closed', () => {
    expect(trigger().querySelector('.material-symbols-outlined')?.textContent?.trim()).toBe(
      'science',
    );
    expect(fixture.nativeElement.querySelector('.pop')).toBeNull();
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
    expect(fixture.nativeElement.querySelector('.pop')).toBeNull();
  });

  it('swallows the Escape that closes the popover', () => {
    const seenByAncestors: Event[] = [];
    const listener = (event: Event) => seenByAncestors.push(event);
    document.addEventListener('keydown', listener);

    try {
      trigger().click();
      fixture.detectChanges();
      trigger().dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
      );
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.pop')).toBeNull();
      // A dialog hosting the picker listens further up; it must not also close.
      expect(seenByAncestors).toEqual([]);
    } finally {
      document.removeEventListener('keydown', listener);
    }
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
