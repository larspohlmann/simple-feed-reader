import { inputValue } from './input-value';

describe('inputValue', () => {
  it('reads the value of the input the event came from', () => {
    const input = document.createElement('input');
    input.value = 'typed text';
    const event = new Event('input');
    input.dispatchEvent(event);

    expect(inputValue(event)).toBe('typed text');
  });
});
