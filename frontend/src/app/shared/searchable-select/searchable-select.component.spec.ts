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
});
