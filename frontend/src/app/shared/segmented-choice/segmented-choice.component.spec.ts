import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Params, provideRouter } from '@angular/router';
import { SegmentedChoiceComponent } from './segmented-choice.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

@Component({
  imports: [SegmentedChoiceComponent],
  template: `<app-segmented-choice
    [options]="['en', 'de']"
    [selected]="selected()"
    [disabledOptions]="disabled()"
    [describedBy]="describedBy()"
    [counts]="counts()"
    [links]="links()"
    ariaLabelKey="lang.label"
    labelPrefix="lang."
    (pick)="picked = $event"
  />`,
})
class HostComponent {
  readonly selected = signal<'en' | 'de'>('en');
  readonly disabled = signal<readonly ('en' | 'de')[]>([]);
  readonly describedBy = signal<string | null>(null);
  readonly counts = signal<Partial<Record<'en' | 'de', number>>>({});
  readonly links = signal<Readonly<Record<'en' | 'de', Params>> | null>(null);
  picked: string | null = null;
}

describe('SegmentedChoiceComponent', () => {
  function create() {
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
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

  it('points the group at the text that describes it, and at nothing without one', () => {
    const fixture = create();
    const group = fixture.nativeElement.querySelector('[role="group"]') as HTMLElement;
    expect(group.hasAttribute('aria-describedby')).toBe(false);

    fixture.componentInstance.describedBy.set('why-disabled');
    fixture.detectChanges();
    expect(group.getAttribute('aria-describedby')).toBe('why-disabled');
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

  it('shows a count only after the options that have one, zero included', () => {
    const fixture = create();
    fixture.componentInstance.counts.set({ de: 0 });
    fixture.detectChanges();

    expect(buttons(fixture).map((button) => button.querySelector('.count')?.textContent)).toEqual([
      undefined,
      '0',
    ]);
  });

  it('disables only the options it is told to, and a disabled one emits nothing', () => {
    const fixture = create();
    expect(buttons(fixture).map((button) => button.disabled)).toEqual([false, false]);

    fixture.componentInstance.disabled.set(['de']);
    fixture.detectChanges();
    buttons(fixture)[1].click();

    expect(buttons(fixture).map((button) => button.disabled)).toEqual([false, true]);
    expect(fixture.componentInstance.picked).toBeNull();
  });

  describe('as links (#2017, D40)', () => {
    function linked() {
      const fixture = create();
      fixture.componentInstance.links.set({ en: { lang: null }, de: { lang: 'de' } });
      fixture.detectChanges();
      return fixture;
    }

    const links = (fixture: ReturnType<typeof create>): HTMLAnchorElement[] =>
      Array.from(fixture.nativeElement.querySelectorAll('a'));

    it('renders one translated link per option, and no button', () => {
      const fixture = linked();

      expect(links(fixture).map((link) => link.textContent?.trim())).toEqual(['English', 'German']);
      expect(buttons(fixture)).toEqual([]);
    });

    it("merges each option's query params into the URL", () => {
      expect(links(linked()).map((link) => link.getAttribute('href'))).toEqual(['/', '/?lang=de']);
    });

    it('marks only the selected option as current, in the look of a pressed one', () => {
      const [english, german] = links(linked());

      expect(english.getAttribute('aria-current')).toBe('true');
      expect(english.classList).toContain('on');
      expect(german.hasAttribute('aria-current')).toBe(false);
      expect(german.classList).not.toContain('on');
    });
  });
});
