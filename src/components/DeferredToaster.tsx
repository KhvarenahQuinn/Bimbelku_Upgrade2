import { lazy, Suspense, useEffect, useState } from "react";
import { markToasterReady, markToasterUnmounted } from "@/lib/notify";

const Toaster = lazy(() => import("@/components/ui/sonner").then((module) => ({ default: module.Toaster })));

function MountedToaster() {
  useEffect(() => {
    markToasterReady();
    return () => markToasterUnmounted();
  }, []);

  return <Toaster position="top-center" richColors closeButton />;
}

export default function DeferredToaster() {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (ready) return;

    const activate = () => setReady(true);
    window.addEventListener("bimbelku:toast-needed", activate, { once: true });
    window.addEventListener("pointerdown", activate, { once: true, passive: true });
    window.addEventListener("keydown", activate, { once: true });

    return () => {
      window.removeEventListener("bimbelku:toast-needed", activate);
      window.removeEventListener("pointerdown", activate);
      window.removeEventListener("keydown", activate);
    };
  }, [ready]);

  return ready ? (
    <Suspense fallback={null}>
      <MountedToaster />
    </Suspense>
  ) : null;
}
