import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { PREFERENCES_WRITER } from './preferences-writer';
import { SIDEBAR_STYLE_KEY } from './sidebar-style';
import { SidebarStyleService } from './sidebar-style.service';

describe('SidebarStyleService', () => {
  const write = jest.fn(() => of(true));

  function make(): SidebarStyleService {
    TestBed.configureTestingModule({
      providers: [{ provide: PREFERENCES_WRITER, useValue: { write } }],
    });
    return TestBed.inject(SidebarStyleService);
  }

  beforeEach(() => {
    localStorage.clear();
    write.mockClear();
    TestBed.resetTestingModule();
  });

  it('starts airy, so airy() is true', () => {
    const service = make();

    expect(service.style()).toBe('airy');
    expect(service.airy()).toBe(true);
  });

  it('writes the boxed choice to the account and airy() follows', () => {
    const service = make();

    service.set('boxed');

    expect(write).toHaveBeenCalledWith({ sidebarStyle: 'boxed' });
    expect(localStorage.getItem(SIDEBAR_STYLE_KEY)).toBe('boxed');
    expect(service.airy()).toBe(false);
  });
});
