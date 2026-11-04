import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { TrendingWindowSwitchComponent } from './trending-window-switch.component';

function mount() {
  TestBed.configureTestingModule({
    imports: [TrendingWindowSwitchComponent, provideTranslocoTesting()],
    providers: [provideAccountIdentity(signal(6))],
  });
  const fixture = TestBed.createComponent(TrendingWindowSwitchComponent);
  fixture.detectChanges();
  return fixture;
}

const option = (fixture: ReturnType<typeof mount>, label: string) =>
  [
    ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('.seg button'),
  ].find((button) => button.textContent!.trim() === label)!;

describe('TrendingWindowSwitchComponent', () => {
  beforeEach(() => localStorage.clear());

  it('shows both windows, the past 24 hours pressed by default', () => {
    const fixture = mount();
    const group = (fixture.nativeElement as HTMLElement).querySelector('.seg')!;

    expect(group.getAttribute('aria-label')).toBe('Time window');
    expect(option(fixture, '24 h').getAttribute('aria-pressed')).toBe('true');
    expect(option(fixture, '7 days').getAttribute('aria-pressed')).toBe('false');
  });

  it('picks the past 7 days and stores it for the account', () => {
    const fixture = mount();

    option(fixture, '7 days').click();
    fixture.detectChanges();

    expect(option(fixture, '7 days').getAttribute('aria-pressed')).toBe('true');
    expect(option(fixture, '24 h').getAttribute('aria-pressed')).toBe('false');
    expect(localStorage.getItem('sfr.user.6.trending-window')).toBe('week');
  });

  it('picks the day again', () => {
    localStorage.setItem('sfr.user.6.trending-window', 'week');
    const fixture = mount();

    option(fixture, '24 h').click();
    fixture.detectChanges();

    expect(localStorage.getItem('sfr.user.6.trending-window')).toBeNull();
    expect(option(fixture, '24 h').getAttribute('aria-pressed')).toBe('true');
  });
});
