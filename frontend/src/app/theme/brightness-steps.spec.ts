import { join } from 'node:path';
import * as sass from 'sass';

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

const COLOUR_TOKENS = Object.keys(TODAY.light).filter((token) => token !== 'favicon-backdrop');

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

  it('parses as colours', () => {
    for (const token of COLOUR_TOKENS) expect(rgb(paletteAt(theme, 0)[token])).toHaveLength(3);
  });
});
