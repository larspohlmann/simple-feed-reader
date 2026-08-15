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

  it('carries role="search" on its wrapper', () => {
    const fixture = mount();
    const wrapper = fixture.debugElement.query(By.css('[role="search"]'));
    expect(wrapper).toBeTruthy();
  });
});
