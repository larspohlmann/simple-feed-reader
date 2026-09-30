import { TestBed } from '@angular/core/testing';
import { BreakpointObserver, BreakpointState } from '@angular/cdk/layout';
import { Subject } from 'rxjs';
import { LayoutService } from './layout.service';

describe('LayoutService', () => {
  const changes = new Subject<BreakpointState>();
  const observer = { observe: () => changes.asObservable() } as unknown as BreakpointObserver;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [{ provide: BreakpointObserver, useValue: observer }],
    });
  });

  it('tracks the wide breakpoint', () => {
    const service = TestBed.inject(LayoutService);
    changes.next({ matches: true, breakpoints: {} });
    expect(service.isWide()).toBe(true);
    changes.next({ matches: false, breakpoints: {} });
    expect(service.isWide()).toBe(false);
  });

  it('tracks the coarse-pointer capability', () => {
    const service = TestBed.inject(LayoutService);
    changes.next({ matches: true, breakpoints: {} });
    expect(service.isCoarse()).toBe(true);
    changes.next({ matches: false, breakpoints: {} });
    expect(service.isCoarse()).toBe(false);
  });
});
