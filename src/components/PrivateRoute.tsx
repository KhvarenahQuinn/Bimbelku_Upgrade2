import { Navigate, Outlet, useLocation } from "react-router-dom";
import { canAdmin, permissionForAdminPath } from "@/lib/adminPermissions";
import { clearBrowserSession } from "@/lib/session";

interface PrivateRouteProps {
  allowedRoles?: string[];
}

type StoredUser = {
  role?: string;
  admin_type?: string | null;
  admin_permissions?: string[];
};

export default function PrivateRoute({ allowedRoles }: PrivateRouteProps) {
  const location = useLocation();
  const token = localStorage.getItem("token");
  const userString = localStorage.getItem("user");
  let user: StoredUser | null = null;

  try {
    user = userString ? JSON.parse(userString) : null;
  } catch {
    clearBrowserSession();
  }

  if (!token || !user) {
    const returnTo = `${location.pathname}${location.search}${location.hash}`;
    const isAdminPath = location.pathname === "/admin" || location.pathname.startsWith("/admin/");
    const loginPath = isAdminPath ? "/admin/login" : "/login";
    return <Navigate to={`${loginPath}?redirect=${encodeURIComponent(returnTo)}`} replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role || "")) {
    return <Navigate to="/access-denied" replace />;
  }

  if (user.role === "admin" && location.pathname.startsWith("/admin")) {
    const permission = permissionForAdminPath(location.pathname);
    if (!permission || !canAdmin(user, permission)) {
      return <Navigate to="/access-denied" replace />;
    }
  }

  return <Outlet />;
}
