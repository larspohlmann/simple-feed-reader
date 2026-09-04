import { TestBed } from '@angular/core/testing';
import { BrightnessService } from './brightness.service';
import { ThemeService } from './theme.service';

describe('BrightnessService', () => {
  const attr = () => document.documentElement.getAttribute('data-brightness');

  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('sfr.theme', 'dark');
    document.documentElement.removeAttribute('data-brightness');
  });

  function create(): BrightnessService {
    const svc = TestBed.inject(BrightnessService);
    TestBed.tick();
    return svc;
  }

  it('starts at the default and writes it to the root element', () => {
    const svc = create();
    expect(svc.step()).toBe(0);
    expect(attr()).toBe('0');
  });

  it('reads the saved step of the resolved theme only', () => {
    localStorage.setItem('sfr.brightness.dark', '-2');
    localStorage.setItem('sfr.brightness.light', '1');
    const svc = create();
    expect(svc.step()).toBe(-2);
    expect(attr()).toBe('-2');
  });

  it('reads a corrupt saved value as the default', () => {
    localStorage.setItem('sfr.brightness.dark', 'bright');
    expect(create().step()).toBe(0);
  });

  it('clamps an out-of-range saved value', () => {
    localStorage.setItem('sfr.brightness.dark', '9');
    expect(create().step()).toBe(3);
  });

  it("steps up and persists under the theme's own key", () => {
    const svc = create();
    svc.increase();
    TestBed.tick();
    expect(svc.step()).toBe(1);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('1');
    expect(localStorage.getItem('sfr.brightness.light')).toBeNull();
    expect(attr()).toBe('1');
  });

  it('stops at both ends of the range', () => {
    const svc = create();
    for (let i = 0; i < 5; i++) svc.decrease();
    expect(svc.step()).toBe(-3);
    for (let i = 0; i < 8; i++) svc.increase();
    expect(svc.step()).toBe(3);
  });

  it('resets to the default', () => {
    const svc = create();
    svc.set(2);
    svc.reset();
    expect(svc.step()).toBe(0);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('0');
  });

  it("switches to the other theme's step and range when the theme changes", () => {
    localStorage.setItem('sfr.brightness.light', '-2');
    const svc = create();
    expect(svc.max()).toBe(3);
    expect(svc.min()).toBe(-3);

    TestBed.inject(ThemeService).setMode('light');
    TestBed.tick();

    expect(svc.step()).toBe(-2);
    expect(svc.max()).toBe(0);
    expect(svc.min()).toBe(-6);
    expect(attr()).toBe('-2');
  });

  it('caps light mode at the default and floors it at -6', () => {
    localStorage.setItem('sfr.theme', 'light');
    const svc = create();
    svc.set(3);
    expect(svc.step()).toBe(0);
    svc.set(-9);
    expect(svc.step()).toBe(-6);
  });
});
