import http from "@/lib/http";

/**
 * Kontrak `POST /admin/packages/{package}/tutor-exit(-preview)`.
 * Keduanya dijaga izin `finance.refunds`, dan `tutor-exit` memakai
 * `Idempotency-Key` yang dipasang otomatis oleh interceptor `http`.
 */
export const TUTOR_EXIT_CONFIRM_TOKEN = "REFUND_SISA_PAKET";

export interface TutorExitSession {
  booking_id: number;
  start_at: string | null;
  end_at: string | null;
  subject: string | null;
  amount: number;
}

export interface TutorExitPreview {
  sessions: TutorExitSession[];
  total_amount: number;
  wallet_amount: number;
  external_amount: number;
  already_queued: boolean;
}

export interface TutorExitResult {
  message: string;
  data: {
    id: number;
    amount: number;
    status: string;
  };
}

export function hasRemainingRefundableSessions(preview: TutorExitPreview | null): boolean {
  return Boolean(preview && preview.sessions.length > 0 && preview.total_amount > 0);
}

export async function fetchTutorExitPreview(packageId: number): Promise<TutorExitPreview> {
  const response = await http.post<{ data: TutorExitPreview }>(
    `/admin/packages/${packageId}/tutor-exit-preview`,
  );

  return response.data.data;
}

export async function submitTutorExit(
  packageId: number,
  input: { reason: string; evidenceNote: string; bookingIds: number[] },
): Promise<TutorExitResult> {
  const response = await http.post<TutorExitResult>(
    `/admin/packages/${packageId}/tutor-exit`,
    {
      reason: input.reason.trim(),
      evidence_note: input.evidenceNote.trim(),
      confirm_token: TUTOR_EXIT_CONFIRM_TOKEN,
      booking_ids: input.bookingIds,
    },
  );

  return response.data;
}
