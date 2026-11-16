import { useSearchParams } from "react-router-dom";
import MarketplaceMessages from "@/components/MarketplaceMessages";
import StudentLayout from "@/components/StudentLayout";

export default function Messages() {
  const [searchParams] = useSearchParams();
  // Percakapan aktif ditentukan URL, jadi layout bisa membacanya langsung
  // tanpa perlu state yang dibagi dari dalam MarketplaceMessages.
  const hasOpenConversation = Boolean(Number(searchParams.get("booking") || 0));

  return (
    <StudentLayout title="Pesan" lockContentScroll hideBottomNav={hasOpenConversation}>
      <MarketplaceMessages role="student" />
    </StudentLayout>
  );
}
