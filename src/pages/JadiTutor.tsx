import { Navigate, useSearchParams } from "react-router-dom";
import Register from "@/pages/Register";

export default function JadiTutor() {
  const [params] = useSearchParams();
  const login = params.get("tab") === "login";

  return login ? <Navigate to="/login" replace /> : <Register role="teacher" />;
}
