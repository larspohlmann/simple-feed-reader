// src/app/theme/theme.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { ThemeService } from './theme.service';

describe('ThemeService', () => {
  const attr = () => document.documentElement.getAttribute('data-theme');
  let mql: { matches: boolean; addEventListener: jest.Mock };

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    mql = { matches: false, addEventListener: jest.fn() };
    window.matchMedia = jest.fn().mockReturnValue(mql) as unknown as typeof window.matchMedia;
  });

  it('defaults to the system preference when nothing is saved (light)', () => {
    const svc = TestBed.inject(ThemeService);
    expect(svc.mode()).toBe('system');
    expect(attr()).toBe('light');
  });

  it('resolves system=dark from prefers-color-scheme', () => {
    mql.matches = true;
    TestBed.inject(ThemeService);
    expect(attr()).toBe('dark');
  });

  it('applies and persists an explicit choice', () => {
    const svc = TestBed.inject(ThemeService);
    svc.setMode('dark');
    expect(attr()).toBe('dark');
    expect(localStorage.getItem('sfr.theme')).toBe('dark');
  });

  it('a saved choice wins over system on construction', () => {
    localStorage.setItem('sfr.theme', 'dark');
    TestBed.inject(ThemeService);
    expect(attr()).toBe('dark');
  });
});
