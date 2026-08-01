import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { SetupApi } from './setup-api';
import { SetupService } from './setup.service';

describe('SetupService', () => {
  function configure(status: jest.Mock): SetupService {
    TestBed.configureTestingModule({
      providers: [SetupService, { provide: SetupApi, useValue: { status } }],
    });
    return TestBed.inject(SetupService);
  }

  it('fetches status once and caches it', (done) => {
    const status = jest.fn().mockReturnValue(of({ needsSetup: true }));
    const service = configure(status);

    service.ensureLoaded().subscribe(() => {
      service.ensureLoaded().subscribe((needs) => {
        expect(needs).toBe(true);
        expect(status).toHaveBeenCalledTimes(1);
        done();
      });
    });
  });

  it('markComplete flips needsSetup to false without another call', () => {
    const status = jest.fn().mockReturnValue(of({ needsSetup: true }));
    const service = configure(status);

    service.markComplete();

    expect(service.needsSetup()).toBe(false);
  });
});
