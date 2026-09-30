import { ComponentFixture, TestBed } from '@angular/core/testing';
import { LoadingOverlayComponent } from './loading-overlay.component';

describe('LoadingOverlayComponent', () => {
  function mount(): ComponentFixture<LoadingOverlayComponent> {
    const fixture = TestBed.createComponent(LoadingOverlayComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('is decorative and hidden until shown', () => {
    const fixture = mount();
    const host = fixture.nativeElement as HTMLElement;
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.classList).not.toContain('shown');
  });

  it('carries the shown class only while shown', () => {
    const fixture = mount();
    fixture.componentRef.setInput('shown', true);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).classList).toContain('shown');

    fixture.componentRef.setInput('shown', false);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).classList).not.toContain('shown');
  });

  it('renders the caption only when a label is given', () => {
    const fixture = mount();
    expect((fixture.nativeElement as HTMLElement).querySelector('.label')).toBeNull();

    fixture.componentRef.setInput('label', 'Loading…');
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.label')!.textContent).toContain(
      'Loading…',
    );
  });

  it('sizes the spinner from the input', () => {
    const fixture = mount();
    fixture.componentRef.setInput('spinnerSize', 42);
    fixture.detectChanges();
    const svg = (fixture.nativeElement as HTMLElement).querySelector('app-spinner svg')!;
    expect(svg.getAttribute('width')).toBe('42');
  });
});
