import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * The CDK ships the structural styles for its overlays as a separate
 * stylesheet. Nothing imports it implicitly: without it, `.cdk-overlay-container`
 * and friends carry no rules at all, so a dialog is not fixed, not centred and
 * does not block scrolling -- it renders as a plain block appended to <body>
 * after the 100vh reader shell, i.e. a full viewport below the fold. That was
 * #85, and it is invisible in unit tests (jsdom applies no stylesheets) and in
 * review, which is why it survived this long.
 *
 * Asserting on the source rather than a built bundle keeps this cheap enough to
 * run on every commit; the build itself is what turns the import into CSS.
 */
describe('global stylesheet', () => {
  const styles = readFileSync(join(__dirname, 'styles.scss'), 'utf8');

  it('loads the CDK overlay styles that every dialog depends on', () => {
    expect(styles).toMatch(/@(use|import)\s+['"]@angular\/cdk\/overlay-prebuilt\.css['"]/);
  });
});
