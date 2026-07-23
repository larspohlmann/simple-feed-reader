import { TestBed } from '@angular/core/testing';
import { ReadingLayoutService } from './reading-layout.service';

describe('ReadingLayoutService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('defaults to magazine when nothing is saved', () => {
    localStorage.removeItem('sfr.layout');
    const svc = new ReadingLayoutService();
    expect(svc.mode()).toBe('magazine');
  });

  it('honours a saved list or pane choice', () => {
    localStorage.setItem('sfr.layout', 'list');
    expect(new ReadingLayoutService().mode()).toBe('list');
    localStorage.setItem('sfr.layout', 'pane');
    expect(new ReadingLayoutService().mode()).toBe('pane');
  });

  it('persists and applies each mode', () => {
    const svc = new ReadingLayoutService();
    svc.set('magazine');
    expect(localStorage.getItem('sfr.layout')).toBe('magazine');
    expect(svc.mode()).toBe('magazine');
  });

  it('ignores a garbage saved value', () => {
    localStorage.setItem('sfr.layout', 'nonsense');
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('magazine');
  });
});
