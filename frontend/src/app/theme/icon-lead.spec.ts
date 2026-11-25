import { compileScssSource, rulesBySelector } from './sass-testing';

const PROBE = `
@use 'icon-lead';

@include icon-lead.register;

.row {
  @include icon-lead.first-line('> .glyph');
}

.note {
  @include icon-lead.box(var(--space-3), '> .glyph');
}

.chip-row {
  @include icon-lead.first-line('> .chip');
  @include icon-lead.overhang('> .chip', calc(var(--icon-sm) + 2 * var(--space-2)));
}

.item {
  @include icon-lead.centred-line;
}

.fixed {
  @include icon-lead.fixed-row(var(--sidebar-row-h), '> .glyph');
}
`;

describe('icon-lead', () => {
  const css = compileScssSource(PROBE, [__dirname]);
  const rules = rulesBySelector(css.replace(/@property[^{]*\{[^}]*\}/, ''));

  it('registers the line as a length that inherits and starts at 0px', () => {
    const block = /@property --icon-lead-line\s*\{([^}]*)\}/.exec(css)?.[1] ?? '';

    expect(block).toMatch(/syntax:\s*"<length>"/);
    expect(block).toMatch(/inherits:\s*true/);
    expect(block).toMatch(/initial-value:\s*0px/);
  });

  it('measures one line on the row and starts the items at its top', () => {
    expect(rules['.row']).toMatchObject({ '--icon-lead-line': '1lh', 'align-items': 'start' });
  });

  it('gives the slot the line as its height and centres the glyph in it', () => {
    expect(rules['.row > .glyph']).toMatchObject({
      'block-size': 'var(--icon-lead-line)',
      'align-items': 'center',
      flex: 'none',
    });
  });

  it('derives a note padding and gap from the font', () => {
    expect(rules['.note']).toMatchObject({
      '--icon-lead-band': 'calc((1lh - 1cap) / 2)',
      padding: 'var(--space-3) calc(var(--space-3) + var(--icon-lead-band))',
      gap: 'calc(var(--space-3) + var(--icon-lead-band))',
    });
  });

  it('centres a slot box taller than the line on it, at its own height', () => {
    expect(rules['.chip-row > .chip']).toMatchObject({
      'block-size': 'auto',
      'margin-block':
        'calc((var(--icon-lead-line) - calc(var(--icon-sm) + 2 * var(--space-2))) / 2)',
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
  });
});
