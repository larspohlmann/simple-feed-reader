import { join } from 'node:path';
import { compileScssFile, rulesBySelector } from '../../../theme/sass-testing';

const IMAGE_BLOCKS = ['entry-hero', 'entry-wide', 'entry-split', 'entry-thumb'];

const compiledRules = (block: string) =>
  rulesBySelector(compileScssFile(join(__dirname, 'blocks', block, `${block}.component.scss`)));

describe.each(IMAGE_BLOCKS)('%s image', (block) => {
  it('carries the glass rim in the airy rule colour over the sheet', () => {
    const airy = compiledRules(block)[':host-context(.airy) .img'];

    expect(airy['border']).toBe('1px solid transparent');
    expect(airy['background']).toBe(
      'linear-gradient(var(--rim-fill), var(--rim-fill)) padding-box, var(--rim-gradient) border-box',
    );
    expect(airy['--rim-fill']).toBe('var(--airy-sheet-fill)');
    expect(airy['--rim-base']).toBe('var(--airy-rule)');
  });

  it('stays bare in boxed, where the card carries the rim', () => {
    const boxed = compiledRules(block)['.img'];

    expect(boxed['border']).toBeUndefined();
    expect(boxed['background']).toBeUndefined();
  });
});
