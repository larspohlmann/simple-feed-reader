import { TestBed } from '@angular/core/testing';
import { SidebarVisibilityService } from './sidebar-visibility.service';

describe('SidebarVisibilityService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('shows the sidebar when nothing is saved', () => {
    expect(new SidebarVisibilityService().hidden()).toBe(false);
  });

  it('honours a saved hidden state', () => {
    localStorage.setItem('sfr.sidebarHidden', '1');
    expect(new SidebarVisibilityService().hidden()).toBe(true);
  });

  it('shows the sidebar for any value other than the hidden marker', () => {
    localStorage.setItem('sfr.sidebarHidden', 'nonsense');
    expect(new SidebarVisibilityService().hidden()).toBe(false);
  });

  it('hides and persists the hidden marker', () => {
    const service = new SidebarVisibilityService();
    service.hide();
    expect(service.hidden()).toBe(true);
    expect(localStorage.getItem('sfr.sidebarHidden')).toBe('1');
  });

  it('shows and clears the hidden marker', () => {
    localStorage.setItem('sfr.sidebarHidden', '1');
    const service = new SidebarVisibilityService();
    service.show();
    expect(service.hidden()).toBe(false);
    expect(localStorage.getItem('sfr.sidebarHidden')).toBeNull();
  });
});
