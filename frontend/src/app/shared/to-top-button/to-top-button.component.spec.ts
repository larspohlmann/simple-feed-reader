import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { ToTopButtonComponent } from './to-top-button.component';

describe('ToTopButtonComponent', () => {
  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [ToTopButtonComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(ToTopButtonComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('renders a labelled button with the up arrow', () => {
    const element = mount().nativeElement as HTMLElement;
    const button = element.querySelector('button') as HTMLButtonElement;
    expect(button.getAttribute('aria-label')).toBe('Back to top');
    expect(button.getAttribute('type')).toBe('button');
    expect(element.querySelector('app-icon')).not.toBeNull();
  });

  it('emits activate when clicked', () => {
    const fixture = mount();
    const fired = jest.fn();
    fixture.componentInstance.activate.subscribe(fired);
    (fixture.nativeElement as HTMLElement).querySelector('button')!.click();
    expect(fired).toHaveBeenCalledTimes(1);
  });
});
