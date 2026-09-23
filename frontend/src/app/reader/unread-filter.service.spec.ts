import { TestBed } from '@angular/core/testing';
import { UnreadFilterService } from './unread-filter.service';

describe('UnreadFilterService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('shows everything when nothing is stored', () => {
    expect(new UnreadFilterService().unreadOnly()).toBe(false);
  });

  it('reads a stored unread-only choice back', () => {
    localStorage.setItem('sfr.unread-only', '1');
    expect(new UnreadFilterService().unreadOnly()).toBe(true);
  });

  it('persists and applies each state', () => {
    const svc = new UnreadFilterService();

    svc.set(true);
    expect(localStorage.getItem('sfr.unread-only')).toBe('1');
    expect(svc.unreadOnly()).toBe(true);

    svc.set(false);
    expect(localStorage.getItem('sfr.unread-only')).toBe('0');
    expect(svc.unreadOnly()).toBe(false);
  });

  it('reads a garbage stored value as show-all', () => {
    localStorage.setItem('sfr.unread-only', 'yes');
    expect(new UnreadFilterService().unreadOnly()).toBe(false);
  });
});
