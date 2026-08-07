import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { OverlayContainer } from '@angular/cdk/overlay';
import { ToastService } from './toast.service';

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

  afterEach(() => jest.useRealTimers());

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
});
