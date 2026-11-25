import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FileDropComponent } from './file-drop.component';
import { matchesAccept } from './matches-accept';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

@Component({
  imports: [FileDropComponent],
  template: `<app-file-drop
    [accept]="accept()"
    label="Drop an OPML file"
    [disabled]="disabled()"
    (picked)="received.push($event)"
  />`,
})
class HostComponent {
  readonly accept = signal('.opml,text/xml');
  readonly disabled = signal(false);
  readonly received: File[] = [];
}

@Component({
  imports: [FileDropComponent],
  template: `
    <app-file-drop accept=".opml" label="Drop one" hint="Up to 1 MB" />
    <app-file-drop accept=".zip" label="Drop two" />
  `,
})
class TwoDropsComponent {}

describe('FileDropComponent', () => {
  function create() {
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  function target(fixture: ReturnType<typeof create>): HTMLElement {
    return fixture.nativeElement.querySelector('.target');
  }

  function drop(fixture: ReturnType<typeof create>, files: File[]): void {
    const event = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', { value: { files } });
    target(fixture).dispatchEvent(event);
    fixture.detectChanges();
  }

  const valueWrites: string[] = [];

  function pick(fixture: ReturnType<typeof create>, file: File): HTMLInputElement {
    const input = fixture.nativeElement.querySelector('input[type=file]') as HTMLInputElement;
    Object.defineProperty(input, 'files', {
      configurable: true,
      value: { length: 1, item: () => file },
    });
    Object.defineProperty(input, 'value', {
      configurable: true,
      get: () => '',
      set: (written: string) => valueWrites.push(written),
    });
    input.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    return input;
  }

  beforeEach(() => (valueWrites.length = 0));

  const opml = () => new File(['<opml/>'], 'a.opml');

  describe('accessible name', () => {
    function buttons(): HTMLButtonElement[] {
      TestBed.configureTestingModule({
        imports: [TwoDropsComponent, provideTranslocoTesting()],
      });
      const fixture = TestBed.createComponent(TwoDropsComponent);
      fixture.detectChanges();
      return [...fixture.nativeElement.querySelectorAll('button')];
    }

    function describedText(button: HTMLButtonElement): string {
      return (button.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .map((id) => document.getElementById(id)?.textContent?.trim())
        .join(' | ');
    }

    it('describes the button by the label and the hint', () => {
      const [first] = buttons();

      expect(describedText(first)).toBe('Drop one | Up to 1 MB');
    });

    it('describes the button by the label alone without a hint', () => {
      const [, second] = buttons();

      expect(describedText(second)).toBe('Drop two');
    });

    it('gives each drop zone its own ids', () => {
      const [first, second] = buttons();

      expect(first.getAttribute('aria-describedby')).not.toBe(
        second.getAttribute('aria-describedby'),
      );
    });
  });

  it('emits the file picked through the input', () => {
    const fixture = create();
    const file = opml();
    pick(fixture, file);
    expect(fixture.componentInstance.received).toEqual([file]);
    expect(valueWrites).toEqual(['']);
  });

  it('emits the same file picked twice', () => {
    const fixture = create();
    const file = opml();
    pick(fixture, file);
    pick(fixture, file);
    expect(fixture.componentInstance.received).toEqual([file, file]);
    expect(valueWrites).toEqual(['', '']);
  });

  it('emits the first dropped file that matches', () => {
    const fixture = create();
    fixture.componentInstance.accept.set('.opml');
    fixture.detectChanges();
    const file = opml();
    drop(fixture, [new File([''], 'x.zip'), file]);
    expect(fixture.componentInstance.received).toEqual([file]);
  });

  it('rejects a drop of the wrong type', () => {
    const fixture = create();
    drop(fixture, [new File([''], 'x.zip')]);
    expect(fixture.componentInstance.received).toEqual([]);
    expect(fixture.nativeElement.querySelector('[data-testid=file-drop-error]')).not.toBeNull();
  });

  it('clears the error after a good drop', () => {
    const fixture = create();
    drop(fixture, [new File([''], 'x.zip')]);
    drop(fixture, [opml()]);
    expect(fixture.nativeElement.querySelector('[data-testid=file-drop-error]')).toBeNull();
  });

  it('ignores a drop while disabled', () => {
    const fixture = create();
    fixture.componentInstance.disabled.set(true);
    fixture.detectChanges();
    drop(fixture, [opml()]);
    expect(fixture.componentInstance.received).toEqual([]);
    expect(fixture.nativeElement.querySelector('[data-testid=file-drop-error]')).toBeNull();
  });

  it('marks the target while a file is dragged over it', () => {
    const fixture = create();
    target(fixture).dispatchEvent(new Event('dragenter', { bubbles: true }));
    fixture.detectChanges();
    expect(target(fixture).classList).toContain('is-over');
    target(fixture).dispatchEvent(new Event('dragleave', { bubbles: true }));
    fixture.detectChanges();
    expect(target(fixture).classList).not.toContain('is-over');
  });
});

describe('FileDropComponent drag depth', () => {
  it('stays marked while the drag moves between the target and its children', () => {
    TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    const target = fixture.nativeElement.querySelector('.target') as HTMLElement;
    const child = target.querySelector('button') as HTMLElement;
    const fire = (element: HTMLElement, type: string) => {
      element.dispatchEvent(new Event(type, { bubbles: true }));
      fixture.detectChanges();
    };
    fire(target, 'dragenter');
    fire(child, 'dragenter');
    fire(target, 'dragleave');
    expect(target.classList).toContain('is-over');
    fire(child, 'dragleave');
    expect(target.classList).not.toContain('is-over');
  });
});

describe('matchesAccept', () => {
  it('matches a suffix case-insensitively', () => {
    expect(matchesAccept(new File([''], 'Feeds.OPML'), '.opml')).toBe(true);
  });

  it('matches the MIME type', () => {
    expect(matchesAccept(new File([''], 'f', { type: 'text/xml' }), 'text/xml')).toBe(true);
  });

  it('rejects what neither a suffix nor a type names', () => {
    expect(matchesAccept(new File([''], 'x.zip'), '.opml,text/xml')).toBe(false);
  });

  it('matches everything for an empty accept', () => {
    expect(matchesAccept(new File([''], 'x.zip'), '')).toBe(true);
  });
});
