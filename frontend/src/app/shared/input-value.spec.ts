import { inputChecked, inputValue } from './input-value';

describe('inputValue', () => {
  it('reads the value of the input the event came from', () => {
    const input = document.createElement('input');
    input.value = 'typed text';
    const event = new Event('input');
    input.dispatchEvent(event);

    expect(inputValue(event)).toBe('typed text');
  });
});

describe('inputChecked', () => {
  it('reads whether the checkbox the event came from is checked', () => {
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.checked = true;
    const event = new Event('change');
    box.dispatchEvent(event);

    expect(inputChecked(event)).toBe(true);
  });
});
