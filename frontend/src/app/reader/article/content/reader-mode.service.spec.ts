import { ReaderModeService } from './reader-mode.service';

describe('ReaderModeService', () => {
  it('starts in reader mode with toggling disabled', () => {
    const service = new ReaderModeService();
    expect(service.mode()).toBe('reader');
    expect(service.canToggle()).toBe(false);
  });

  it('enableToggle allows switching between reader and original', () => {
    const service = new ReaderModeService();
    service.enableToggle();
    expect(service.canToggle()).toBe(true);
    service.toggle();
    expect(service.mode()).toBe('original');
    service.toggle();
    expect(service.mode()).toBe('reader');
  });

  it('does not toggle while disabled', () => {
    const service = new ReaderModeService();
    service.toggle();
    expect(service.mode()).toBe('reader');
  });

  it('reset returns to reader mode with toggling disabled', () => {
    const service = new ReaderModeService();
    service.enableToggle();
    service.toggle();
    service.reset();
    expect(service.mode()).toBe('reader');
    expect(service.canToggle()).toBe(false);
  });

  it('setOriginalOnly shows original with toggling disabled', () => {
    const service = new ReaderModeService();
    service.setOriginalOnly();
    expect(service.mode()).toBe('original');
    expect(service.canToggle()).toBe(false);
  });

  it('presents the reader view when a retry recovers from a failed extraction', () => {
    const service = new ReaderModeService();
    service.setOriginalOnly();
    service.enableToggle();
    expect(service.mode()).toBe('reader');
    expect(service.canToggle()).toBe(true);
  });

  it('leaves the chosen view alone when a reload succeeds while toggling is already allowed', () => {
    const service = new ReaderModeService();
    service.enableToggle();
    service.toggle(); // the reader chose the original view
    service.enableToggle(); // a reload succeeds again
    expect(service.mode()).toBe('original');
  });
});
