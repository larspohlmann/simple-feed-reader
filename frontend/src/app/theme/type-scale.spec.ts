import { join } from 'node:path';
import { compileScssFile, cssRules, Declarations } from './sass-testing';

const SIZES = {
  '2xs': '10px',
  xs: '11px',
  sm: '13px',
  dense: '14px',
  base: '15px',
  read: '16px',
  lg: '18px',
  xl: '24px',
};

const ICON_SIZES = {
  xs: '12px',
  sm: '16px',
  dense: '18px',
  md: '20px',
  lg: '24px',
  xl: '32px',
  '2xl': '36px',
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

  it.each(Object.entries(ICON_SIZES))(
    'keeps --icon-%s at its fixed size on the root',
    (name, size) => {
      expect(root[`--icon-${name}`]).toBe(size);
    },
  );

  it('keeps a favicon at its own size on the root', () => {
    expect(root['--icon-text-scale']).toBe('1');
  });
});

describe('text-scaled scope', () => {
  const scope = ruleOf('../../styles/_text-size.scss', '.text-scaled');

  it.each(Object.entries(SIZES))('multiplies --fs-%s by the text scale', (name, size) => {
    expect(scope[`--fs-${name}`]).toBe(`calc(${size} * var(--text-scale, 1))`);
  });

  it('scales text that only inherits its size', () => {
    expect(scope['font-size']).toBe('calc(1em * var(--text-scale, 1))');
  });

  it.each(Object.entries(ICON_SIZES))('multiplies --icon-%s by the text scale', (name, size) => {
    expect(scope[`--icon-${name}`]).toBe(`calc(${size} * var(--text-scale, 1))`);
  });

  it('scales a favicon and an avatar with the text', () => {
    expect(scope['--icon-text-scale']).toBe('var(--text-scale, 1)');
  });

  it('leaves no rule that puts a control back to fixed type', () => {
    const selectors = cssRules(
      compileScssFile(join(__dirname, '../../styles/_text-size.scss')),
    ).map((rule) => rule.selector);
    expect(selectors).toEqual(['.text-scaled']);
  });
});
