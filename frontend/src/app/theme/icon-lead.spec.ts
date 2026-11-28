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
  @include icon-lead.box(var(--space-1), '> .glyph', '> .text', $gap: var(--space-1));
}

.flush {
  @include icon-lead.box(0, '> .glyph', '> .text');
}

.chip {
  @include icon-lead.padded-slot(var(--icon-sm), var(--space-2));
}

.logo {
  @include icon-lead.self-sized(var(--icon-xs));
}

.tall {
  @include icon-lead.box(var(--space-2), '> .glyph', '> .text', var(--icon-lg));
}

.fixed {
  @include icon-lead.fixed-row(var(--sidebar-row-h), '> .glyph', '> .text');
}

.fixed-lead {
  @include icon-lead.fixed-row(var(--sidebar-row-h), '> .glyph', '> .text', $gap: var(--space-3));
}

.offset {
  margin-block-start: icon-lead.line-offset(var(--control-h));
}

@include icon-lead.register-length(--probe-length, true);
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
    ['--probe-length', true],
  ])('registers %s as a length that starts at 0px, inherited: %s', (property, inherited) => {
    const block = registration(property);

    expect(block).toMatch(/syntax:\s*"<length>"/);
    expect(block).toMatch(new RegExp(`inherits:\\s*${inherited}`));
    expect(block).toMatch(/initial-value:\s*0px/);
  });

  it('measures the cap on the row, lines the slot and the text up on one baseline and centres the rest', () => {
    expect(rules['.row']).toMatchObject({ '--icon-lead-cap': '1cap', 'align-items': 'center' });
    expect(rules['.row > .glyph, .row > .text']).toMatchObject({ 'align-self': 'baseline' });
  });

  it('gives the slot a baseline a half cap below its centre, the box it publishes and no lead margin', () => {
    expect(rules['.row > .glyph']).toMatchObject({
      'align-items': 'center',
      flex: 'none',
      'block-size': 'var(--icon-lead-self)',
      'margin-block': 'calc(var(--icon-lead-self) / -2)',
      'margin-inline-end': '0',
    });
    expect(rules['.row > .glyph::before']).toMatchObject({
      content: '"" !important',
      'block-size': 'var(--icon-lead-cap) !important',
    });
  });

  it('publishes a chip or a self-sized host box to the slot', () => {
    expect(rules['.chip']).toMatchObject({
      '--icon-lead-self': 'calc(var(--icon-sm) + 2 * var(--space-2))',
      padding: 'var(--space-2)',
    });
    expect(rules['.logo']).toMatchObject({
      '--icon-lead-self': 'var(--icon-xs)',
      width: 'var(--icon-lead-self)',
      height: 'var(--icon-lead-self)',
    });
  });

  it('derives a note padding and gap from one lead space, measured on the note', () => {
    expect(rules['.note']).toMatchObject({
      '--icon-lead-space': 'calc(var(--space-3) + (1lh - 1cap) / 2)',
      padding: 'var(--space-3) var(--icon-lead-space)',
      gap: 'var(--icon-lead-space)',
    });
  });

  it('takes a unitless 0 inset as a length calc() can add', () => {
    expect(rules['.flush']).toMatchObject({
      '--icon-lead-space': 'calc(0px + (1lh - 1cap) / 2)',
      padding: '0px var(--icon-lead-space)',
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
    expect(rules['.alone > .glyph.only::before']).toMatchObject({ content: 'none !important' });
  });

  it('lines a whole row up on the baseline when its text is bare', () => {
    expect(rules['.bare']).toMatchObject({ 'align-items': 'baseline' });
    expect(rules['.bare > .glyph::before']).toMatchObject({
      'block-size': 'var(--icon-lead-cap) !important',
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
    expect(rules['.offset']).toMatchObject({
      'margin-block-start': 'calc((var(--control-h) - 1lh) / 2)',
    });
  });

  it.each([
    ['.pill', 'var(--space-1)'],
    ['.fixed-lead', 'var(--space-3)'],
  ])(
    'with a $gap, %s keeps the lead space before the text only and the token after it',
    (row, gap) => {
      expect(rules[row]).toMatchObject({ gap });
      expect(rules[`${row} > .glyph`]).toMatchObject({
        'margin-inline-end': `calc(var(--icon-lead-space) - ${gap})`,
      });
    },
  );

  it('declares a gap once, the token or the lead space', () => {
    const countGaps = (row: string) =>
      css.match(new RegExp(`\\${row}\\s*\\{[^}]*`))![0].match(/\bgap:/g);

    expect(countGaps('.pill')).toHaveLength(1);
    expect(countGaps('.note')).toHaveLength(1);
  });
});
