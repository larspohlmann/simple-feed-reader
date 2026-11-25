import { compileScssSource, rulesBySelector } from './sass-testing';

const PROBE = `
@use 'icon-lead';

@include icon-lead.register;

.row {
  @include icon-lead.first-line('> .glyph', '> .text');
}

.note {
  @include icon-lead.box(var(--space-3), '> .glyph', '> .text');
}

.alone {
  @include icon-lead.box(var(--space-1), '> .glyph', null);
  @include icon-lead.alone('> .glyph.only');
}

.item {
  @include icon-lead.centred-line;
}

.bare {
  @include icon-lead.first-line('> .glyph', null);
}

.pill {
  @include icon-lead.box-lead(var(--space-1), '> .glyph', '> .text', var(--space-1));
}

.tall {
  @include icon-lead.box(var(--space-2), '> .glyph', '> .text', var(--icon-lg));
}

.fixed {
  @include icon-lead.fixed-row(var(--sidebar-row-h), '> .glyph', '> .text');
}

.fixed-lead {
  @include icon-lead.fixed-row-lead(
    var(--sidebar-row-h),
    '> .glyph',
    '> .text',
    var(--space-3)
  );
}
`;

describe('icon-lead', () => {
  const css = compileScssSource(PROBE, [__dirname]);
  const rules = rulesBySelector(css.replace(/@property[^{]*\{[^}]*\}/g, ''));

  const registration = (property: string) =>
    new RegExp(`@property ${property}\\s*\\{([^}]*)\\}`).exec(css)?.[1] ?? '';

  it.each([
    ['--icon-lead-cap', true],
    ['--icon-lead-space', true],
    ['--icon-lead-self', false],
  ])('registers %s as a length that starts at 0px, inherited: %s', (property, inherited) => {
    const block = registration(property);

    expect(block).toMatch(/syntax:\s*"<length>"/);
    expect(block).toMatch(new RegExp(`inherits:\\s*${inherited}`));
    expect(block).toMatch(/initial-value:\s*0px/);
  });

  it('measures the cap on the row and lines the slot and the text up on one baseline', () => {
    expect(rules['.row']).toMatchObject({ '--icon-lead-cap': '1cap', 'align-items': 'start' });
    expect(rules['.row > .glyph, .row > .text']).toMatchObject({ 'align-self': 'baseline' });
  });

  it('gives the slot a baseline a half cap below its centre and no height of its own', () => {
    expect(rules['.row > .glyph']).toMatchObject({
      'align-items': 'center',
      flex: 'none',
      'block-size': 'var(--icon-lead-self)',
    });
    expect(rules['.row > .glyph::before']).toMatchObject({
      content: '""',
      'block-size': 'var(--icon-lead-cap)',
    });
  });

  it('lets a host whose box is its glyph publish its size, keep that box and cancel it with margins', () => {
    expect(rules['.row > .glyph']).toMatchObject({
      'margin-block': 'calc(var(--icon-lead-self) / -2)',
    });
  });

  it('derives a note padding and gap from one lead space, measured on the note', () => {
    expect(rules['.note']).toMatchObject({
      '--icon-lead-space': 'calc(var(--space-3) + (1lh - 1cap) / 2)',
      padding: 'var(--space-3) var(--icon-lead-space)',
      gap: 'var(--icon-lead-space)',
    });
  });

  it('keeps a glyph taller than the line its inset clear of the edges', () => {
    expect(rules['.tall']).toMatchObject({
      padding:
        'max(var(--space-2), var(--space-2) + (var(--icon-lg) - 1lh) / 2) var(--icon-lead-space)',
    });
  });

  it('gives a glyph with no text its own box back, centred, with no lead space after it', () => {
    expect(rules['.alone > .glyph.only']).toMatchObject({
      'align-self': 'center',
      'block-size': 'auto',
      'margin-block': '0',
      'margin-inline-end': '0',
    });
    expect(rules['.alone > .glyph.only::before']).toMatchObject({ content: 'none' });
  });

  it('lines a whole row up on the baseline when its text is bare', () => {
    expect(rules['.bare']).toMatchObject({ 'align-items': 'baseline' });
    expect(rules['.bare > .glyph::before']).toMatchObject({ 'block-size': 'var(--icon-lead-cap)' });
  });

  it('keeps the lead space before a pill text only, and the token between the items after it', () => {
    expect(rules['.pill']).toMatchObject({
      '--icon-lead-space': 'calc(var(--space-1) + (1lh - 1cap) / 2)',
      padding: 'var(--space-1) var(--icon-lead-space)',
      gap: 'var(--space-1)',
    });
    expect(rules['.pill > .glyph']).toMatchObject({
      'margin-inline-end': 'calc(var(--icon-lead-space) - var(--space-1))',
    });
  });

  it('keeps a single wrapped line centred in a taller row', () => {
    expect(rules['.item']).toMatchObject({ 'flex-wrap': 'wrap', 'align-content': 'center' });
  });

  it('derives a fixed-height row start padding and gap from its height', () => {
    expect(rules['.fixed']).toMatchObject({
      '--icon-lead-space': 'calc((var(--sidebar-row-h) - 1cap) / 2)',
      'padding-inline-start': 'var(--icon-lead-space)',
      gap: 'var(--icon-lead-space)',
    });
    expect(rules['.fixed > .text']).toMatchObject({
      'margin-block': 'calc((var(--sidebar-row-h) - 1lh) / 2)',
    });
  });

  it('keeps the lead space before the text only, and the row token between the items after it', () => {
    expect(rules['.fixed-lead']).toMatchObject({
      'padding-inline-start': 'var(--icon-lead-space)',
      gap: 'var(--space-3)',
    });
    expect(rules['.fixed-lead > .glyph']).toMatchObject({
      'margin-inline-end': 'calc(var(--icon-lead-space) - var(--space-3))',
    });
  });
});
