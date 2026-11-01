import { openExternal } from './open-external';

describe('openExternal', () => {
  it('opens the address in a new tab without opener or referrer', () => {
    const open = jest.spyOn(window, 'open').mockImplementation(() => null);

    openExternal('https://example.com/a');

    expect(open).toHaveBeenCalledWith('https://example.com/a', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });
});
