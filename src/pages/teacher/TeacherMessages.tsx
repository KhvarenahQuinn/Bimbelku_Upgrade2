import { useSearchParams } from "react-router-dom";
import MarketplaceMessages from "@/components/MarketplaceMessages";
import TeacherLayout from "@/components/TeacherLayout";

export default function TeacherMessages() {
  const [searchParams] = useSearchParams();
  // Paritas dengan sisi murid: navbar bawah hilang saat percakapan terbuka.
  const hasOpenConversation = Boolean(Number(searchParams.get("booking") || 0));

  return (
    <TeacherLayout title="Pesan" lockContentScroll hideBottomNav={hasOpenConversation}>
      <MarketplaceMessages role="teacher" />
    </TeacherLayout>
  );
}
