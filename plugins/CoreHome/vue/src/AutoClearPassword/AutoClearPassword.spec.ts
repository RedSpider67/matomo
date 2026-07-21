/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

import AutoClearPassword from './AutoClearPassword';

interface DirectiveLike {
  mounted: (el: HTMLElement, binding: { value?: { delay?: number } }) => void;
  unmounted: (el: HTMLElement) => void;
}

const directive = AutoClearPassword as unknown as DirectiveLike;

function mountInput(value = 'secret'): HTMLInputElement {
  const el = document.createElement('input');
  el.type = 'password';
  el.value = value;
  document.body.appendChild(el);
  directive.mounted(el, { value: { delay: 1 } });
  return el;
}

function firePageHide(persisted: boolean): void {
  window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted }));
}

describe('CoreHome/AutoClearPassword', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
  });

  it('marks the input as enabled on mount', () => {
    const el = mountInput();

    expect(el.dataset.autoClearEnabled).toBe('true');
  });

  it('clears the field and removes all state on a real navigation (pagehide)', () => {
    const el = mountInput();

    firePageHide(false);

    expect(el.value).toBe('');
    expect(el.dataset.autoClearEnabled).toBeUndefined();
    expect((el as { onUmounted?: unknown }).onUmounted).toBeUndefined();
  });

  it('keeps the watcher armed but drops the value when entering the bfcache', () => {
    const el = mountInput();

    firePageHide(true);

    // Value is dropped ...
    expect(el.value).toBe('');
    // ... but the directive stays armed so the restored page is still protected.
    expect(el.dataset.autoClearEnabled).toBe('true');

    const removeSpy = vi.spyOn(window, 'removeEventListener');
    firePageHide(false);
    expect(removeSpy).toHaveBeenCalledWith('pagehide', expect.any(Function));
    expect(el.dataset.autoClearEnabled).toBeUndefined();
  });

  it('cleans up on unmount without clearing the field value', () => {
    const el = mountInput('keepme');

    directive.unmounted(el);

    expect(el.value).toBe('keepme');
    expect(el.dataset.autoClearEnabled).toBeUndefined();
    expect((el as { onUmounted?: unknown }).onUmounted).toBeUndefined();
  });

  it('does not clear the field after teardown (timers/listeners removed)', () => {
    vi.useFakeTimers();
    const el = mountInput();

    directive.unmounted(el);
    vi.advanceTimersByTime(5000);

    expect(el.value).toBe('secret');
  });

  it('does not arm the same input twice', () => {
    const el = mountInput();
    const addSpy = vi.spyOn(window, 'addEventListener');

    directive.mounted(el, { value: { delay: 1 } });

    expect(addSpy).not.toHaveBeenCalledWith('pagehide', expect.any(Function));
  });
});
