// jest.config.ts
import type { Config } from 'jest';
import presets from 'jest-preset-angular/presets/index.js';

export default {
  displayName: 'frontend',
  ...presets.createCjsPreset(),
  setupFilesAfterEnv: ['<rootDir>/setup-jest.ts'],
  // Transloco and its @jsverse/utils dep ship untranspiled ESM; let Jest transform
  // them instead of choking on `export` in node_modules.
  transformIgnorePatterns: ['node_modules/(?!.*\\.mjs$|@jsverse)'],
  // Path fragments, not <rootDir>-anchored: in a git worktree the checkout
  // path contains a literal '+' (a regex metachar), which breaks anchored
  // matches and makes jest try to run the Playwright specs (#615).
  testPathIgnorePatterns: ['/node_modules/', '/e2e/'],
  collectCoverageFrom: ['src/**/*.ts', '!src/**/*.spec.ts', '!src/main.ts'],
} satisfies Config;
