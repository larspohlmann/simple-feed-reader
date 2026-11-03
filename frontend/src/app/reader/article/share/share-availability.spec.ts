import { TestBed } from '@angular/core/testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { ShareAvailability } from './share-availability';
import { CONFIGURED_TARGETS } from './share-targets';

describe('ShareAvailability', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
  });

  const allIds = (): string[] => CONFIGURED_TARGETS.map((target) => target.id);

  it('is available while sharing is on and a target is left', () => {
    TestBed.inject(SharingService).setTargetShown('x', false);

    expect(TestBed.inject(ShareAvailability).available()).toBe(true);
  });

  it('is unavailable with sharing switched off', () => {
    TestBed.inject(SharingService).setEnabled(false);

    expect(TestBed.inject(ShareAvailability).available()).toBe(false);
  });

  it('is unavailable with every listed target hidden', () => {
    const sharing = TestBed.inject(SharingService);
    allIds().forEach((id) => sharing.setTargetShown(id, false));

    expect(TestBed.inject(ShareAvailability).available()).toBe(false);
  });
});
