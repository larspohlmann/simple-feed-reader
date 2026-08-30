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
    const f = TestBed.createComponent(HostComponent);
    f.detectChanges();
    return f;
  }

  function buttons(f: ReturnType<typeof create>): HTMLButtonElement[] {
    return Array.from(f.nativeElement.querySelectorAll('button'));
  }

  it('renders one translated button per option', () => {
    expect(buttons(create()).map((b) => b.textContent?.trim())).toEqual(['English', 'German']);
  });

  it('names the group for assistive tech', () => {
    const group = create().nativeElement.querySelector('[role="group"]') as HTMLElement;
    expect(group.getAttribute('aria-label')).toBe('Language');
  });

  it('marks only the selected option', () => {
    const f = create();
    expect(buttons(f).map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false']);

    f.componentInstance.selected.set('de');
    f.detectChanges();
    expect(buttons(f).map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'true']);
  });

  it('emits the option that was clicked', () => {
    const f = create();
    buttons(f)[1].click();
    expect(f.componentInstance.picked).toBe('de');
  });
});
