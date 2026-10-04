import { join } from 'node:path';
import * as sass from 'sass';

const SIZES = {
  '2xs': '10px',
  xs: '11px',
  sm: '13px',
  base: '15px',
  read: '16px',
  lg: '18px',
  xl: '24px',
};

function compile(file: string): string {
  return sass
    .compile(join(__dirname, file), { style: 'expanded' })
    .css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function ruleBody(css: string, selector: string): string {
  const start = css.indexOf(`${selector} {`);
  if (start < 0) throw new Error(`No rule for ${selector}`);
  return css.slice(start, css.indexOf('}', start));
}

describe('type tokens', () => {
  const tokens = compile('tokens.scss');

  it.each(Object.entries(SIZES))('keeps --fs-%s at its fixed size on the root', (name, size) => {
    expect(tokens).toContain(`--fs-${name}: ${size};`);
  });
});

describe('text-scaled scope', () => {
  const css = compile('../../styles/_text-size.scss');
  const scope = ruleBody(css, '.text-scaled');
  const reset = ruleBody(
    css,
    ':where(.text-scaled) :where(button:not(.reads-as-text), app-entry-pills)',
  );

  it.each(Object.entries(SIZES))('multiplies --fs-%s by the text scale', (name, size) => {
    expect(scope).toContain(`--fs-${name}: calc(${size} * var(--text-scale, 1));`);
  });

  it('scales text that only inherits its size', () => {
    expect(scope).toContain('font-size: calc(1em * var(--text-scale, 1));');
  });

  it.each(Object.entries(SIZES))('puts --fs-%s back to fixed on controls', (name, size) => {
    expect(reset).toContain(`--fs-${name}: ${size};`);
  });

  it('divides the inherited scale back out on controls', () => {
    expect(reset).toContain('font-size: calc(1em / var(--text-scale, 1));');
  });
});
