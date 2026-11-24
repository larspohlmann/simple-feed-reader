import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { PREFERENCES_WRITER } from './preferences-writer';
import { THEME_NAME_KEY } from './theme-name';
import { ThemeNameService } from './theme-name.service';

describe('ThemeNameService', () => {
  const write = jest.fn(() => of(true));
  const attribute = (): string | null => document.documentElement.getAttribute('data-theme-name');

  function make(): ThemeNameService {
    TestBed.configureTestingModule({
      providers: [{ provide: PREFERENCES_WRITER, useValue: { write } }],
    });
    return TestBed.inject(ThemeNameService);
  }

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme-name');
    write.mockClear();
    TestBed.resetTestingModule();
  });

  it('starts graphite with no attribute', () => {
    const service = make();

    expect(service.name()).toBe('graphite');
    expect(attribute()).toBeNull();
  });

  it('starts classic with the attribute set when classic is cached', () => {
    localStorage.setItem(THEME_NAME_KEY, 'classic');

    const service = make();

    expect(service.name()).toBe('classic');
    expect(attribute()).toBe('classic');
  });

  it('starts graphite with no attribute when the cache holds an unknown name', () => {
    localStorage.setItem(THEME_NAME_KEY, 'sepia');

    const service = make();

    expect(service.name()).toBe('graphite');
    expect(attribute()).toBeNull();
  });

  it('writes the classic choice to the account, caches it and sets the attribute', () => {
    const service = make();

    service.set('classic');

    expect(write).toHaveBeenCalledWith({ theme: 'classic' });
    expect(localStorage.getItem(THEME_NAME_KEY)).toBe('classic');
    expect(attribute()).toBe('classic');
  });

  it('removes the attribute when graphite is picked again', () => {
    const service = make();
    service.set('classic');

    service.set('graphite');

    expect(write).toHaveBeenLastCalledWith({ theme: 'graphite' });
    expect(attribute()).toBeNull();
  });

  it('reset removes the attribute and the cache key', () => {
    const service = make();
    service.set('classic');

    service.reset();

    expect(service.name()).toBe('graphite');
    expect(localStorage.getItem(THEME_NAME_KEY)).toBeNull();
    expect(attribute()).toBeNull();
  });
});
