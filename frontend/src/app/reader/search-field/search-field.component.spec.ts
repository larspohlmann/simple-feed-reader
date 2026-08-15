// src/app/reader/search-field/search-field.component.spec.ts
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SearchFieldComponent } from './search-field.component';

function mount() {
  TestBed.configureTestingModule({
    imports: [SearchFieldComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(SearchFieldComponent);
  fixture.componentRef.setInput('term', '');
  fixture.detectChanges();
  return fixture;
}

function typeInto(fixture: ReturnType<typeof mount>, value: string): void {
  const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;
  input.value = value;
  input.dispatchEvent(new Event('input'));
  fixture.detectChanges();
}

describe('SearchFieldComponent', () => {
  it('emits nothing before the debounce elapses, and the trimmed term after it', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, '  cats  ');
    expect(emitted).toEqual([]);

    tick(299);
    expect(emitted).toEqual([]);

    tick(1);
    expect(emitted).toEqual(['cats']);
  }));

  it('re-emits a term typed again right after it was cleared', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'angular');
    tick(300);
    expect(emitted).toEqual(['angular']);

    const clearButton: HTMLButtonElement = fixture.debugElement.query(
      By.css('.clear'),
    ).nativeElement;
    clearButton.click();
    expect(emitted).toEqual(['angular', '']);

    typeInto(fixture, 'angular');
    tick(300);

    expect(emitted).toEqual(['angular', '', 'angular']);
  }));

  it('re-emits a term typed again after the route moved on without this component', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    // Establish "angular" as the genuinely active term via a real, settled
    // emission — not merely as the field's initial default — so a stale
    // memory of it is what the next step must overwrite.
    typeInto(fixture, 'angular');
    tick(300);
    expect(emitted).toEqual(['angular']);

    // Simulate Back/Forward: the URL's term changes to something else while
    // this component sits there, with no typing of its own.
    fixture.componentRef.setInput('term', 'python');
    fixture.detectChanges();

    typeInto(fixture, 'angular');
    tick(300);

    expect(emitted).toEqual(['angular', 'angular']);
  }));

  it('does not let a cleared search reappear from a pending debounce', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'angular');
    tick(100); // well inside the 300 ms window: the debounce is still pending

    const clearButton: HTMLButtonElement = fixture.debugElement.query(
      By.css('.clear'),
    ).nativeElement;
    clearButton.click();

    tick(300); // drain the pending debounce — "angular" must not resurface

    expect(emitted).toEqual(['']);
  }));

  it('collapses a debounce burst that settles on a repeat into one emission', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'a');
    typeInto(fixture, 'an');
    typeInto(fixture, 'ang');
    typeInto(fixture, 'angu');
    tick(300);

    expect(emitted).toEqual(['angu']);
  }));

  it('emits nothing at all for a two-character term', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'ab');
    tick(300);

    expect(emitted).toEqual([]);
  }));

  it('shows the too-short hint at two characters and hides it at three', () => {
    const fixture = mount();

    typeInto(fixture, 'ab');
    expect(fixture.debugElement.query(By.css('.hint'))).toBeTruthy();
    expect(fixture.nativeElement.textContent).toContain('Type at least 3 characters.');

    typeInto(fixture, 'abc');
    expect(fixture.debugElement.query(By.css('.hint'))).toBeFalsy();
  });

  it('clears immediately, with no debounce', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'cats');
    tick(300);
    expect(emitted).toEqual(['cats']);

    const clearButton: HTMLButtonElement = fixture.debugElement.query(
      By.css('.clear'),
    ).nativeElement;
    clearButton.click();

    // No tick() at all: a debounced clear would leave `emitted` unchanged here.
    expect(emitted).toEqual(['cats', '']);
  }));

  it('labels the clear button for assistive tech', () => {
    const fixture = mount();
    typeInto(fixture, 'cats');

    const clearButton = fixture.debugElement.query(By.css('.clear')).nativeElement;
    expect(clearButton.getAttribute('aria-label')).toBe('Clear search');
  });

  it('clears on Escape when the field is non-empty', fakeAsync(() => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));

    typeInto(fixture, 'cats');
    const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(input.value).toBe('');
    expect(emitted).toEqual(['']);
  }));

  it('emits escapedWhileEmpty on Escape when the field is already empty, without clearing again', () => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.search.subscribe((term) => emitted.push(term));
    const escapedWhileEmpty = jest.fn();
    fixture.componentInstance.escapedWhileEmpty.subscribe(escapedWhileEmpty);

    const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(escapedWhileEmpty).toHaveBeenCalledTimes(1);
    expect(emitted).toEqual([]);
  });

  it('carries role="search" on its wrapper', () => {
    const fixture = mount();
    const wrapper = fixture.debugElement.query(By.css('[role="search"]'));
    expect(wrapper).toBeTruthy();
  });

  describe('the loading spinner (#408 follow-up)', () => {
    it('shows the plain search icon, not the spinner, while loading is false', () => {
      const fixture = mount();
      fixture.componentRef.setInput('loading', false);
      fixture.detectChanges();

      expect(fixture.debugElement.query(By.css('.icon app-icon, app-icon.icon'))).toBeTruthy();
      expect(fixture.debugElement.query(By.css('app-spinner'))).toBeFalsy();
    });

    it('replaces the search icon with a decorative spinner while loading is true', () => {
      const fixture = mount();
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();

      const spinner = fixture.debugElement.query(By.css('app-spinner'));
      expect(spinner).toBeTruthy();
      expect(fixture.debugElement.query(By.css('app-icon[name="search"]'))).toBeFalsy();
      // decorative: this is a state indicator inside an already-labelled field,
      // not a standalone status region — a second announced "Loading" would
      // fight the entry list's own live region.
      expect(spinner.componentInstance.decorative).toBe(true);
    });

    it('goes back to the search icon once loading turns false again', () => {
      const fixture = mount();
      fixture.componentRef.setInput('loading', true);
      fixture.detectChanges();
      fixture.componentRef.setInput('loading', false);
      fixture.detectChanges();

      expect(fixture.debugElement.query(By.css('app-spinner'))).toBeFalsy();
      expect(fixture.debugElement.query(By.css('app-icon[name="search"]'))).toBeTruthy();
    });
  });

  describe('the clear button chrome (#408 follow-up)', () => {
    // jsdom applies a real UA default stylesheet to <button> (background-color:
    // buttonface, a 2px outset border) — proven by probing a bare <button> in
    // this same environment. What jsdom's test runner does NOT do is compile or
    // inject this component's `styleUrl` (jest-preset-angular does not run the
    // Angular build pipeline that would turn search-field.component.scss into a
    // loaded stylesheet), so `getComputedStyle` on the mounted button here
    // still reports the UA default regardless of what the .scss says — that
    // was checked directly and would make an assertion on computed style a
    // false pass either way. What IS honestly provable in this environment is
    // the wiring: the reset lives on the `.clear` class, and this is the class
    // the template actually applies to the button that needs it.
    it('renders the clear control as a button carrying the .clear class the chrome reset targets', () => {
      const fixture = mount();
      typeInto(fixture, 'cats');

      const clearButton: HTMLButtonElement = fixture.debugElement.query(
        By.css('.clear'),
      ).nativeElement;

      expect(clearButton.tagName).toBe('BUTTON');
      expect(clearButton.getAttribute('type')).toBe('button');
      expect(clearButton.classList).toContain('clear');
    });
  });

  describe('the / shortcut (#408)', () => {
    function pressSlash(target: EventTarget, modifiers: Partial<KeyboardEventInit> = {}) {
      const event = new KeyboardEvent('keydown', {
        key: '/',
        bubbles: true,
        cancelable: true,
        ...modifiers,
      });
      Object.defineProperty(event, 'target', { value: target });
      const prevented = jest.spyOn(event, 'preventDefault');
      document.dispatchEvent(event);
      return prevented;
    }

    it('focuses the input on a bare / while focus is on the document body', () => {
      const fixture = mount();
      const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;

      pressSlash(document.body);

      expect(document.activeElement).toBe(input);
    });

    it('does not steal focus, or the key, when already typing in a text input', () => {
      mount();
      const otherInput = document.createElement('input');
      document.body.appendChild(otherInput);
      otherInput.focus();

      const prevented = pressSlash(otherInput);

      expect(document.activeElement).toBe(otherInput);
      expect(prevented).not.toHaveBeenCalled();
      document.body.removeChild(otherInput);
    });

    it('does not steal a / typed into its own input', () => {
      const fixture = mount();
      const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;
      input.focus();

      const prevented = pressSlash(input);

      expect(prevented).not.toHaveBeenCalled();
    });

    it.each(['ctrlKey', 'metaKey', 'altKey'] as const)(
      'does nothing when %s is held',
      (modifierKey) => {
        const fixture = mount();
        const input: HTMLInputElement = fixture.debugElement.query(By.css('input')).nativeElement;

        const prevented = pressSlash(document.body, { [modifierKey]: true });

        expect(document.activeElement).not.toBe(input);
        expect(prevented).not.toHaveBeenCalled();
      },
    );
  });
});
