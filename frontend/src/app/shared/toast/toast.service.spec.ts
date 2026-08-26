import { ApplicationRef, ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { CONFIRMATION_DURATION_MS, ToastService } from './toast.service';

describe('ToastService', () => {
  let toast: ToastService;
  let container: HTMLElement;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    toast = TestBed.inject(ToastService);
    container = TestBed.inject(OverlayContainer).getContainerElement();
  });

  const tick = () => TestBed.inject(ApplicationRef).tick();
  const el = () => container.querySelector<HTMLElement>('.toast');

  @Component({
    selector: 'app-toast-test-content',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `<p class="hosted">hosted content</p>`,
  })
  class HostedContentComponent {}

  const root = document.documentElement;
  const toastHeight = () => root.style.getPropertyValue('--app-toast-height');
  const stubToastHeight = (height: number) =>
    jest
      .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
      .mockReturnValue({ height } as DOMRect);

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    root.style.removeProperty('--app-toast-height');
  });

  it('renders the message into the document', () => {
    toast.show({ message: 'Refresh finished' });
    tick();
    expect(el()!.textContent).toContain('Refresh finished');
  });

  it('renders no action button without an actionLabel', () => {
    toast.show({ message: 'Refresh finished' });
    tick();
    expect(el()!.querySelector('.act')).toBeNull();
  });

  it('renders the action button, and clicking it runs the callback and closes the toast', () => {
    const action = jest.fn();
    toast.show({ message: 'Undo the mark-read?', actionLabel: 'Undo', action });
    tick();

    const button = el()!.querySelector<HTMLButtonElement>('.act')!;
    expect(button.textContent).toContain('Undo');
    button.click();
    tick();

    expect(action).toHaveBeenCalledTimes(1);
    expect(container.querySelector('.toast')).toBeNull();
  });

  it('replaces a visible toast with a new one', () => {
    toast.show({ message: 'First' });
    tick();
    expect(container.querySelectorAll('.toast')).toHaveLength(1);

    toast.show({ message: 'Second' });
    tick();

    const toasts = container.querySelectorAll('.toast');
    expect(toasts).toHaveLength(1);
    expect(toasts[0].textContent).toContain('Second');
    expect(toasts[0].textContent).not.toContain('First');
  });

  it('auto-dismisses after the default 6000ms', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Auto-dismiss me' });
    tick();
    expect(el()).not.toBeNull();

    jest.advanceTimersByTime(6000);
    tick();

    expect(el()).toBeNull();
  });

  it('clears a confirmation sooner than a toast on the default duration', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Saved.', durationMs: CONFIRMATION_DURATION_MS });
    tick();

    jest.advanceTimersByTime(CONFIRMATION_DURATION_MS);
    tick();
    expect(el()).toBeNull();

    toast.show({ message: 'Undo the mark-read?' });
    tick();

    jest.advanceTimersByTime(CONFIRMATION_DURATION_MS);
    tick();
    expect(el()).not.toBeNull();
  });

  it('clears the previous timer when replaced, so it cannot dismiss the new toast early', () => {
    jest.useFakeTimers();
    toast.show({ message: 'First', durationMs: 6000 });
    tick();

    jest.advanceTimersByTime(3000);
    toast.show({ message: 'Second', durationMs: 6000 });
    tick();

    jest.advanceTimersByTime(3000);
    tick();
    expect(el()!.textContent).toContain('Second');

    jest.advanceTimersByTime(3000);
    tick();
    expect(el()).toBeNull();
  });

  it('dismiss() closes an open toast and clears its timer', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Dismiss me' });
    tick();

    toast.dismiss();
    tick();
    expect(el()).toBeNull();

    // A no-op second dismiss must not throw.
    expect(() => toast.dismiss()).not.toThrow();
  });

  it('marks the toast region as role=status with aria-live=polite', () => {
    toast.show({ message: 'Announce me' });
    tick();
    expect(el()!.getAttribute('role')).toBe('status');
    expect(el()!.getAttribute('aria-live')).toBe('polite');
  });

  it('positions the toast from the --space-5 token, not a hardcoded literal', () => {
    document.documentElement.style.setProperty('--space-5', '40px');

    toast.show({ message: 'Positioned' });
    tick();

    const pane = container.querySelector<HTMLElement>('.cdk-overlay-pane')!;
    expect(pane.style.marginBottom).toBe('40px');
    document.documentElement.style.removeProperty('--space-5');
  });

  it('never auto-dismisses a toast whose durationMs is null', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Ranking your feeds', durationMs: null });
    tick();

    jest.advanceTimersByTime(600_000);
    tick();

    expect(el()).not.toBeNull();
  });

  it('lets the close button dismiss a persistent toast', () => {
    jest.useFakeTimers();
    toast.show({ message: 'Ranking your feeds', durationMs: null });
    tick();

    el()!.querySelector<HTMLButtonElement>('.close')!.click();
    tick();

    expect(el()).toBeNull();
  });

  it('renders a content toast through the component outlet instead of a message', () => {
    toast.show({ content: HostedContentComponent });
    tick();

    expect(el()!.querySelector('.hosted')!.textContent).toBe('hosted content');
  });

  it('tracks whether a toast is on screen, and stays visible across a replacement', () => {
    expect(toast.visible()).toBe(false);

    toast.show({ message: 'First' });
    tick();
    expect(toast.visible()).toBe(true);

    // The replacement opens before the outgoing ref reports itself closed.
    toast.show({ message: 'Second' });
    tick();
    expect(toast.visible()).toBe(true);

    toast.dismiss();
    tick();
    expect(toast.visible()).toBe(false);
  });

  it('publishes the live toast height as --app-toast-height while it is on screen', () => {
    stubToastHeight(118);
    expect(toastHeight()).toBe('');

    toast.show({ message: 'Ranking your feeds', durationMs: null });
    tick();
    expect(toastHeight()).toBe('118px');

    toast.dismiss();
    tick();
    expect(toastHeight()).toBe('');
  });

  it('keeps the height published across a replacement, so the offset never strands', () => {
    stubToastHeight(90);
    toast.show({ message: 'First' });
    tick();
    expect(toastHeight()).toBe('90px');

    // The replacement opens before the outgoing ref reports itself closed; its
    // closed handler must not clear the property out from under the new toast.
    toast.show({ message: 'Second' });
    tick();
    expect(toastHeight()).toBe('90px');
  });

  it('marks the pane fixed-width only when the toast asks for it', () => {
    toast.show({ message: 'Fits its content' });
    tick();
    expect(container.querySelector('.cdk-overlay-pane')!.classList).not.toContain(
      'app-toast--fixed',
    );

    toast.show({ message: 'Holds one width', width: 'fixed' });
    tick();
    expect(container.querySelector('.cdk-overlay-pane')!.classList).toContain('app-toast--fixed');
  });
});
