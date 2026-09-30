import * as beacon from './client-error-beacon';
import { revealBootErrorSurface } from './boot-error-surface';

describe('revealBootErrorSurface', () => {
  it('reveals the static surface and beacons the error', () => {
    const surface = document.createElement('div');
    surface.id = 'boot-error';
    surface.hidden = true;
    document.body.appendChild(surface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    const reportBootError = jest
      .spyOn(beacon, 'reportBootError')
      .mockImplementation(() => undefined);

    const error = new Error('bootstrap rejected');
    revealBootErrorSurface(error);

    expect(surface.hasAttribute('hidden')).toBe(false);
    expect(reportBootError).toHaveBeenCalledWith(error);

    surface.remove();
    jest.restoreAllMocks();
  });
});
