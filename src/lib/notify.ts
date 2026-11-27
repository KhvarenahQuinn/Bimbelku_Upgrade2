type SonnerModule = typeof import("sonner");
type ToastMethod = "success" | "error" | "warning";
type ToastMessage = Parameters<SonnerModule["toast"]["success"]>[0];
type ToastOptions = Parameters<SonnerModule["toast"]["success"]>[1];
type QueuedToast = { kind: ToastMethod; message: ToastMessage; options?: ToastOptions };

const MAX_QUEUED_TOASTS = 5;
let modulePromise: Promise<SonnerModule> | null = null;
let toasterReady = false;
const pendingQueue: QueuedToast[] = [];
const pendingDedupKeys = new Set<string>();

const loadSonner = () => {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new Event("bimbelku:toast-needed"));
  }
  modulePromise ??= import("sonner");
  return modulePromise;
};

const dedupKeyFor = (kind: ToastMethod, message: ToastMessage, options?: ToastOptions): string | null => {
  if (typeof message !== "string") return null;
  const description = typeof options?.description === "string" ? options.description : "";
  return `${kind}:${message}:${description}`;
};

const call = (
  kind: ToastMethod,
  message: ToastMessage,
  options?: ToastOptions,
) => {
  const loader = loadSonner();
  if (toasterReady) {
    void loader.then(({ toast }) => {
      toast[kind](message, options);
    });
    return;
  }

  const key = dedupKeyFor(kind, message, options);
  if (key && pendingDedupKeys.has(key)) return;
  if (pendingQueue.length >= MAX_QUEUED_TOASTS) {
    const dropped = pendingQueue.shift();
    if (dropped) {
      const droppedKey = dedupKeyFor(dropped.kind, dropped.message, dropped.options);
      if (droppedKey) pendingDedupKeys.delete(droppedKey);
    }
  }
  if (key) pendingDedupKeys.add(key);
  pendingQueue.push({ kind, message, options });
};

export const markToasterReady = () => {
  toasterReady = true;
  if (pendingQueue.length === 0) return;
  const batch = pendingQueue.splice(0, pendingQueue.length);
  pendingDedupKeys.clear();
  void loadSonner().then(({ toast }) => {
    for (const item of batch) {
      toast[item.kind](item.message, item.options);
    }
  });
};

export const markToasterUnmounted = () => {
  toasterReady = false;
};

export const notify = {
  success: (message: ToastMessage, options?: ToastOptions) => call("success", message, options),
  error: (message: ToastMessage, options?: ToastOptions) => call("error", message, options),
  warning: (message: ToastMessage, options?: ToastOptions) => call("warning", message, options),
};

export const loadToastModule = loadSonner;
