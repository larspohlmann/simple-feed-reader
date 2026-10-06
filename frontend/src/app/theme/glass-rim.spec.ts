import { join } from 'node:path';
import * as sass from 'sass';

type Declarations = Record<string, string>;

const PROBE = `
@use 'sass:map';
@use 'glass-rim';

knobs {
  strength: glass-rim.$strength;
  control-boost: glass-rim.$control-boost;
  faint-ratio: glass-rim.$faint-ratio;
  light-glint: map.get(glass-rim.$modes, light, glint);
  light-shade: map.get(glass-rim.$modes, light, shade);
  dark-glint: map.get(glass-rim.$modes, dark, glint);
  dark-shade: map.get(glass-rim.$modes, dark, shade);
  angle: glass-rim.$angle;
  base-from: glass-rim.$base-from;
  shade-at: glass-rim.$shade-at;
  base-to: glass-rim.$base-to;
}

light {
  @include glass-rim.tokens(light);
}

dark {
  @include glass-rim.tokens(dark);
}

.box {
  @include glass-rim.filled(var(--surface-1));
}

.chip {
  @include glass-rim.filled(var(--surface-2), var(--accent), control);
}

.dot {
  @include glass-rim.ring(var(--border-strong), control);
}
`;

function compile(source: string): string {
  return sass.compileString(source, { loadPaths: [__dirname], style: 'expanded' }).css;
}

function blocks(css: string): Record<string, Declarations> {
  const parsed: Record<string, Declarations> = {};
  const bare = css.replace(/^@charset [^;]*;/, '').replace(/\/\*[\s\S]*?\*\//g, '');
  for (const [, selector, body] of bare.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const declarations: Declarations = {};
    for (const declaration of body.split(';')) {
      const colon = declaration.indexOf(':');
      if (colon > 0) {
        const value = declaration.slice(colon + 1).replace(/\s+/g, ' ');
        declarations[declaration.slice(0, colon).trim()] = value.trim();
      }
    }
    parsed[selector.replace(/\s+/g, ' ').trim()] = declarations;
  }
  return parsed;
}

const PROBED = blocks(compile(PROBE));
const KNOBS = PROBED['knobs'];
const knob = (name: string): number => Number.parseFloat(KNOBS[name]);

const mix = (toward: string, token: string): string =>
  `color-mix(in oklab, var(--rim-base), ${toward} var(--${token}))`;

const rim = (prefix: string): string =>
  `linear-gradient(${KNOBS['angle']}, ${mix('white', `${prefix}glint`)}, ` +
  `var(--rim-base) ${KNOBS['base-from']}, ${mix('black', `${prefix}shade`)} ${KNOBS['shade-at']}, ` +
  `var(--rim-base) ${KNOBS['base-to']}, ${mix('white', `${prefix}glint-faint`)}) border-box`;

/** Sass rounds each amount to one decimal; the exact product may sit on a .x5 boundary. */
function expectOneDecimalOf(emitted: string, exact: number): void {
  expect(emitted).toMatch(/^\d+(\.\d)?%$/);
  expect(Math.abs(Number.parseFloat(emitted) - exact)).toBeLessThanOrEqual(0.05 + 1e-9);
}

describe.each(['light', 'dark'] as const)('%s rim tokens', (mode) => {
  const tokens = PROBED[mode];
  const glint = knob(`${mode}-glint`) * knob('strength');
  const shade = knob(`${mode}-shade`) * knob('strength');
  const boost = knob('control-boost');
  const faint = knob('faint-ratio');

  it('scales the mode’s glint and shade by the overall strength', () => {
    expectOneDecimalOf(tokens['--rim-glint'], glint);
    expectOneDecimalOf(tokens['--rim-shade'], shade);
  });

  it('makes the bottom-right glint the faint ratio of the top-left one', () => {
    expectOneDecimalOf(tokens['--rim-glint-faint'], glint * faint);
    expectOneDecimalOf(tokens['--rim-control-glint-faint'], glint * boost * faint);
  });

  it('gives controls the boosted surface amounts', () => {
    expectOneDecimalOf(tokens['--rim-control-glint'], glint * boost);
    expectOneDecimalOf(tokens['--rim-control-shade'], shade * boost);
  });

  it('lands in the theme block of tokens.scss', () => {
    const themed = blocks(sass.compile(join(__dirname, 'tokens.scss'), { style: 'expanded' }).css);
    const selector = mode === 'light' ? ':root, :root[data-theme=light]' : ':root[data-theme=dark]';
    for (const [name, value] of Object.entries(tokens)) {
      expect(`${name}: ${themed[selector][name]}`).toBe(`${name}: ${value}`);
    }
  });
});

it('refuses an unknown mode', () => {
  expect(() => compile(`@use 'glass-rim'; x { @include glass-rim.tokens(sepia); }`)).toThrow(
    /mode/,
  );
});

describe('filled', () => {
  it('paints the fill over the padding box and the rim over the border box', () => {
    expect(PROBED['.box']).toEqual({
      '--rim-base': 'var(--border)',
      '--rim-fill': 'var(--surface-1)',
      border: '1px solid transparent',
      background: `linear-gradient(var(--rim-fill), var(--rim-fill)) padding-box, ${rim('rim-')}`,
    });
  });

  it('reads the control tokens for the control tier', () => {
    expect(PROBED['.chip']).toEqual({
      '--rim-base': 'var(--accent)',
      '--rim-fill': 'var(--surface-2)',
      border: '1px solid transparent',
      background: `linear-gradient(var(--rim-fill), var(--rim-fill)) padding-box, ${rim('rim-control-')}`,
    });
  });

  it('refuses an unknown tier', () => {
    expect(() =>
      compile(`@use 'glass-rim'; x { @include glass-rim.filled(red, red, loud); }`),
    ).toThrow(/tier/);
  });
});

describe('ring', () => {
  it('keeps the host see-through behind a transparent border', () => {
    expect(PROBED['.dot']).toEqual({
      '--rim-base': 'var(--border-strong)',
      position: 'relative',
      border: '1px solid transparent',
    });
  });

  it('paints the rim on an ::after masked to the border area', () => {
    expect(PROBED['.dot::after']).toEqual({
      content: '""',
      position: 'absolute',
      inset: '-1px',
      padding: '1px',
      'border-radius': 'inherit',
      'pointer-events': 'none',
      background: rim('rim-control-'),
      mask: 'linear-gradient(#000 0 0) content-box exclude, linear-gradient(#000 0 0)',
    });
  });

  it('refuses an unknown tier', () => {
    expect(() => compile(`@use 'glass-rim'; x { @include glass-rim.ring(red, loud); }`)).toThrow(
      /tier/,
    );
  });
});
