import { lazy, Suspense, useEffect, useRef, useState } from "react";
import {
  FileText,
  ExternalLink,
  Loader2,
  RotateCw,
  ScanSearch,
  X,
  ZoomIn,
  ZoomOut,
} from "lucide-react";
import {
  consumePendingFilePreview,
  FILE_PREVIEW_EVENT,
  FilePreviewDetail,
  setFilePreviewOpen,
} from "@/lib/filePreview";

const MIN_SCALE = 0.5;
const MAX_SCALE = 4;
const PdfCanvasPreview = lazy(() => import("@/components/PdfCanvasPreview"));

export default function FilePreviewProvider() {
  const [preview, setPreview] = useState<FilePreviewDetail | null>(null);
  const [scale, setScale] = useState(1);
  const [rotation, setRotation] = useState(0);
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const restoreFocusRef = useRef<HTMLElement | null>(null);

  const close = () => {
    setPreview((current) => {
      current?.release?.();
      return null;
    });
    setFilePreviewOpen(false);
  };

  useEffect(() => {
    const open = (event: Event) => {
      const detail = (event as CustomEvent<FilePreviewDetail>).detail;
      if (!detail?.url) return;
      consumePendingFilePreview();

      setPreview((current) => {
        current?.release?.();
        return detail;
      });
      setFilePreviewOpen(true);
      setScale(1);
      setRotation(0);
    };

    window.addEventListener(FILE_PREVIEW_EVENT, open);
    const pending = consumePendingFilePreview();
    if (pending?.url) {
      setPreview(pending);
      setFilePreviewOpen(true);
      setScale(1);
      setRotation(0);
    }
    return () => window.removeEventListener(FILE_PREVIEW_EVENT, open);
  }, []);

  // Jika provider dilepas saat pratinjau masih terbuka, modal induk tidak boleh
  // tetap terkunci oleh flag pratinjau.
  useEffect(() => () => setFilePreviewOpen(false), []);

  useEffect(() => {
    if (!preview) return;

    restoreFocusRef.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    window.requestAnimationFrame(() => dialogRef.current?.focus());

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        // Radix Dialog mendengarkan Escape pada document (capture). Listener
        // ini berada di window capture sehingga berjalan lebih dulu dan
        // menghentikan event agar modal induk tidak ikut tertutup.
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        close();
        return;
      }
      if (event.key === "+" || event.key === "=") {
        setScale((value) => Math.min(MAX_SCALE, value + 0.25));
      }
      if (event.key === "-") {
        setScale((value) => Math.max(MIN_SCALE, value - 0.25));
      }
    };
    window.addEventListener("keydown", handleKeyDown, { capture: true });

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", handleKeyDown, { capture: true });
      restoreFocusRef.current?.focus();
    };
  }, [preview]);

  const isImage = preview?.contentType.startsWith("image/") || false;
  const isPdf = preview?.contentType === "application/pdf";

  if (!preview) return null;

  return (
    <div
      ref={dialogRef}
      tabIndex={-1}
      className="pointer-events-auto fixed inset-0 z-[var(--layer-preview)] flex flex-col bg-slate-950/95 text-white backdrop-blur-sm outline-none"
      role="dialog"
      aria-modal="true"
      aria-label={`Pratinjau ${preview.filename}`}
      onPointerDown={(event) => event.stopPropagation()}
      onClick={(event) => event.stopPropagation()}
    >
      <div className="flex min-h-16 items-center justify-between gap-3 border-b border-white/10 bg-slate-950/80 px-4 py-3 sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white/10 text-indigo-200">
            {isImage ? <ScanSearch size={20} /> : <FileText size={20} />}
          </span>
          <div className="min-w-0">
            <p className="truncate text-sm font-black">{preview.filename}</p>
            <p className="mt-0.5 text-xs text-slate-400">
              {isImage ? "Klik gambar atau gunakan tombol untuk memperbesar." : "Berkas ditampilkan langsung tanpa perlu diunduh."}
            </p>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1">
          {isPdf && <a href={preview.url} target="_blank" rel="noopener noreferrer" className="grid h-10 w-10 place-items-center rounded-xl text-slate-200 transition hover:bg-white/10" aria-label="Buka PDF di tab baru" title="Buka PDF di tab baru"><ExternalLink size={18} /></a>}
          {isImage && (
            <>
              <PreviewButton
                label="Perkecil"
                disabled={scale <= MIN_SCALE}
                onClick={() => setScale((value) => Math.max(MIN_SCALE, value - 0.25))}
              >
                <ZoomOut size={18} />
              </PreviewButton>
              <span className="hidden min-w-14 text-center text-xs font-black text-slate-300 sm:block">
                {Math.round(scale * 100)}%
              </span>
              <PreviewButton
                label="Perbesar"
                disabled={scale >= MAX_SCALE}
                onClick={() => setScale((value) => Math.min(MAX_SCALE, value + 0.25))}
              >
                <ZoomIn size={18} />
              </PreviewButton>
              <PreviewButton label="Putar" onClick={() => setRotation((value) => (value + 90) % 360)}>
                <RotateCw size={18} />
              </PreviewButton>
            </>
          )}
          <PreviewButton label="Tutup pratinjau" onClick={close}>
            <X size={20} />
          </PreviewButton>
        </div>
      </div>

      <div
        role="presentation"
        className="relative flex-1 overflow-auto p-3 sm:p-6"
        onClick={(event) => {
          event.stopPropagation();
          if (event.target === event.currentTarget) close();
        }}
      >
        {isImage ? (
          <div className="grid min-h-full place-items-center">
            <img
              src={preview.url}
              alt={preview.filename}
              loading="eager"
              decoding="async"
              className="max-h-[calc(100dvh-7rem)] max-w-full cursor-zoom-in select-none object-contain shadow-2xl transition-transform duration-200"
              style={{ transform: `scale(${scale}) rotate(${rotation}deg)` }}
              onClick={() => setScale((value) => value === 1 ? 2 : 1)}
              draggable={false}
            />
          </div>
        ) : isPdf ? (
          <Suspense fallback={<p className="flex items-center justify-center gap-2 py-16 text-sm text-slate-300"><Loader2 size={18} className="animate-spin" />Memuat pembaca PDF…</p>}>
            <PdfCanvasPreview url={preview.url} blob={preview.blob} />
          </Suspense>
        ) : (
          <div className="grid min-h-full place-items-center">
            <div className="max-w-md rounded-2xl border border-white/10 bg-white/5 p-8 text-center">
              <FileText className="mx-auto text-indigo-200" size={40} />
              <p className="mt-4 font-black">Format ini belum dapat ditampilkan.</p>
              <p className="mt-2 text-sm leading-6 text-slate-300">
                Unggah bukti dalam format JPG, PNG, WebP, atau PDF agar dapat diperiksa langsung.
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function PreviewButton({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="grid h-10 w-10 place-items-center rounded-xl text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-30"
    >
      {children}
    </button>
  );
}
