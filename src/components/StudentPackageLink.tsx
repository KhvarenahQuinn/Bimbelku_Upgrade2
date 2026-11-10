import { lazy, Suspense, type ComponentProps, type MouseEvent, useState } from "react";
import { Link } from "react-router-dom";
import { clearBrowserSession } from "@/lib/session";

const StudentPackageBlockedDialog = lazy(() => import("@/components/StudentPackageBlockedDialog"));

type StoredUser = {
  name?: string;
  role?: string;
};

const readStoredUser = (): StoredUser | null => {
  const storedUser = localStorage.getItem("user");
  if (!storedUser) return null;

  try {
    const parsed = JSON.parse(storedUser);
    return parsed && typeof parsed === "object" ? parsed : null;
  } catch {
    clearBrowserSession();
    return null;
  }
};

type StudentPackageLinkProps = ComponentProps<typeof Link> & {
  onNavigate?: () => void;
};

export default function StudentPackageLink({
  onClick,
  onNavigate,
  children,
  ...props
}: StudentPackageLinkProps) {
  const [blockedUser, setBlockedUser] = useState<StoredUser | null>(null);
  const target = !readStoredUser() && typeof props.to === "string" && props.to.startsWith("/student/packages/new")
    ? props.to.replace("/student/packages/new", "/pesan")
    : props.to;

  const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
    onClick?.(event);
    if (event.defaultPrevented) return;

    const currentUser = readStoredUser();
    if (!currentUser || currentUser.role === "student") {
      onNavigate?.();
      return;
    }

    event.preventDefault();
    setBlockedUser(currentUser);
  };

  return (
    <>
      <Link {...props} to={target} onClick={handleClick}>
        {children}
      </Link>

      {blockedUser && (
        <Suspense fallback={null}>
          <StudentPackageBlockedDialog
            user={blockedUser}
            onClose={() => setBlockedUser(null)}
            onNavigate={onNavigate}
          />
        </Suspense>
      )}
    </>
  );
}
