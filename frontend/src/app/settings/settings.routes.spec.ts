import { Route } from '@angular/router';
import { hasTranslation } from '../../testing/translation-keys';
import { SETTINGS_ROUTES } from './settings.routes';

describe('SETTINGS_ROUTES', () => {
  const sections = SETTINGS_ROUTES[0].children ?? [];

  it('gives every section its own title, so the tab names the section on screen', () => {
    for (const section of sections.filter(hasOwnPath)) {
      expect(section.title).toBeDefined();
    }
  });

  it('leaves the hub to the title of the settings area itself', () => {
    expect(sections.find((r) => r.path === '')?.title).toBeUndefined();
  });

  it('titles sections by a key the dictionary holds', () => {
    for (const section of sections.filter(hasOwnPath)) {
      expect(hasTranslation(String(section.title))).toBe(true);
    }
  });

  it('loads the import page, not one of the two sections it composes', async () => {
    const route = sections.find((r) => r.path === 'import')!;
    const loaded = await (route.loadComponent as () => Promise<unknown>)();

    expect((loaded as { name: string }).name).toBe('ImportSectionComponent');
  });
});

/** The hub answers on the area's own path and takes the area's title with it. */
function hasOwnPath(route: Route): boolean {
  return route.path !== undefined && route.path !== '';
}
