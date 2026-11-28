import { ReadingFocusService } from './reading-focus.service';

describe('ReadingFocusService', () => {
  beforeEach(() => localStorage.clear());

  it('is off for a reader who never chose (#2602)', () => {
    expect(new ReadingFocusService().enabled()).toBe(false);
  });

  it('restores an enabled reading focus from local storage', () => {
    localStorage.setItem('sfr.readingFocus', 'true');

    expect(new ReadingFocusService().enabled()).toBe(true);
  });

  it('restores a disabled reading focus from local storage', () => {
    localStorage.setItem('sfr.readingFocus', 'false');

    expect(new ReadingFocusService().enabled()).toBe(false);
  });

  it('stores a changed setting for the next reload', () => {
    const service = new ReadingFocusService();

    service.setEnabled(true);

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem('sfr.readingFocus')).toBe('true');
  });
});
