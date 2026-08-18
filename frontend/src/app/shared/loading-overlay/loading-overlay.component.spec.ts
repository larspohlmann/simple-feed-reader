import { ComponentFixture, TestBed } from '@angular/core/testing';
import { LoadingOverlayComponent } from './loading-overlay.component';

describe('LoadingOverlayComponent', () => {
  function mount(): ComponentFixture<LoadingOverlayComponent> {
    const f = TestBed.createComponent(LoadingOverlayComponent);
    f.detectChanges();
    return f;
  }

  it('is decorative and hidden until shown', () => {
    const f = mount();
    const host = f.nativeElement as HTMLElement;
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.classList).not.toContain('shown');
  });

  it('carries the shown class only while shown', () => {
    const f = mount();
    f.componentRef.setInput('shown', true);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).classList).toContain('shown');

    f.componentRef.setInput('shown', false);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).classList).not.toContain('shown');
  });

  it('renders the caption only when a label is given', () => {
    const f = mount();
    expect((f.nativeElement as HTMLElement).querySelector('.label')).toBeNull();

    f.componentRef.setInput('label', 'Loading…');
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('.label')!.textContent).toContain(
      'Loading…',
    );
  });

  it('sizes the spinner from the input', () => {
    const f = mount();
    f.componentRef.setInput('spinnerSize', 42);
    f.detectChanges();
    const svg = (f.nativeElement as HTMLElement).querySelector('app-spinner svg')!;
    expect(svg.getAttribute('width')).toBe('42');
  });
});
