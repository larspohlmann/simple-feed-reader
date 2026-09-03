import { TestBed } from '@angular/core/testing';
import { DEFAULT_LIST_PERCENT, MAX_LIST_PERCENT, MIN_LIST_PERCENT } from './pane-split';
import { PaneSplitService } from './pane-split.service';

describe('PaneSplitService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('defaults to 42 when nothing is saved', () => {
    localStorage.removeItem('sfr.paneSplit');
    expect(new PaneSplitService().width()).toBe(DEFAULT_LIST_PERCENT);
  });

  it('honours a saved valid value', () => {
    localStorage.setItem('sfr.paneSplit', '55');
    expect(new PaneSplitService().width()).toBe(55);
  });

  it('persists and applies a set value', () => {
    const service = new PaneSplitService();
    service.set(60);
    expect(localStorage.getItem('sfr.paneSplit')).toBe('60');
    expect(service.width()).toBe(60);
  });

  it('falls back to the default for a garbage saved value', () => {
    localStorage.setItem('sfr.paneSplit', 'nonsense');
    expect(TestBed.inject(PaneSplitService).width()).toBe(DEFAULT_LIST_PERCENT);
  });

  it('treats an empty saved value as nothing saved, not as zero', () => {
    // `Number('')` is 0 — finite — so an empty string would slip past a bare
    // finite check and clamp to the minimum. It must read as "unset" instead.
    localStorage.setItem('sfr.paneSplit', '');
    expect(new PaneSplitService().width()).toBe(DEFAULT_LIST_PERCENT);
  });

  it('clamps out-of-band input on set', () => {
    const service = new PaneSplitService();
    service.set(MIN_LIST_PERCENT - 10);
    expect(service.width()).toBe(MIN_LIST_PERCENT);
    service.set(MAX_LIST_PERCENT + 10);
    expect(service.width()).toBe(MAX_LIST_PERCENT);
  });

  it('resets to the default', () => {
    const service = new PaneSplitService();
    service.set(60);
    service.reset();
    expect(service.width()).toBe(DEFAULT_LIST_PERCENT);
  });
});
