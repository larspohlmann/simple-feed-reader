// jest.config.ts
import type { Config } from 'jest';
import presets from 'jest-preset-angular/presets/index.js';

export default {
  displayName: 'frontend',
  ...presets.createCjsPreset(),
  setupFilesAfterEnv: ['<rootDir>/setup-jest.ts'],
  testPathIgnorePatterns: ['<rootDir>/node_modules/', '<rootDir>/e2e/'],
  collectCoverageFrom: ['src/**/*.ts', '!src/**/*.spec.ts', '!src/main.ts'],
} satisfies Config;
