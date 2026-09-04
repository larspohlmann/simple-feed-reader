import { brightnessKey, clampBrightness } from './brightness';

describe('clampBrightness', () => {
  it('keeps a value inside the dark range', () => {
    expect(clampBrightness('dark', 2)).toBe(2);
    expect(clampBrightness('dark', -3)).toBe(-3);
  });

  it('caps dark at +3 and light at 0', () => {
    expect(clampBrightness('dark', 9)).toBe(3);
    expect(clampBrightness('light', 2)).toBe(0);
  });

  it('floors dark at -3 and light at -6', () => {
    expect(clampBrightness('light', -9)).toBe(-6);
    expect(clampBrightness('dark', -7)).toBe(-3);
  });

  it('reads a non-number as the default', () => {
    expect(clampBrightness('dark', Number.NaN)).toBe(0);
    expect(clampBrightness('dark', Number.POSITIVE_INFINITY)).toBe(0);
  });

  it('truncates a fraction', () => {
    expect(clampBrightness('dark', 1.7)).toBe(1);
  });
});

describe('brightnessKey', () => {
  it('names one key per theme', () => {
    expect(brightnessKey('light')).toBe('sfr.brightness.light');
    expect(brightnessKey('dark')).toBe('sfr.brightness.dark');
  });
});
