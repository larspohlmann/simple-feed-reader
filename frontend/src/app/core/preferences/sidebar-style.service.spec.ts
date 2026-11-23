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

  it('starts boxed, so airy() is false', () => {
    const service = make();

    expect(service.style()).toBe('boxed');
    expect(service.airy()).toBe(false);
  });

  it('writes the airy choice to the account and airy() follows', () => {
    const service = make();

    service.set('airy');

    expect(write).toHaveBeenCalledWith({ sidebarStyle: 'airy' });
    expect(localStorage.getItem(SIDEBAR_STYLE_KEY)).toBe('airy');
    expect(service.airy()).toBe(true);
  });
});
