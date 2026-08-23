import { Route } from '@angular/router';
import en from '../../../public/i18n/en.json';
import { SETTINGS_SECTIONS } from './settings-sections';
import { SETTINGS_ROUTES } from './settings.routes';

describe('SETTINGS_ROUTES', () => {
  const sections = SETTINGS_ROUTES[0].children ?? [];

  it('gives every section its own title, so the tab names the section on screen', () => {
    for (const section of sections.filter(hasOwnPath)) {
      expect(section.title).toBeDefined();
    }
  });

  it('titles each section with the label its nav entry uses', () => {
    for (const section of SETTINGS_SECTIONS) {
      expect(sections.find((r) => r.path === section.path)?.title).toBe(section.labelKey);
    }
  });

  it('leaves the hub to the title of the settings area itself', () => {
    expect(sections.find((r) => r.path === '')?.title).toBeUndefined();
  });

  it('titles sections by a key the dictionary holds', () => {
    for (const section of sections) {
      if (typeof section.title !== 'string') continue;
      expect(translation(section.title)).toBeDefined();
    }
  });
});

/** The hub answers on the area's own path and takes the area's title with it. */
function hasOwnPath(route: Route): boolean {
  return route.path !== undefined && route.path !== '';
}

function translation(key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (node, part) => (node as Record<string, unknown> | undefined)?.[part],
      en as unknown,
    );
}
