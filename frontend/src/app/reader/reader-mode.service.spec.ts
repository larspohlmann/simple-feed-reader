import { ReaderModeService } from './reader-mode.service';

describe('ReaderModeService', () => {
  it('starts in reader mode with toggling disabled', () => {
    const s = new ReaderModeService();
    expect(s.mode()).toBe('reader');
    expect(s.canToggle()).toBe(false);
  });

  it('enableToggle allows switching between reader and original', () => {
    const s = new ReaderModeService();
    s.enableToggle();
    expect(s.canToggle()).toBe(true);
    s.toggle();
    expect(s.mode()).toBe('original');
    s.toggle();
    expect(s.mode()).toBe('reader');
  });

  it('does not toggle while disabled', () => {
    const s = new ReaderModeService();
    s.toggle();
    expect(s.mode()).toBe('reader');
  });

  it('reset returns to reader mode with toggling disabled', () => {
    const s = new ReaderModeService();
    s.enableToggle();
    s.toggle();
    s.reset();
    expect(s.mode()).toBe('reader');
    expect(s.canToggle()).toBe(false);
  });

  it('setOriginalOnly shows original with toggling disabled', () => {
    const s = new ReaderModeService();
    s.setOriginalOnly();
    expect(s.mode()).toBe('original');
    expect(s.canToggle()).toBe(false);
  });

  it('presents the reader view when a retry recovers from a failed extraction', () => {
    const s = new ReaderModeService();
    s.setOriginalOnly();
    s.enableToggle();
    expect(s.mode()).toBe('reader');
    expect(s.canToggle()).toBe(true);
  });

  it('leaves the chosen view alone when a reload succeeds while toggling is already allowed', () => {
    const s = new ReaderModeService();
    s.enableToggle();
    s.toggle(); // the reader chose the original view
    s.enableToggle(); // a reload succeeds again
    expect(s.mode()).toBe('original');
  });
});
