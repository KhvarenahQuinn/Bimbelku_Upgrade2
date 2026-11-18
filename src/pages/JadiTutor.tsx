import { Navigate, useSearchParams } from "react-router-dom";
import { useEffect } from "react";
import Register from "@/pages/Register";
import { notify } from "@/lib/notify";

export default function JadiTutor() {
  const [params] = useSearchParams();
  const login = params.get("tab") === "login";

  useEffect(() => {
    if (!login) {
      notify.success("Mengarahkan ke WhatsApp untuk melanjutkan seleksi tutor...");
    }
  }, [login]);

  return login ? <Navigate to="/login" replace /> : <Register role="teacher" />;
}
