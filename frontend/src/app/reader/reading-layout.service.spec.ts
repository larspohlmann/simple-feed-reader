import { TestBed } from '@angular/core/testing';
import { ReadingLayoutService } from './reading-layout.service';

describe('ReadingLayoutService', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({});
  });

  it('defaults to list', () => {
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('list');
  });

  it('persists and restores the choice', () => {
    TestBed.inject(ReadingLayoutService).set('pane');
    expect(localStorage.getItem('sfr.layout')).toBe('pane');
    // A fresh injector reads the saved value.
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('pane');
  });

  it('ignores a garbage saved value', () => {
    localStorage.setItem('sfr.layout', 'nonsense');
    expect(TestBed.inject(ReadingLayoutService).mode()).toBe('list');
  });
});
