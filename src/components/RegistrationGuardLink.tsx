import { type ComponentProps, type MouseEvent, useState } from "react";
import { CircleCheckBig, LayoutDashboard } from "lucide-react";
import { Link, useNavigate } from "react-router-dom";
import { clearBrowserSession } from "@/lib/session";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

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

const dashboardPath = (role?: string) => {
  if (role === "admin") return "/admin";
  if (role === "teacher") return "/guru";
  return "/student/dashboard";
};

const roleLabel = (role?: string) => {
  if (role === "admin") return "admin";
  if (role === "teacher") return "tutor";
  return "siswa";
};

type RegistrationGuardLinkProps = ComponentProps<typeof Link>;

export default function RegistrationGuardLink({
  onClick,
  children,
  ...props
}: RegistrationGuardLinkProps) {
  const navigate = useNavigate();
  const [activeUser, setActiveUser] = useState<StoredUser | null>(null);

  const handleClick = (event: MouseEvent<HTMLAnchorElement>) => {
    onClick?.(event);
    if (event.defaultPrevented) return;

    const currentUser = readStoredUser();
    if (!currentUser) return;

    event.preventDefault();
    setActiveUser(currentUser);
  };

  const openDashboard = () => {
    const path = dashboardPath(activeUser?.role);
    setActiveUser(null);
    navigate(path);
  };

  return (
    <>
      <Link {...props} onClick={handleClick}>
        {children}
      </Link>

      <Dialog open={Boolean(activeUser)} onOpenChange={(open) => !open && setActiveUser(null)}>
        <DialogContent className="rounded-[2rem] border-slate-100 p-6 sm:max-w-md sm:p-8">
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700 sm:mx-0">
            <CircleCheckBig className="h-7 w-7" aria-hidden="true" />
          </div>
          <DialogHeader className="gap-2">
            <DialogTitle className="text-2xl font-black text-slate-900">
              Anda sudah login
            </DialogTitle>
            <DialogDescription className="text-sm leading-6 text-slate-600">
              {activeUser?.name ? `${activeUser.name} sedang` : "Akun Anda sedang"} aktif sebagai {roleLabel(activeUser?.role)}. Anda tidak perlu mendaftar kembali.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="mt-2 gap-2 sm:space-x-0">
            <Button type="button" variant="outline" onClick={() => setActiveUser(null)}>
              Tutup
            </Button>
            <Button type="button" onClick={openDashboard}>
              <LayoutDashboard className="h-4 w-4" aria-hidden="true" />
              Buka Dashboard
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
