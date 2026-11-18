import { connectionLabel, unusableConnectionId } from './connection-choice';

const candidate = (overrides: object = {}) => ({
  id: 7,
  name: 'Local',
  baseUrl: 'http://localhost:1234/v1',
  model: 'qwen',
  ...overrides,
});

describe('connectionLabel', () => {
  it('joins the name and the model', () => {
    expect(connectionLabel(candidate())).toBe('Local · qwen');
  });

  it('falls back to the host without a name and omits a missing model', () => {
    expect(connectionLabel(candidate({ name: null, model: null }))).toBe('localhost:1234');
  });
});

describe('unusableConnectionId', () => {
  it('is null without a pick', () => {
    expect(unusableConnectionId(null, [candidate()])).toBeNull();
  });

  it('is null for a pick that is a candidate', () => {
    expect(unusableConnectionId(7, [candidate()])).toBeNull();
  });

  it('is the pick when no candidate has its id', () => {
    expect(unusableConnectionId(99, [candidate()])).toBe(99);
  });
});
