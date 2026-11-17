import { createContext, useContext, useEffect, useMemo, useState } from "react";

import { API_BASE_URL } from "@/lib/apiBase";
import {
  defaultWebsiteContent,
  type WebsiteContentPayload,
  type WebsiteSection,
} from "@/lib/websiteContent";

type WebsiteContentContextValue = WebsiteContentPayload & {
  loading: boolean;
  section: (key: string) => WebsiteSection | undefined;
  isVisible: (key: string) => boolean;
};

const WebsiteContentContext = createContext<WebsiteContentContextValue | null>(null);

export function WebsiteContentProvider({ children }: { children: React.ReactNode }) {
  const [content, setContent] = useState<WebsiteContentPayload>(() => window.__bimbelkuWebsiteContentValue ?? defaultWebsiteContent);
  const [loading, setLoading] = useState(() => !window.__bimbelkuWebsiteContentValue);
  const [loadedFromApi, setLoadedFromApi] = useState(() => Boolean(window.__bimbelkuWebsiteContentValue));

  useEffect(() => {
    let active = true;
    const fetchContent = () => fetch(`${API_BASE_URL}/website-content`, {
      credentials: "include",
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(10_000),
    }).then((response) => {
      if (!response.ok) throw new Error("Konten publik belum tersedia.");
      return response.json() as Promise<WebsiteContentPayload>;
    });
    const load = (force = false) => (force || !window.__bimbelkuWebsiteContent
      ? fetchContent()
      : window.__bimbelkuWebsiteContent.then((data) => data ?? fetchContent()))
      .then((data) => {
        if (!active) return;
        setContent({
          settings: { ...defaultWebsiteContent.settings, ...data.settings },
          sections: Array.isArray(data.sections) ? data.sections : defaultWebsiteContent.sections,
          trust_items: data.trust_items || [],
          rating_summary: data.rating_summary || null,
          testimonials: Array.isArray(data.testimonials) ? data.testimonials : [],
        });
        setLoadedFromApi(true);
      })
      .catch(() => {
        // Konten aman bawaan menjaga landing tetap berfungsi saat API sementara tidak tersedia.
      })
      .finally(() => active && setLoading(false));

    void load();
    const refresh = () => void load(true);
    window.addEventListener("bimbelku:website-content-changed", refresh);

    return () => {
      active = false;
      window.removeEventListener("bimbelku:website-content-changed", refresh);
    };
  }, []);

  useEffect(() => {
    document.documentElement.dataset.siteAnimations = content.settings.animations_enabled ? "on" : "off";
    if (content.settings.favicon_url) {
      document.querySelector<HTMLLinkElement>('link[rel~="icon"]')?.setAttribute("href", content.settings.favicon_url);
    }
    if (content.settings.social_share_image_url) {
      document.querySelector<HTMLMetaElement>('meta[property="og:image"]')?.setAttribute("content", content.settings.social_share_image_url);
      document.querySelector<HTMLMetaElement>('meta[name="twitter:image"]')?.setAttribute("content", content.settings.social_share_image_url);
    }
  }, [content.settings]);

  const value = useMemo<WebsiteContentContextValue>(() => {
    const sectionMap = new Map(content.sections.map((item) => [item.section_key, item]));
    return {
      ...content,
      loading,
      section: (key) => sectionMap.get(key) || defaultWebsiteContent.sections.find((item) => item.section_key === key),
      isVisible: (key) => {
        const found = sectionMap.get(key);
        if (found) return found.is_visible !== false;
        return loadedFromApi ? false : defaultWebsiteContent.sections.find((item) => item.section_key === key)?.is_visible !== false;
      },
    };
  }, [content, loadedFromApi, loading]);

  return <WebsiteContentContext.Provider value={value}>{children}</WebsiteContentContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useWebsiteContent() {
  const value = useContext(WebsiteContentContext);
  if (!value) throw new Error("useWebsiteContent harus digunakan di dalam WebsiteContentProvider.");
  return value;
}

/**
 * Varian tanpa lemparan untuk komponen global yang boleh hidup di luar
 * provider, mis. tombol WhatsApp mengambang yang dipasang sekali di root.
 */
// eslint-disable-next-line react-refresh/only-export-components
export function useOptionalWebsiteContent() {
  return useContext(WebsiteContentContext);
}
