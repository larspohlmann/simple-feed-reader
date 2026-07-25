import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToTopButtonComponent } from './to-top-button.component';

describe('ToTopButtonComponent', () => {
  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [ToTopButtonComponent, provideTranslocoTesting()],
    });
    const f = TestBed.createComponent(ToTopButtonComponent);
    f.detectChanges();
    return f;
  }

  it('renders a labelled button with the up arrow', () => {
    const el = mount().nativeElement as HTMLElement;
    const btn = el.querySelector('button') as HTMLButtonElement;
    expect(btn.getAttribute('aria-label')).toBe('Back to top');
    expect(btn.getAttribute('type')).toBe('button');
    expect(el.querySelector('app-icon')).not.toBeNull();
  });

  it('emits activate when clicked', () => {
    const f = mount();
    const fired = jest.fn();
    f.componentInstance.activate.subscribe(fired);
    (f.nativeElement as HTMLElement).querySelector('button')!.click();
    expect(fired).toHaveBeenCalledTimes(1);
  });
});
