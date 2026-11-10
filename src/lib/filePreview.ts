export const FILE_PREVIEW_EVENT = "bimbelku:file-preview";
export const FILE_PREVIEW_REQUEST_EVENT = "bimbelku:file-preview-needed";

export interface FilePreviewDetail {
  url: string;
  filename: string;
  contentType: string;
  blob?: Blob;
  release?: () => void;
}

let pendingPreview: FilePreviewDetail | null = null;
let previewOpen = false;
const previewStateListeners = new Set<() => void>();

const notifyPreviewState = (open: boolean) => {
  if (previewOpen === open) return;
  previewOpen = open;
  for (const listener of previewStateListeners) listener();
};

/**
 * Pratinjau berkas global hidup di layer terpisah dari modal pemanggil.
 * Flag ini dipakai modal induk untuk menolak Escape/backdrop saat pratinjau
 * masih terbuka, sehingga menutup pratinjau tidak menutup modal induk.
 */
export const isFilePreviewOpen = () => previewOpen;

export const setFilePreviewOpen = (open: boolean) => {
  notifyPreviewState(open);
};

export const subscribeFilePreviewState = (listener: () => void) => {
  previewStateListeners.add(listener);
  return () => previewStateListeners.delete(listener);
};

export const showFilePreview = (detail: FilePreviewDetail) => {
  pendingPreview = detail;
  notifyPreviewState(true);
  window.dispatchEvent(new Event(FILE_PREVIEW_REQUEST_EVENT));
  window.dispatchEvent(new CustomEvent<FilePreviewDetail>(FILE_PREVIEW_EVENT, {
    detail,
  }));
};

export const consumePendingFilePreview = () => {
  const detail = pendingPreview;
  pendingPreview = null;
  return detail;
};

export const inferContentType = (source: string) => {
  const cleanSource = source.split("?")[0].split("#")[0].toLocaleLowerCase("id-ID");
  if (cleanSource.endsWith(".pdf")) return "application/pdf";
  if (cleanSource.endsWith(".png")) return "image/png";
  if (cleanSource.endsWith(".webp")) return "image/webp";
  if (cleanSource.endsWith(".gif")) return "image/gif";
  if (cleanSource.endsWith(".jpg") || cleanSource.endsWith(".jpeg")) return "image/jpeg";
  return "";
};
