import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Problem } from '../../../core/problem';
import { installMockResizeObserver, resizeTo } from '../../../../testing/resize-observer-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ListFrameComponent } from './list-frame.component';

@Component({
  imports: [ListFrameComponent],
  template: `
    <app-list-frame
      [problem]="problem()"
      [collapsed]="collapsed()"
      (retry)="retries = retries + 1"
      (dismiss)="dismissals = dismissals + 1"
    >
      <h2 frameHeader>Title</h2>
      <p class="row">Row</p>
      <span frameTail>Corner</span>
    </app-list-frame>
  `,
})
class FrameHost {
  readonly problem = signal<Problem | null>(null);
  readonly collapsed = signal(false);
  retries = 0;
  dismissals = 0;
}

function mount() {
  installMockResizeObserver();
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(FrameHost);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  return { fixture, element, host: fixture.componentInstance };
}

function problemWith(status: number): Problem {
  return { type: 'about:blank', title: 'Failed', status };
}

function resizeHeaderTo(element: HTMLElement, height: number): void {
  resizeTo(element.querySelector<HTMLElement>('.list-header')!, height);
}

describe('ListFrameComponent', () => {
  it('projects the header into the floating header', () => {
    const { element } = mount();

    expect(
      element.querySelector('.list-frame.lead > app-pane-bar.list-header > h2'),
    ).not.toBeNull();
  });

  it('projects rows between the frames and the tail into the tail frame', () => {
    const { element } = mount();

    expect(element.querySelector('.list-frame.tail > span')?.textContent).toBe('Corner');
    expect(element.querySelector('p.row')!.closest('.list-frame')).toBeNull();
  });

  it('shows the offline message for a status-0 problem', () => {
    const { fixture, element, host } = mount();
    host.problem.set(problemWith(0));
    fixture.detectChanges();

    expect(element.querySelector('app-error-banner')!.textContent).toContain(
      'Could not reach the server',
    );
  });

  it('shows the server message with the status', () => {
    const { fixture, element, host } = mount();
    host.problem.set(problemWith(503));
    fixture.detectChanges();

    expect(element.querySelector('app-error-banner')!.textContent).toContain('503');
  });

  it('emits retry and dismiss from the banner', () => {
    const { fixture, element, host } = mount();
    host.problem.set(problemWith(503));
    fixture.detectChanges();

    element.querySelector<HTMLButtonElement>('app-error-banner .action')!.click();
    element.querySelector<HTMLButtonElement>('app-error-banner .dismiss')!.click();

    expect([host.retries, host.dismissals]).toEqual([1, 1]);
  });

  it('marks the header collapsed', () => {
    const { fixture, element, host } = mount();
    host.collapsed.set(true);
    fixture.detectChanges();

    expect(element.querySelector('.list-header')!.classList).toContain('collapsed');
  });

  it('publishes the header height only while expanded', () => {
    const { fixture, element, host } = mount();
    const frame = element.querySelector<HTMLElement>('app-list-frame')!;
    resizeHeaderTo(element, 40);
    expect(frame.style.getPropertyValue('--list-bar-h')).toBe('40px');

    host.collapsed.set(true);
    fixture.detectChanges();
    resizeHeaderTo(element, 20);

    expect(frame.style.getPropertyValue('--list-bar-h')).toBe('40px');
  });
});
