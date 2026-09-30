// @ts-check
const eslint = require("@eslint/js");
const { defineConfig } = require("eslint/config");
const tseslint = require("typescript-eslint");
const angular = require("angular-eslint");
const prettier = require("eslint-config-prettier");
const boundaries = require("eslint-plugin-boundaries");
const unicorn = require("eslint-plugin-unicorn").default;
const sonarjs = require("eslint-plugin-sonarjs");

const FEATURE_IMPORTS = {
  core: ["core", "theme"],
  shared: ["core", "shared", "theme"],
  theme: ["theme"],
  reader: ["core", "shared", "theme", "reader"],
  settings: ["core", "shared", "theme", "settings", "reader", "admin"],
  admin: ["core", "shared", "theme", "admin", "reader"],
  discover: ["core", "shared", "theme", "discover", "reader"],
  auth: ["core", "shared", "theme", "auth"],
  setup: ["core", "shared", "theme", "setup", "auth"],
};

module.exports = defineConfig([
  {
    files: ["**/*.ts"],
    extends: [
      eslint.configs.recommended,
      tseslint.configs.recommended,
      tseslint.configs.stylistic,
      angular.configs.tsRecommended,
      prettier,
    ],
    processor: angular.processInlineTemplates,
    plugins: { boundaries, unicorn, sonarjs },
    settings: {
      "import/resolver": { node: { extensions: [".ts", ".js"] } },
      "boundaries/elements": Object.keys(FEATURE_IMPORTS).map((type) => ({
        type,
        pattern: `src/app/${type}`,
      })),
    },
    rules: {
      "@angular-eslint/directive-selector": [
        "error",
        { type: "attribute", prefix: "app", style: "camelCase" },
      ],
      "@angular-eslint/component-selector": [
        "error",
        { type: "element", prefix: "app", style: "kebab-case" },
      ],
      "boundaries/dependencies": [
        "error",
        {
          default: "disallow",
          policies: Object.entries(FEATURE_IMPORTS).map(([from, to]) => ({
            from: { element: { type: from } },
            allow: { to: { element: { types: { anyOf: to } } } },
          })),
        },
      ],
      "max-lines": ["warn", { max: 300, skipBlankLines: true, skipComments: true }],
      "max-lines-per-function": ["warn", { max: 60, skipBlankLines: true, skipComments: true }],
      complexity: ["warn", 10],
      "@typescript-eslint/max-params": ["warn", { max: 3 }],
      "sonarjs/cognitive-complexity": ["warn", 15],
      "sonarjs/no-identical-functions": "error",
      "id-length": ["warn", { min: 2, exceptions: ["x", "y", "_"], properties: "never" }],
      "unicorn/prevent-abbreviations": [
        "warn",
        {
          checkFilenames: false,
          checkProperties: false,
          replacements: {
            svc: { service: true },
            recs: { recommendations: true },
            subs: { subscriptions: true },
            hdr: { header: true },
            grp: { group: true },
          },
        },
      ],
    },
  },
  {
    files: ["src/app/**/*.component.ts", "src/app/**/*.store.ts"],
    rules: {
      "no-restricted-imports": [
        "warn",
        {
          paths: [
            {
              name: "@angular/common/http",
              importNames: ["HttpClient"],
              message: "Call HTTP through the feature's *-api.ts file (#1300).",
            },
          ],
        },
      ],
    },
  },
  {
    files: ["**/*.spec.ts"],
    rules: {
      "boundaries/dependencies": "off",
      "max-lines": "off",
      "max-lines-per-function": "off",
      "sonarjs/no-identical-functions": "off",
    },
  },
  {
    files: ["**/*.html"],
    extends: [angular.configs.templateRecommended, angular.configs.templateAccessibility],
    rules: {
      "@angular-eslint/template/cyclomatic-complexity": ["warn", { maxComplexity: 25 }],
    },
  },
]);
