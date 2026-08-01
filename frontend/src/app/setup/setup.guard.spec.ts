import { TestBed } from '@angular/core/testing';
import { GuardResult, MaybeAsync, UrlTree } from '@angular/router';
import { of, throwError } from 'rxjs';
import { runInInjectionContext, EnvironmentInjector } from '@angular/core';
import { firstValueFrom, isObservable } from 'rxjs';
import { SetupService } from './setup.service';
import { requireSetupGuard, setupRedirectGuard } from './setup.guard';

function resolve(result: MaybeAsync<GuardResult>): Promise<GuardResult> {
  return isObservable(result) ? firstValueFrom(result) : Promise.resolve(result);
}

describe('setup guards', () => {
  function inject(needsSetup: boolean) {
    TestBed.configureTestingModule({
      providers: [{ provide: SetupService, useValue: { ensureLoaded: () => of(needsSetup) } }],
    });
    return TestBed.inject(EnvironmentInjector);
  }

  function injectErroring() {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: SetupService,
          useValue: { ensureLoaded: () => throwError(() => new Error('boom')) },
        },
      ],
    });
    return TestBed.inject(EnvironmentInjector);
  }

  it('setupRedirectGuard sends to /setup when setup is needed', async () => {
    const injector = inject(true);
    const result = await runInInjectionContext(injector, () =>
      resolve(setupRedirectGuard({} as never, {} as never)),
    );
    expect((result as UrlTree).toString()).toBe('/setup');
  });

  it('setupRedirectGuard allows navigation when no setup is needed', async () => {
    const injector = inject(false);
    const result = await runInInjectionContext(injector, () =>
      resolve(setupRedirectGuard({} as never, {} as never)),
    );
    expect(result).toBe(true);
  });

  it('requireSetupGuard sends to /login when no setup is needed', async () => {
    const injector = inject(false);
    const result = await runInInjectionContext(injector, () =>
      resolve(requireSetupGuard({} as never, {} as never)),
    );
    expect((result as UrlTree).toString()).toBe('/login');
  });

  it('requireSetupGuard allows navigation when setup is needed', async () => {
    const injector = inject(true);
    const result = await runInInjectionContext(injector, () =>
      resolve(requireSetupGuard({} as never, {} as never)),
    );
    expect(result).toBe(true);
  });

  it('setupRedirectGuard fails open (allows navigation) when the status check errors', async () => {
    const injector = injectErroring();
    const result = await runInInjectionContext(injector, () =>
      resolve(setupRedirectGuard({} as never, {} as never)),
    );
    expect(result).toBe(true);
  });

  it('requireSetupGuard fails closed (sends to /login) when the status check errors', async () => {
    const injector = injectErroring();
    const result = await runInInjectionContext(injector, () =>
      resolve(requireSetupGuard({} as never, {} as never)),
    );
    expect((result as UrlTree).toString()).toBe('/login');
  });
});
