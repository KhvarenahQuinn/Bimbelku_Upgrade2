import { useEffect, useRef, type RefObject } from "react";

const FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  "input:not([disabled])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  '[tabindex]:not([tabindex="-1"])',
].join(", ");

const isVisibleElement = (element: HTMLElement) =>
  element.isConnected && element.getClientRects().length > 0;

const getFocusableElements = (container: HTMLElement): HTMLElement[] =>
  Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
    isVisibleElement,
  );

/**
 * Traps keyboard Tab focus inside a mobile drawer while open, closes on Escape,
 * and restores focus to the hamburger trigger button when closed.
 */
export function useDrawerFocusTrap(
  open: boolean,
  containerRef: RefObject<HTMLElement | null>,
  triggerRef: RefObject<HTMLElement | null>,
  onClose: () => void,
) {
  const wasOpenRef = useRef(false);
  const fallbackTriggerRef = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) {
      if (wasOpenRef.current) {
        wasOpenRef.current = false;
        const target =
          fallbackTriggerRef.current && isVisibleElement(fallbackTriggerRef.current)
            ? fallbackTriggerRef.current
            : triggerRef.current;
        if (target && isVisibleElement(target)) {
          target.focus();
        }
        fallbackTriggerRef.current = null;
      }
      return;
    }

    wasOpenRef.current = true;
    const active = document.activeElement;
    if (active instanceof HTMLElement && !containerRef.current?.contains(active)) {
      fallbackTriggerRef.current = active;
    }

    const focusFrame = window.requestAnimationFrame(() => {
      const container = containerRef.current;
      if (!container) return;
      const focusables = getFocusableElements(container);
      (focusables[0] ?? container).focus();
    });

    const handleKeyDown = (event: KeyboardEvent) => {
      const container = containerRef.current;
      if (!container) return;

      if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        onCloseRef.current();
        return;
      }

      if (event.key !== "Tab") return;

      const focusables = getFocusableElements(container);
      if (focusables.length === 0) {
        event.preventDefault();
        container.focus();
        return;
      }

      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey) {
        if (document.activeElement === first || !container.contains(document.activeElement)) {
          event.preventDefault();
          last.focus();
        }
      } else if (document.activeElement === last || !container.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      }
    };

    const handleFocusIn = (event: FocusEvent) => {
      const container = containerRef.current;
      const target = event.target;
      if (!container || !(target instanceof Node) || container.contains(target)) return;
      if (target instanceof Element && target.closest('[role="dialog"], [role="alertdialog"]')) return;
      const focusables = getFocusableElements(container);
      (focusables[0] ?? container).focus();
    };

    document.addEventListener("keydown", handleKeyDown);
    document.addEventListener("focusin", handleFocusIn, true);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener("keydown", handleKeyDown);
      document.removeEventListener("focusin", handleFocusIn, true);
    };
  }, [open, containerRef, triggerRef]);
}
