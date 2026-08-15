import { HttpErrorResponse } from '@angular/common/http';
import { SERVER_TEXT_KINDS, aiFailure } from './ai-failure';

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

  it('reads a rejected body as a validation failure and keeps every field message', () => {
    const failure = aiFailure(
      response(422, {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        detail: 'One or more fields are invalid.',
        errors: {
          apiKey: ['This value is too short. It should have 8 characters or more.'],
          baseUrl: ['This value should not be blank.'],
        },
      }),
    );

    expect(failure.kind).toBe('validation');
    expect(failure.detail).toBe('One or more fields are invalid.');
    expect(failure.fieldErrors).toEqual([
      {
        field: 'apiKey',
        messages: ['This value is too short. It should have 8 characters or more.'],
      },
      { field: 'baseUrl', messages: ['This value should not be blank.'] },
    ]);
  });

  it('keeps the server sentence on an unmapped problem type', () => {
    const failure = aiFailure(
      response(400, problem('request_error', 'The body is not valid JSON.', 400)),
    );

    expect(failure.kind).toBe('unknown');
    expect(failure.detail).toBe('The body is not valid JSON.');
  });

  // A production 500 sends no detail on purpose, so there is nothing to show
  // and the translated fallback has to carry the banner.
  it('shows no server text when a 500 withholds its detail', () => {
    const failure = aiFailure(
      response(500, { type: 'internal_error', title: 'Internal server error', status: 500 }),
    );

    expect(failure.kind).toBe('unknown');
    expect(failure.detail).toBeNull();
  });

  it('reports no field errors for the kinds that have none', () => {
    const failure = aiFailure(response(422, problem('ai_provider_rejected', 'Nope.')));

    expect(failure.fieldErrors).toEqual([]);
  });

  it('names the kinds whose banner shows the server sentence', () => {
    expect([...SERVER_TEXT_KINDS].sort()).toEqual(['provider', 'unknown', 'validation']);
  });
});
