import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * The CDK ships its overlay styles separately; without importing them a
 * dialog isn't fixed, centred, or scroll-blocking (#85) -- invisible in
 * jsdom and in review. Asserts the source import, cheaper than the build.
 */
describe('global stylesheet', () => {
  const styles = readFileSync(join(__dirname, 'styles.scss'), 'utf8');

  it('loads the CDK overlay styles that every dialog depends on', () => {
    expect(styles).toMatch(/@(use|import)\s+['"]@angular\/cdk\/overlay-prebuilt\.css['"]/);
  });
});
