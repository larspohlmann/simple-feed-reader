import { Route, Router, provideRouter } from '@angular/router';
import { TestBed } from '@angular/core/testing';
import { hasTranslation } from '../../testing/translation-keys';
import { SETTINGS_ROUTES } from './settings.routes';

describe('SETTINGS_ROUTES', () => {
  const sections = SETTINGS_ROUTES[0].children ?? [];

  it('gives every section its own title, so the tab names the section on screen', () => {
    for (const section of sections.filter(isSection)) {
      expect(section.title).toBeDefined();
    }
  });

  it('leaves the hub to the title of the settings area itself', () => {
    expect(sections.find((r) => r.path === '')?.title).toBeUndefined();
  });

  it('titles sections by a key the dictionary holds', () => {
    for (const section of sections.filter(isSection)) {
      expect(hasTranslation(String(section.title))).toBe(true);
    }
  });

  it('loads the import page, not one of the two sections it composes', async () => {
    const route = sections.find((r) => r.path === 'import')!;
    const loaded = await (route.loadComponent as () => Promise<unknown>)();

    expect((loaded as { name: string }).name).toBe('ImportSectionComponent');
  });

  it('forwards the retired Tags path to Organise, so an old bookmark still lands', async () => {
    TestBed.configureTestingModule({ providers: [provideRouter(SETTINGS_ROUTES)] });
    const router = TestBed.inject(Router);

    await router.navigateByUrl('/tags');

    expect(router.url).toBe('/organise');
  });
});

/** A section owns a path and renders a page. The hub answers on the area's own
 *  path and takes the area's title with it; the retired Tags path is a forward
 *  and renders nothing — neither carries a title of its own. */
function isSection(route: Route): boolean {
  return route.path !== undefined && route.path !== '' && route.redirectTo === undefined;
}
