import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, Routes, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { readerMatcher, selectionFromRoute } from './reader-matcher';
import { selectionQueryParams } from './query';

@Component({ template: '' })
class ReaderStubComponent {}

const routes: Routes = [{ matcher: readerMatcher, component: ReaderStubComponent }];

function leafRoute(router: Router): ActivatedRoute {
  let route = router.routerState.root;
  while (route.firstChild) route = route.firstChild;
  return route;
}

// The reader shell owns both `/` and `/searches/saved/:slug` through one matcher
// route, so an "All items" link's target depends entirely on whether it navigates
// absolutely or relative to that shared route (#1118).
describe('reader navigation off a saved-search path (#1118)', () => {
  async function openSavedSearch(): Promise<Router> {
    TestBed.configureTestingModule({ providers: [provideRouter(routes)] });
    await RouterTestingHarness.create('/searches/saved/4-climate');
    return TestBed.inject(Router);
  }

  it('drops the saved-search path when All items navigates absolutely', async () => {
    const router = await openSavedSearch();
    expect(router.url).toBe('/searches/saved/4-climate');

    await router.navigate(['/'], {
      queryParams: selectionQueryParams({}),
      queryParamsHandling: 'merge',
    });

    expect(router.url).toBe('/');
    const leaf = leafRoute(router);
    const { selection } = selectionFromRoute(leaf.snapshot.paramMap, leaf.snapshot.queryParamMap);
    expect(selection.kind).toBe('all');
  });

  it('stays trapped on the saved search when All items navigates relatively (the old bug)', async () => {
    const router = await openSavedSearch();
    const route = leafRoute(router);

    await router.navigate([], {
      relativeTo: route,
      queryParams: selectionQueryParams({}),
      queryParamsHandling: 'merge',
    });

    expect(router.url).toBe('/searches/saved/4-climate');
    const leaf = leafRoute(router);
    const { selection } = selectionFromRoute(leaf.snapshot.paramMap, leaf.snapshot.queryParamMap);
    expect(selection.kind).toBe('saved-search');
  });
});
