/**
 * Penanda sederhana untuk elemen mengambang lapisan bawah-kanan. Halaman publik
 * memasang tombol WhatsApp di posisi itu; saat widget lain (mis. popup tagihan)
 * memakai sudut yang sama, tombol WhatsApp perlu naik agar tidak tertutup.
 */
type Listener = (active: boolean) => void;

const listeners = new Set<Listener>();
let pendingCount = 0;

const emit = () => listeners.forEach((listener) => listener(pendingCount > 0));

export const setPendingPaymentWidgetVisible = (active: boolean) => {
  pendingCount = Math.max(0, pendingCount + (active ? 1 : -1));
  emit();
};

export const subscribePendingPaymentWidget = (listener: Listener) => {
  listeners.add(listener);
  listener(pendingCount > 0);
  return () => {
    listeners.delete(listener);
  };
};
