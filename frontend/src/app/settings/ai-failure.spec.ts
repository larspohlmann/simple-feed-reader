import { HttpErrorResponse } from '@angular/common/http';
import { aiFailure } from './ai-failure';

describe('aiFailure', () => {
  const response = (status: number, body: unknown): HttpErrorResponse =>
    new HttpErrorResponse({ status, error: body, url: '/api/me/ai/models' });

  const problem = (type: string, detail: string, status = 422) => ({
    type,
    title: 'The AI provider could not be used',
    status,
    detail,
  });

  it('reads an unreachable endpoint as an ordinary provider refusal, with the server sentence', () => {
    const failure = aiFailure(
      response(422, problem('ai_provider_rejected', 'That endpoint did not answer.')),
    );

    expect(failure.kind).toBe('provider');
    expect(failure.detail).toBe('That endpoint did not answer.');
  });

  it('reads a refused key as an ordinary provider refusal', () => {
    const failure = aiFailure(
      response(422, problem('ai_provider_rejected', 'That provider refused the API key.')),
    );

    expect(failure.kind).toBe('provider');
  });

  it('reads the unreadable stored key by its own type', () => {
    const failure = aiFailure(
      response(
        422,
        problem('ai_key_unreadable', 'The stored API key can no longer be read. Enter it again.'),
      ),
    );

    expect(failure.kind).toBe('unreadableKey');
  });

  // The prose the backend sends is not part of the contract. A reworded detail
  // must still classify as the unreadable key, and the sentence on its own must
  // no longer classify as anything.
  it('ignores the detail when it decides the kind', () => {
    expect(aiFailure(response(422, problem('ai_key_unreadable', 'Anything at all.'))).kind).toBe(
      'unreadableKey',
    );
    expect(
      aiFailure(
        response(422, problem('ai_provider_rejected', 'The stored API key can no longer be read.')),
      ).kind,
    ).toBe('provider');
  });

  it('reads the rate limit', () => {
    const failure = aiFailure(
      response(429, problem('rate_limited', 'Too many attempts. Try again later.', 429)),
    );

    expect(failure.kind).toBe('rateLimited');
  });

  it('reads a missing configuration', () => {
    const failure = aiFailure(
      response(404, problem('ai_not_configured', 'Save an endpoint and an API key first.', 404)),
    );

    expect(failure.kind).toBe('notConfigured');
  });

  it('reads the configuration limit', () => {
    const failure = aiFailure(
      response(
        409,
        problem(
          'ai_configuration_limit',
          'This account already holds the maximum number of AI configurations.',
          409,
        ),
      ),
    );

    expect(failure.kind).toBe('limit');
  });

  it('falls back to the unknown kind, and shows no server text, when the body is not a problem', () => {
    const failure = aiFailure(response(0, null));

    expect(failure.kind).toBe('unknown');
    expect(failure.detail).toBeNull();
  });
});
