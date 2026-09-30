import { isSignal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ThemeService } from './theme.service';

describe('ThemeService', () => {
  const attribute = () => document.documentElement.getAttribute('data-theme');
  let mql: { matches: boolean; addEventListener: jest.Mock };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    mql = { matches: false, addEventListener: jest.fn() };
    window.matchMedia = jest.fn().mockReturnValue(mql) as unknown as typeof window.matchMedia;
  });

  it('defaults to the system preference when nothing is saved (light)', () => {
    const service = TestBed.inject(ThemeService);
    expect(service.mode()).toBe('system');
    expect(attribute()).toBe('light');
  });

  it('resolves system=dark from prefers-color-scheme', () => {
    mql.matches = true;
    TestBed.inject(ThemeService);
    expect(attribute()).toBe('dark');
  });

  it('applies and persists an explicit choice', () => {
    const service = TestBed.inject(ThemeService);
    service.setMode('dark');
    expect(attribute()).toBe('dark');
    expect(localStorage.getItem('sfr.theme')).toBe('dark');
  });

  it('a saved choice wins over system on construction', () => {
    localStorage.setItem('sfr.theme', 'dark');
    TestBed.inject(ThemeService);
    expect(attribute()).toBe('dark');
  });

  it('exposes the resolved theme as a signal', () => {
    const service = TestBed.inject(ThemeService);
    expect(isSignal(service.resolved)).toBe(true);
    expect(service.resolved()).toBe('light');

    service.setMode('dark');

    expect(service.resolved()).toBe('dark');
  });

  it('re-resolves when the OS scheme flips under system mode', () => {
    const service = TestBed.inject(ThemeService);
    mql.matches = true;
    const onChange = mql.addEventListener.mock.calls[0][1] as () => void;

    onChange();

    expect(service.resolved()).toBe('dark');
    expect(attribute()).toBe('dark');
  });
});
