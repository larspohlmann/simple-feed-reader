import { AXIS_LOCK_MIN, isSlideSwipe } from '../../reader-gestures';

/** Arrow keys and swipes on a slideshow figure step it; the returned function unbinds them. */
export function bindSlideGestures(figure: HTMLElement, step: (delta: number) => void): () => void {
  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'ArrowRight') step(1);
    else if (event.key === 'ArrowLeft') step(-1);
    else return;
    event.preventDefault();
  };

  // The reader body is a horizontally-swipeable "back to list" surface, so the
  // carousel claims horizontal drags (stop them reaching it) while letting a
  // vertical drag bubble, so the article still scrolls when dragging on an image.
  let startX = 0;
  let startY = 0;
  let axis: 'none' | 'horizontal' | 'vertical' = 'none';
  const onTouchstart = (event: TouchEvent): void => {
    startX = event.changedTouches[0]?.clientX ?? 0;
    startY = event.changedTouches[0]?.clientY ?? 0;
    axis = 'none';
  };
  const onTouchmove = (event: TouchEvent): void => {
    const dx = (event.changedTouches[0]?.clientX ?? 0) - startX;
    const dy = (event.changedTouches[0]?.clientY ?? 0) - startY;
    if (axis === 'none') {
      if (Math.abs(dx) < AXIS_LOCK_MIN && Math.abs(dy) < AXIS_LOCK_MIN) return;
      axis = Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical';
    }
    if (axis === 'horizontal') {
      event.stopPropagation();
      event.preventDefault();
    }
  };
  const onTouchend = (event: TouchEvent): void => {
    const direction = isSlideSwipe(
      (event.changedTouches[0]?.clientX ?? 0) - startX,
      (event.changedTouches[0]?.clientY ?? 0) - startY,
    );
    if (direction !== 0) {
      step(direction);
      event.stopPropagation();
    }
    axis = 'none';
  };
  const passive: AddEventListenerOptions = { passive: true };
  const blocking: AddEventListenerOptions = { passive: false };

  figure.addEventListener('keydown', onKeydown);
  figure.addEventListener('touchstart', onTouchstart, passive);
  figure.addEventListener('touchmove', onTouchmove, blocking);
  figure.addEventListener('touchend', onTouchend);

  return () => {
    figure.removeEventListener('keydown', onKeydown);
    figure.removeEventListener('touchstart', onTouchstart, passive);
    figure.removeEventListener('touchmove', onTouchmove, blocking);
    figure.removeEventListener('touchend', onTouchend);
  };
}
