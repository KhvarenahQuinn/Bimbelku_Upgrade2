import { lazy, Suspense, useEffect, useRef } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, useLocation } from "react-router-dom";
import AppErrorBoundary from "./components/AppErrorBoundary";
import { WebsiteContentProvider } from "./components/WebsiteContentProvider";
import { hydrateBrowserSession } from "./lib/session";
import "./index.css";

const initialLanding = window.location.pathname === "/" ? import("./pages/Index") : null;
const LandingPage = lazy(() => initialLanding ?? import("./pages/Index"));
const initialFullApp = window.location.pathname !== "/" ? import("./App.tsx") : null;
const FullApp = lazy(() => (initialFullApp ?? import("./App.tsx")).then(({ AppContent }) => ({ default: AppContent })));

function AppEntry() {
  const location = useLocation();
  const previousPath = useRef(location.pathname);

  useEffect(() => {
    if (location.pathname === "/" && previousPath.current !== "/" && !location.hash) {
      window.scrollTo({ top: 0, left: 0, behavior: "auto" });
    }
    previousPath.current = location.pathname;
  }, [location.pathname, location.hash]);

  return <Suspense fallback={<div className="min-h-screen bg-[#F7F1E8]" />}>
    {location.pathname === "/"
      ? <WebsiteContentProvider><LandingPage /></WebsiteContentProvider>
      : <FullApp />}
  </Suspense>;
}

const rootElement = document.getElementById("root") ?? (() => {
  const fallbackRoot = document.createElement("div");
  fallbackRoot.id = "root";
  document.body.appendChild(fallbackRoot);
  return fallbackRoot;
})();

function bootstrap() {
hydrateBrowserSession();
createRoot(rootElement).render(
  <AppErrorBoundary>
    <BrowserRouter><AppEntry /></BrowserRouter>
  </AppErrorBoundary>,
);
}

void bootstrap();
