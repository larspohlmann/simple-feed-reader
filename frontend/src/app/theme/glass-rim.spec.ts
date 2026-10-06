import { join } from 'node:path';
import { compileScssFile, compileScssSource, rulesBySelector } from './sass-testing';

const PROBE = `
@use 'sass:map';
@use 'glass-rim';

knobs {
  strength: glass-rim.$strength;
  control-boost: glass-rim.$control-boost;
  image-boost: glass-rim.$image-boost;
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

.picker {
  @include glass-rim.layers(control, (linear-gradient(red, red) right / 4px 4px no-repeat, linear-gradient(blue, blue)));
}
`;

const compile = (source: string): string => compileScssSource(source, [__dirname]);

const PROBED = rulesBySelector(compile(PROBE));
const KNOBS = PROBED['knobs'];
const knob = (name: string): number => Number.parseFloat(KNOBS[name]);

const mix = (toward: string, token: string): string =>
  `color-mix(in oklab, var(--rim-base), ${toward} var(--${token}))`;

const gradient = (prefix: string): string =>
  `linear-gradient(${KNOBS['angle']}, ${mix('var(--rim-light)', `${prefix}glint`)}, ` +
  `var(--rim-base) ${KNOBS['base-from']}, ${mix('black', `${prefix}shade`)} ${KNOBS['shade-at']}, ` +
  `var(--rim-base) ${KNOBS['base-to']}, ${mix('var(--rim-light)', `${prefix}glint-faint`)})`;

const rim = (prefix: string): string => `var(--${prefix}gradient) border-box`;

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

  it('gives images their own, stronger boost', () => {
    const imageBoost = knob('image-boost');

    expect(imageBoost).toBeGreaterThan(boost);
    expectOneDecimalOf(tokens['--rim-image-glint'], Math.min(100, glint * imageBoost));
    expectOneDecimalOf(
      tokens['--rim-image-glint-faint'],
      Math.min(100, glint * imageBoost * faint),
    );
    expectOneDecimalOf(tokens['--rim-image-shade'], Math.min(100, shade * imageBoost));
  });

  it('caps every amount at 100%, a valid color-mix share', () => {
    for (const [name, value] of Object.entries(tokens)) {
      if (name !== '--rim-light') {
        expect(Number.parseFloat(value)).toBeLessThanOrEqual(100);
      }
    }
  });

  it('emits white as the step-0 highlight colour', () => {
    expect(tokens['--rim-light']).toBe('white');
  });

  it('lands in the theme block of tokens.scss', () => {
    const themed = rulesBySelector(compileScssFile(join(__dirname, 'tokens.scss')));
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

describe('gradients', () => {
  const global = compileScssFile(join(__dirname, '..', '..', 'styles.scss'), [
    join(__dirname, '..', '..', '..', 'node_modules'),
  ]);

  it('declares every tier gradient once, on every element, in the global stylesheet', () => {
    expect(global.match(/--rim-gradient:/g)).toHaveLength(1);
    expect(global.match(/--rim-control-gradient:/g)).toHaveLength(1);
    expect(global.match(/--rim-image-gradient:/g)).toHaveLength(1);
    expect(Object.keys(rulesBySelector(global)[':where(*)'])).toEqual([
      '--rim-gradient',
      '--rim-control-gradient',
      '--rim-image-gradient',
    ]);
  });

  it.each([
    ['surface', 'rim-'],
    ['control', 'rim-control-'],
    ['image', 'rim-image-'],
  ])('glints the %s rim toward the light and shades it toward black', (_tier, prefix) => {
    expect(rulesBySelector(global)[':where(*)'][`--${prefix}gradient`]).toBe(gradient(prefix));
  });
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

describe('layers', () => {
  it('stacks the overlays above the fill and the rim without touching the rim tokens', () => {
    expect(PROBED['.picker']).toEqual({
      background:
        'linear-gradient(red, red) right/4px 4px no-repeat, linear-gradient(blue, blue), ' +
        `linear-gradient(var(--rim-fill), var(--rim-fill)) padding-box, ${rim('rim-control-')}`,
    });
  });

  it('refuses an unknown tier', () => {
    expect(() => compile(`@use 'glass-rim'; x { @include glass-rim.layers(loud); }`)).toThrow(
      /tier/,
    );
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
