import { CatalogCategoryDto } from './catalog.models';
import { CatalogSelection } from './catalog-selection.store';

function category(overrides: Partial<CatalogCategoryDto> = {}): CatalogCategoryDto {
  return {
    id: 1,
    key: 'technology',
    name: 'Technology',
    icon: 'memory',
    color: '#3b82f6',
    feeds: [
      { id: 10, title: 'A', description: null, siteUrl: null, faviconUrl: '/a', subscribed: false },
      { id: 11, title: 'B', description: null, siteUrl: null, faviconUrl: '/b', subscribed: false },
    ],
    ...overrides,
  };
}

describe('CatalogSelection', () => {
  it('starts empty and toggles one feed at a time', () => {
    const selection = new CatalogSelection();
    selection.setCategories([category()]);

    expect(selection.selectedCount()).toBe(0);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(true);
    expect(selection.selectedCount()).toBe(1);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(false);
  });

  it('pre-selects already-subscribed feeds and refuses to toggle them', () => {
    const subscribed = category({
      feeds: [
        {
          id: 10,
          title: 'A',
          description: null,
          siteUrl: null,
          faviconUrl: '/a',
          subscribed: true,
        },
      ],
    });
    const selection = new CatalogSelection();
    selection.setCategories([subscribed]);

    expect(selection.isSelected(10)).toBe(true);
    expect(selection.isLocked(10)).toBe(true);

    selection.toggle(10);
    expect(selection.isSelected(10)).toBe(true);

    // A locked feed is already subscribed, so it is not part of what we submit.
    expect(selection.selectedIds()).toEqual([]);
  });

  it('selects and clears a whole category without touching locked feeds', () => {
    const mixed = category({
      feeds: [
        {
          id: 10,
          title: 'A',
          description: null,
          siteUrl: null,
          faviconUrl: '/a',
          subscribed: false,
        },
        {
          id: 11,
          title: 'B',
          description: null,
          siteUrl: null,
          faviconUrl: '/b',
          subscribed: true,
        },
      ],
    });
    const selection = new CatalogSelection();
    selection.setCategories([mixed]);

    selection.selectAll(1);
    expect(selection.selectedIds()).toEqual([10]);
    expect(selection.selectedInCategory(1)).toBe(1);

    selection.clearCategory(1);
    expect(selection.selectedIds()).toEqual([]);
    expect(selection.isSelected(11)).toBe(true);
  });

  it('counts the categories a selection spans, which is how many tags it creates', () => {
    const selection = new CatalogSelection();
    selection.setCategories([
      category(),
      category({
        id: 2,
        key: 'science',
        name: 'Science',
        feeds: [
          {
            id: 20,
            title: 'Q',
            description: null,
            siteUrl: null,
            faviconUrl: '/q',
            subscribed: false,
          },
        ],
      }),
    ]);

    selection.toggle(10);
    expect(selection.selectedCategoryCount()).toBe(1);

    selection.toggle(20);
    expect(selection.selectedCategoryCount()).toBe(2);
  });
});
