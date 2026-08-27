import jestConfig from './jest.config';

// Regression guard for #615: in a git worktree the checkout path contains a
// literal '+', a regex metachar. Anchored '<rootDir>/e2e/' patterns stop
// matching there and jest runs the Playwright specs. Fragment patterns are
// immune. We prove the patterns match a realistic worktree path.
describe('jest testPathIgnorePatterns (#615)', () => {
  const patterns = (jestConfig.testPathIgnorePatterns ?? []).map((p) => new RegExp(p));
  const worktreeE2ePath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/e2e/auth-smoke.spec.ts';
  const worktreeNodeModulesPath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/node_modules/pkg/some.spec.ts';
  const realSpecPath =
    '/Users/dev/project/.git/worktrees/fix+612-x/frontend/src/app/reader/reader.spec.ts';

  it('ignores the e2e directory even when the path contains a "+"', () => {
    expect(patterns.some((re) => re.test(worktreeE2ePath))).toBe(true);
  });

  it('ignores node_modules even when the path contains a "+"', () => {
    expect(patterns.some((re) => re.test(worktreeNodeModulesPath))).toBe(true);
  });

  it('does not ignore a real unit spec under src/', () => {
    expect(patterns.some((re) => re.test(realSpecPath))).toBe(false);
  });
});
