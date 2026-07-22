import { TAG_COLORS, TAG_ICONS } from './icon-choices';

describe('tag choices', () => {
  it('every colour is a #rrggbb hex the backend accepts', () => {
    expect(TAG_COLORS.length).toBeGreaterThan(0);
    for (const c of TAG_COLORS) expect(c).toMatch(/^#[0-9a-fA-F]{6}$/);
  });

  it('every icon is a Material Symbol name the backend accepts', () => {
    expect(TAG_ICONS.length).toBeGreaterThan(0);
    for (const i of TAG_ICONS) expect(i).toMatch(/^[a-z0-9_]+$/);
  });
});
