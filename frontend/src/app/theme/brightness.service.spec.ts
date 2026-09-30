import { TestBed } from '@angular/core/testing';
import { BrightnessService } from './brightness.service';
import { ThemeService } from './theme.service';

describe('BrightnessService', () => {
  const attribute = () => document.documentElement.getAttribute('data-brightness');

  beforeEach(() => {
    localStorage.clear();
    localStorage.setItem('sfr.theme', 'dark');
    document.documentElement.removeAttribute('data-brightness');
  });

  function create(): BrightnessService {
    const service = TestBed.inject(BrightnessService);
    TestBed.tick();
    return service;
  }

  it('starts at the default and writes it to the root element', () => {
    const service = create();
    expect(service.step()).toBe(0);
    expect(attribute()).toBe('0');
  });

  it('reads the saved step of the resolved theme only', () => {
    localStorage.setItem('sfr.brightness.dark', '-2');
    localStorage.setItem('sfr.brightness.light', '1');
    const service = create();
    expect(service.step()).toBe(-2);
    expect(attribute()).toBe('-2');
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
    const service = create();
    service.increase();
    TestBed.tick();
    expect(service.step()).toBe(1);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('1');
    expect(localStorage.getItem('sfr.brightness.light')).toBeNull();
    expect(attribute()).toBe('1');
  });

  it('stops at both ends of the range', () => {
    const service = create();
    for (let index = 0; index < 5; index++) service.decrease();
    expect(service.step()).toBe(-3);
    for (let index = 0; index < 8; index++) service.increase();
    expect(service.step()).toBe(3);
  });

  it('resets to the default', () => {
    const service = create();
    service.set(2);
    service.reset();
    expect(service.step()).toBe(0);
    expect(localStorage.getItem('sfr.brightness.dark')).toBe('0');
  });

  it("switches to the other theme's step and range when the theme changes", () => {
    localStorage.setItem('sfr.brightness.light', '-2');
    const service = create();
    expect(service.max()).toBe(3);
    expect(service.min()).toBe(-3);

    TestBed.inject(ThemeService).setMode('light');
    TestBed.tick();

    expect(service.step()).toBe(-2);
    expect(service.max()).toBe(0);
    expect(service.min()).toBe(-6);
    expect(attribute()).toBe('-2');
  });

  it('caps light mode at the default and floors it at -6', () => {
    localStorage.setItem('sfr.theme', 'light');
    const service = create();
    service.set(3);
    expect(service.step()).toBe(0);
    service.set(-9);
    expect(service.step()).toBe(-6);
  });
});
