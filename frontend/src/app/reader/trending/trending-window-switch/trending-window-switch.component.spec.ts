import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TrendingWindow } from '../trending.models';
import { TrendingWindowSwitchComponent } from './trending-window-switch.component';

function mount(window: TrendingWindow) {
  TestBed.configureTestingModule({
    imports: [TrendingWindowSwitchComponent, provideTranslocoTesting()],
    providers: [provideRouter([])],
  });
  const fixture = TestBed.createComponent(TrendingWindowSwitchComponent);
  fixture.componentRef.setInput('window', window);
  fixture.detectChanges();
  return fixture;
}

const option = (fixture: ReturnType<typeof mount>, label: string) =>
  [...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('.seg a')].find(
    (link) => link.textContent!.trim() === label,
  )!;

describe('TrendingWindowSwitchComponent', () => {
  it('shows both windows as links, the one on screen current', () => {
    const fixture = mount('day');
    const group = (fixture.nativeElement as HTMLElement).querySelector('.seg')!;

    expect(group.getAttribute('aria-label')).toBe('Time window');
    expect(option(fixture, '24 h').getAttribute('aria-current')).toBe('true');
    expect(option(fixture, '7 days').getAttribute('aria-current')).toBeNull();
  });

  it('links the week by its window and the day by none (D40)', () => {
    const fixture = mount('week');

    expect(option(fixture, '7 days').getAttribute('href')).toBe('/?window=week');
    expect(option(fixture, '7 days').getAttribute('aria-current')).toBe('true');
    expect(option(fixture, '24 h').getAttribute('href')).toBe('/');
  });
});
