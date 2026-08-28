import { ReadingFocusService } from './reading-focus.service';

describe('ReadingFocusService', () => {
  beforeEach(() => localStorage.clear());

  it('restores a disabled reading focus from local storage', () => {
    localStorage.setItem('sfr.readingFocus', 'false');

    expect(new ReadingFocusService().enabled()).toBe(false);
  });

  it('stores a changed setting for the next reload', () => {
    const service = new ReadingFocusService();

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(localStorage.getItem('sfr.readingFocus')).toBe('false');
  });
});
