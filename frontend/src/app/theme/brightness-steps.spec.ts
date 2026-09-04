import { join } from 'node:path';
import * as sass from 'sass';
import { BRIGHTNESS_MAX, BRIGHTNESS_MIN } from './brightness';

type Palette = Record<string, string>;
type Rgb = [number, number, number];
type Theme = 'light' | 'dark';

/** Today's palette, verbatim: step 0 must never drift from it (#832). */
const TODAY: Record<Theme, Palette> = {
  light: {
    'surface-0': '#f5f5f4',
    'surface-1': '#fff',
    'surface-2': '#fff',
    'surface-read': '#f5f5f4',
    border: '#e4e4e2',
    'border-strong': '#d4d4d1',
    'text-primary': '#2a2a2a',
    'text-secondary': '#5f5f5c',
    'text-muted': '#8f8f8b',
    accent: '#3f8676',
    'accent-soft': '#e9f1ef',
    'on-accent': '#fff',
    danger: '#b3403a',
    'bg-danger': '#f7e9e8',
    success: '#3f7a52',
    'bg-success': '#e9f1ec',
    warning: '#8a5a00',
    'bg-warning': '#fbf0d9',
    'favicon-backdrop': 'transparent',
  },
  dark: {
    'surface-0': '#161616',
    'surface-1': '#1c1c1c',
    'surface-2': '#242424',
    'surface-read': '#1e1e1e',
    border: '#2a2a2a',
    'border-strong': '#383836',
    'text-primary': '#c2c2c0',
    'text-secondary': '#9a9a97',
    'text-muted': '#6a6a67',
    accent: '#5aa694',
    'accent-soft': '#20302c',
    'on-accent': '#0f1a17',
    danger: '#d98a86',
    'bg-danger': '#2a1e1d',
    success: '#7faf8c',
    'bg-success': '#1c2620',
    warning: '#e6b768',
    'bg-warning': '#302611',
    'favicon-backdrop': '#e8e8e6',
  },
};

interface Block {
  selector: string;
  tokens: Palette;
}

function compileBlocks(): Block[] {
  const css = sass
    .compile(join(__dirname, 'tokens.scss'), { style: 'expanded' })
    .css.replace(/\/\*[\s\S]*?\*\//g, '');
  return Array.from(css.matchAll(/([^{}]+)\{([^{}]*)\}/g), ([, selector, body]) => {
    const tokens: Palette = {};
    for (const declaration of body.split(';')) {
      const colon = declaration.indexOf(':');
      const name = declaration.slice(0, colon).trim();
      if (name.startsWith('--')) tokens[name.slice(2)] = declaration.slice(colon + 1).trim();
    }
    return { selector: selector.trim(), tokens };
  });
}

const BLOCKS = compileBlocks();

function paletteAt(theme: Theme, step: number): Palette {
  const block = BLOCKS.find((candidate) =>
    step === 0
      ? candidate.selector.includes(`[data-theme=${theme}]`) &&
        !candidate.selector.includes('data-brightness')
      : candidate.selector.includes(`[data-theme=${theme}][data-brightness="${step}"]`),
  );
  if (!block) throw new Error(`No block for ${theme} step ${step}`);
  return block.tokens;
}

function rgb(value: string): Rgb {
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value);
  if (hex) {
    const digits = hex[1].length === 3 ? [...hex[1]].map((d) => d + d).join('') : hex[1];
    return [0, 2, 4].map((offset) => parseInt(digits.slice(offset, offset + 2), 16)) as Rgb;
  }
  const fn = /^rgb\(([\d.]+),\s*([\d.]+),\s*([\d.]+)\)$/.exec(value);
  if (fn) return [Number(fn[1]), Number(fn[2]), Number(fn[3])];
  throw new Error(`Not a colour: ${value}`);
}

describe.each(['light', 'dark'] as const)('%s step 0', (theme) => {
  it('is today’s palette, byte for byte', () => {
    const base = paletteAt(theme, 0);
    for (const token of Object.keys(TODAY[theme])) {
      expect(`${token}: ${base[token]}`).toBe(`${token}: ${TODAY[theme][token]}`);
    }
  });
});

/** Every step of the theme's range except 0, which is the base block. */
function stepsOf(theme: Theme): number[] {
  const steps: number[] = [];
  for (let step = BRIGHTNESS_MIN[theme]; step <= BRIGHTNESS_MAX[theme]; step++) {
    if (step !== 0) steps.push(step);
  }
  return steps;
}

const STEPS: Record<Theme, readonly number[]> = { light: stepsOf('light'), dark: stepsOf('dark') };
const SURFACES = ['surface-0', 'surface-1', 'surface-2', 'surface-read'];
// Every colour token is scaled; only favicon-backdrop is not.
const SCALED = Object.keys(TODAY.light).filter((token) => token !== 'favicon-backdrop');
const TEXT = ['text-primary', 'text-secondary', 'text-muted'];
const MEDIA: Partial<Record<number, string>> = {
  [-1]: '0.92',
  [-2]: '0.84',
  [-3]: '0.76',
  [-4]: '0.68',
  [-5]: '0.6',
  [-6]: '0.52',
};
const TOLERANCE = 0.02;

const linear = (channel: number): number => {
  const c = channel / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};
const luminance = ([r, g, b]: Rgb): number =>
  0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
const contrast = (a: string, b: string): number => {
  const [high, low] = [luminance(rgb(a)), luminance(rgb(b))].sort((x, y) => y - x);
  return (high + 0.05) / (low + 0.05);
};
const weakest = (token: string, palette: Palette): number =>
  Math.min(...SURFACES.map((surface) => contrast(palette[token], palette[surface])));

function emittedSteps(theme: Theme): number[] {
  return BLOCKS.flatMap((block) => {
    const match = new RegExp(`\\[data-theme=${theme}\\]\\[data-brightness="?(-?\\d)"?\\]`).exec(
      block.selector,
    );
    return match ? [Number(match[1])] : [];
  }).sort((a, b) => a - b);
}

describe.each(['light', 'dark'] as const)('%s brightness steps', (theme) => {
  const base = paletteAt(theme, 0);

  it('emits exactly the steps brightness.ts declares', () => {
    expect(emittedSteps(theme)).toEqual([...STEPS[theme]]);
  });

  it('carries no media factor at step 0', () => {
    expect(base['media-brightness']).toBeUndefined();
  });

  it.each(STEPS[theme])('step %i redefines every colour token and nothing else', (step) => {
    const mediaToken = step < 0 ? ['media-brightness'] : [];
    expect(Object.keys(paletteAt(theme, step)).sort()).toEqual([...SCALED, ...mediaToken].sort());
  });

  it.each(STEPS[theme])('step %i moves the canvas and the text with the step', (step) => {
    const palette = paletteAt(theme, step);
    for (const token of ['surface-0', ...TEXT]) {
      const now = luminance(rgb(palette[token]));
      const today = luminance(rgb(base[token]));
      if (step < 0) expect(now).toBeLessThan(today);
      else expect(now).toBeGreaterThan(today);
    }
  });

  it.each(STEPS[theme])(
    'step %i eases the body-text contrast when dimming, strengthens it when brightening',
    (step) => {
      const now = weakest('text-primary', paletteAt(theme, step));
      const today = weakest('text-primary', base);
      if (step < 0) expect(now).toBeLessThanOrEqual(today + TOLERANCE);
      else expect(now).toBeGreaterThanOrEqual(today - TOLERANCE);
    },
  );

  it.each(STEPS[theme])('step %i dims media only when dimming', (step) => {
    expect(paletteAt(theme, step)['media-brightness']).toBe(MEDIA[step]);
  });
});

it('turns the body text pure white at the brightest dark step', () => {
  expect(rgb(paletteAt('dark', 3)['text-primary'])).toEqual([255, 255, 255]);
});

it('turns the body text pure black at the darkest light step', () => {
  expect(rgb(paletteAt('light', -6)['text-primary'])).toEqual([0, 0, 0]);
});
