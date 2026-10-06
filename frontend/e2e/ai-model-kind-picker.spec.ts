import { test, expect, Page } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

/**
 * The kind-first model picker over a stubbed configuration and model list, so the
 * spec owns every value it asserts on: nothing is saved, and no outbound call
 * reaches a real provider. Matched on the pathname, so `/api/me/ai` and
 * `/api/me/ai/configs/1/models` do not catch each other.
 */
const LLM_CAPABILITIES = {
  reasons: true,
  prompt: true,
  profile: 'own',
  tuningFields: [
    'contextWindow',
    'batchSize',
    'suppressReasoning',
    'slowModel',
    'maxBatchSize',
    'batchConcurrency',
  ],
};

const SCORING_CAPABILITIES = {
  reasons: false,
  prompt: false,
  profile: 'borrowed',
  tuningFields: ['batchConcurrency'],
};

const CONFIG = {
  id: 1,
  name: 'Stubbed provider',
  baseUrl: 'https://stubbed.example.test/v1',
  apiKeyHint: '9876',
  model: 'acme/chat-1',
  kind: 'llm',
  family: null,
  ready: true,
  active: true,
  suppressReasoning: true,
  suppressionRefused: false,
  batchConcurrency: 1,
  slowModel: false,
  maxBatchSize: null,
  capabilities: LLM_CAPABILITIES,
};

const MODELS = [
  { id: 'acme/chat-1', label: null, kind: 'llm', family: null, capabilities: LLM_CAPABILITIES },
  {
    id: 'acme/decider-1',
    label: 'System One',
    kind: 'scoring',
    family: 'decision',
    capabilities: SCORING_CAPABILITIES,
  },
  {
    id: 'acme/reranker-1',
    label: 'Rerank',
    kind: 'scoring',
    family: 'reranker',
    capabilities: SCORING_CAPABILITIES,
  },
];

async function stubAi(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me/ai',
    (route) =>
      route.fulfill({
        status: 200,
        json: { configs: [CONFIG], activeId: CONFIG.id, defaultMaxBatchSize: 50 },
      }),
  );
  await page.route(
    (url) => url.pathname === `/api/me/ai/configs/${CONFIG.id}/models`,
    (route) => route.fulfill({ status: 200, json: { models: MODELS } }),
  );
}

test('the model picker asks for the kind first and lists only that kind', async ({ page }) => {
  await stubAi(page);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/ai');
  await page.getByRole('button', { name: 'Manage' }).click();
  const row = page.locator('.config-row').first();
  await row.locator('summary').click();
  await row.locator('.change-model button').click();

  const kinds = row.getByRole('group', { name: 'Model type' });
  await expect(kinds.getByRole('button', { name: 'LLM', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  const select = row.locator('app-searchable-select');
  await select.locator('.trigger').click();
  await expect(select.getByRole('option')).toHaveText(['acme/chat-1']);
  await select.locator('.trigger').click();

  await kinds.getByRole('button', { name: 'Scoring model', exact: true }).click();

  await expect(row.locator('.scoring-hint')).toContainText('probability');
  await expect(row.locator('.scoring-hint')).toContainText('only ranks the articles of one run');
  await select.locator('.trigger').click();
  await expect(select.getByRole('option')).toHaveCount(2);
  await expect(select.getByRole('option')).toContainText([
    'acme/decider-1 · Decision model',
    'acme/reranker-1 · Reranker',
  ]);
});
