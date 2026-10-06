import { join } from 'node:path';
import { compileScssFile, cssRules, Declarations } from './sass-testing';

const SIZES = {
  '2xs': '10px',
  xs: '11px',
  sm: '13px',
  base: '15px',
  read: '16px',
  lg: '18px',
  xl: '24px',
};

function ruleOf(file: string, selector: string): Declarations {
  const rule = cssRules(compileScssFile(join(__dirname, file))).find(
    (candidate) => candidate.selector === selector,
  );
  if (!rule) throw new Error(`No rule for ${selector}`);
  return rule.declarations;
}

describe('type tokens', () => {
  const root = ruleOf('tokens.scss', ':root');

  it.each(Object.entries(SIZES))('keeps --fs-%s at its fixed size on the root', (name, size) => {
    expect(root[`--fs-${name}`]).toBe(size);
  });
});

describe('text-scaled scope', () => {
  const file = '../../styles/_text-size.scss';
  const scope = ruleOf(file, '.text-scaled');
  const reset = ruleOf(
    file,
    ':where(.text-scaled) :is(button:where(:not(.reads-as-text)), app-entry-pills)',
  );

  it.each(Object.entries(SIZES))('multiplies --fs-%s by the text scale', (name, size) => {
    expect(scope[`--fs-${name}`]).toBe(`calc(${size} * var(--text-scale, 1))`);
  });

  it('scales text that only inherits its size', () => {
    expect(scope['font-size']).toBe('calc(1em * var(--text-scale, 1))');
  });

  it.each(Object.entries(SIZES))('puts --fs-%s back to fixed on controls', (name, size) => {
    expect(reset[`--fs-${name}`]).toBe(size);
  });

  it('divides the inherited scale back out on controls', () => {
    expect(reset['font-size']).toBe('calc(1em / var(--text-scale, 1))');
  });
});
