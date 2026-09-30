import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SegmentedChoiceComponent } from './segmented-choice.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

@Component({
  imports: [SegmentedChoiceComponent],
  template: `<app-segmented-choice
    [options]="['en', 'de']"
    [selected]="selected()"
    ariaLabelKey="lang.label"
    labelPrefix="lang."
    (pick)="picked = $event"
  />`,
})
class HostComponent {
  readonly selected = signal<'en' | 'de'>('en');
  picked: string | null = null;
}

describe('SegmentedChoiceComponent', () => {
  function create() {
    TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  function buttons(fixture: ReturnType<typeof create>): HTMLButtonElement[] {
    return Array.from(fixture.nativeElement.querySelectorAll('button'));
  }

  it('renders one translated button per option', () => {
    expect(buttons(create()).map((button) => button.textContent?.trim())).toEqual([
      'English',
      'German',
    ]);
  });

  it('names the group for assistive tech', () => {
    const group = create().nativeElement.querySelector('[role="group"]') as HTMLElement;
    expect(group.getAttribute('aria-label')).toBe('Language');
  });

  it('marks only the selected option', () => {
    const fixture = create();
    expect(buttons(fixture).map((button) => button.getAttribute('aria-pressed'))).toEqual([
      'true',
      'false',
    ]);

    fixture.componentInstance.selected.set('de');
    fixture.detectChanges();
    expect(buttons(fixture).map((button) => button.getAttribute('aria-pressed'))).toEqual([
      'false',
      'true',
    ]);
  });

  it('emits the option that was clicked', () => {
    const fixture = create();
    buttons(fixture)[1].click();
    expect(fixture.componentInstance.picked).toBe('de');
  });
});
