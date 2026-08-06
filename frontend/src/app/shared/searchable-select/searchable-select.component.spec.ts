import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SearchableSelectComponent } from './searchable-select.component';

describe('SearchableSelectComponent', () => {
  function mount(
    options = [
      { value: 'gpt-4o', label: 'gpt-4o' },
      { value: 'gpt-4o-mini', label: 'gpt-4o-mini' },
      { value: 'claude-sonnet', label: 'claude-sonnet' },
    ],
  ) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(SearchableSelectComponent);
    fixture.componentRef.setInput('options', options);
    fixture.componentRef.setInput('inputId', 'model-select');
    fixture.detectChanges();
    return fixture;
  }

  function open(fixture: ReturnType<typeof mount>) {
    const trigger = fixture.nativeElement.querySelector('.trigger') as HTMLButtonElement;
    trigger.click();
    fixture.detectChanges();
  }

  function type(fixture: ReturnType<typeof mount>, text: string) {
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;
    search.value = text;
    search.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function optionLabels(fixture: ReturnType<typeof mount>): string[] {
    return Array.from(fixture.nativeElement.querySelectorAll('[role="option"]')).map((el) =>
      (el as HTMLElement).textContent!.trim(),
    );
  }

  it('shows no list until it is opened', () => {
    const fixture = mount();
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });

  it('lists every option when opened', () => {
    const fixture = mount();
    open(fixture);
    expect(optionLabels(fixture)).toEqual(['gpt-4o', 'gpt-4o-mini', 'claude-sonnet']);
  });

  it('filters the list on the typed text, ignoring case', () => {
    const fixture = mount();
    open(fixture);
    type(fixture, 'MINI');
    expect(optionLabels(fixture)).toEqual(['gpt-4o-mini']);
  });

  it('reports when the filter matches nothing', () => {
    const fixture = mount();
    open(fixture);
    type(fixture, 'llama');
    expect(optionLabels(fixture)).toEqual([]);
    expect(fixture.nativeElement.querySelector('.empty')).not.toBeNull();
  });

  it('emits the value of a clicked option and closes', () => {
    const fixture = mount();
    open(fixture);
    (fixture.nativeElement.querySelectorAll('[role="option"]')[1] as HTMLElement).click();
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('gpt-4o-mini');
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });

  it('moves the active option with the arrow keys and takes it on Enter', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('gpt-4o-mini');
  });

  it('keeps the active option inside the filtered list', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    type(fixture, 'claude');
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBe('claude-sonnet');
  });

  it('closes on Escape without choosing', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
    expect(fixture.componentInstance.value()).toBeNull();
  });

  it('does not open while disabled', () => {
    const fixture = mount();
    fixture.componentRef.setInput('disabled', true);
    fixture.detectChanges();
    open(fixture);
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).toBeNull();
  });

  it('does not select or throw when Enter is pressed after the highlighted option is filtered away', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    // Highlight claude-sonnet (index 2), then filter it out entirely -- a
    // missing activeIndex reset would leave it pointing past the shrunk
    // matches array instead of at nothing.
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();
    type(fixture, 'nonexistent-model');

    expect(() =>
      search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })),
    ).not.toThrow();
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBeNull();
  });

  it('does not select or throw when Enter is pressed with zero matches from the start', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;
    type(fixture, 'nonexistent-model');

    expect(() =>
      search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })),
    ).not.toThrow();
    fixture.detectChanges();

    expect(fixture.componentInstance.value()).toBeNull();
  });

  it('reports the highlighted option via aria-activedescendant, following the arrow keys', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;
    const options = fixture.nativeElement.querySelectorAll('[role="option"]');

    expect(search.getAttribute('role')).toBe('combobox');
    expect(search.getAttribute('aria-activedescendant')).toBe((options[0] as HTMLElement).id);

    search.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    fixture.detectChanges();

    expect(search.getAttribute('aria-activedescendant')).toBe((options[1] as HTMLElement).id);
  });

  it('has no aria-activedescendant while there is no open list to point at', () => {
    const fixture = mount();
    expect(fixture.nativeElement.querySelector('.search')).toBeNull();

    open(fixture);
    type(fixture, 'nonexistent-model');
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    expect(search.getAttribute('aria-activedescendant')).toBeNull();
  });

  it('points aria-controls at the open listbox', () => {
    const fixture = mount();
    open(fixture);
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;
    const listbox = fixture.nativeElement.querySelector('[role="listbox"]') as HTMLElement;

    expect(search.getAttribute('aria-expanded')).toBe('true');
    expect(search.getAttribute('aria-controls')).toBe(listbox.id);
  });

  it('never points aria-controls at an element that is not actually rendered', () => {
    const fixture = mount();
    open(fixture);
    type(fixture, 'nonexistent-model');
    const search = fixture.nativeElement.querySelector('.search') as HTMLInputElement;

    const controlledId = search.getAttribute('aria-controls');
    expect(controlledId).not.toBeNull();

    const controlledElement = fixture.nativeElement.querySelector(`#${controlledId}`);
    expect(controlledElement).not.toBeNull();
    expect(controlledElement.getAttribute('role')).toBe('listbox');
  });

  it('gives two instances on one page distinct listbox and option ids', () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });

    const first = TestBed.createComponent(SearchableSelectComponent);
    first.componentRef.setInput('options', [{ value: 'a', label: 'a' }]);
    first.componentRef.setInput('inputId', 'model-select');
    first.detectChanges();
    open(first);

    const second = TestBed.createComponent(SearchableSelectComponent);
    second.componentRef.setInput('options', [{ value: 'a', label: 'a' }]);
    second.componentRef.setInput('inputId', 'fallback-select');
    second.detectChanges();
    open(second);

    const firstListbox = first.nativeElement.querySelector('[role="listbox"]') as HTMLElement;
    const secondListbox = second.nativeElement.querySelector('[role="listbox"]') as HTMLElement;
    const firstOption = first.nativeElement.querySelector('[role="option"]') as HTMLElement;
    const secondOption = second.nativeElement.querySelector('[role="option"]') as HTMLElement;

    expect(firstListbox.id).not.toBe(secondListbox.id);
    expect(firstOption.id).not.toBe(secondOption.id);
  });
});
