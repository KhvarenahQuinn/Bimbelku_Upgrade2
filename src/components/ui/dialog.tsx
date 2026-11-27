import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";

import { isFilePreviewOpen } from "@/lib/filePreview";
import { cn } from "@/lib/utils";

const Dialog = DialogPrimitive.Root;

const DialogTrigger = DialogPrimitive.Trigger;

const DialogPortal = DialogPrimitive.Portal;

const DialogClose = DialogPrimitive.Close;

type DialogLayer = "modal" | "detail";

const overlayLayerClass: Record<DialogLayer, string> = {
  modal: "z-[var(--layer-modal)]",
  detail: "z-[var(--layer-detail)]",
};

const contentLayerClass: Record<DialogLayer, string> = {
  modal: "z-[var(--layer-modal-content)]",
  detail: "z-[var(--layer-detail-content)]",
};

const DialogOverlay = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      "fixed inset-0 z-[var(--layer-modal)] bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
      className,
    )}
    {...props}
  />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

type DialogContentProps = React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & {
  layer?: DialogLayer;
  hideCloseButton?: boolean;
  overlayClassName?: string;
};

const isInteractivePortalEvent = (event: { detail?: { originalEvent?: Event } }) =>
  (event.detail?.originalEvent?.target as Element | null)?.closest?.("[data-dialog-interactive-portal]") !== null;

const DialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  DialogContentProps
>(({
  className,
  children,
  layer = "modal",
  hideCloseButton = false,
  overlayClassName,
  onEscapeKeyDown,
  onPointerDownOutside,
  onInteractOutside,
  onFocusOutside,
  onCloseAutoFocus,
  ...props
}, ref) => {
  const restoreFocusRef = React.useRef<HTMLElement | null>(null);
  const dialogNodeRef = React.useRef<React.ElementRef<typeof DialogPrimitive.Content> | null>(null);
  const setRefs = React.useCallback(
    (node: React.ElementRef<typeof DialogPrimitive.Content> | null) => {
      if (node) dialogNodeRef.current = node;
      if (typeof ref === "function") ref(node);
      else if (ref) ref.current = node;
    },
    [ref],
  );

  React.useLayoutEffect(() => {
    const active = document.activeElement;
    if (active instanceof HTMLElement && active !== document.body) {
      restoreFocusRef.current = active;
    }
  }, []);

  return (
    <DialogPortal>
      <DialogOverlay className={cn(overlayLayerClass[layer], overlayClassName)} />
      <DialogPrimitive.Content
        ref={setRefs}
        aria-modal="true"
        className={cn(
          "fixed left-[50%] top-[50%] grid min-w-0 w-[calc(100vw-1rem)] max-w-lg max-h-[calc(100dvh-1rem)] translate-x-[-50%] translate-y-[-50%] gap-4 overflow-hidden border bg-background p-4 shadow-lg sm:w-[calc(100vw-2rem)] sm:max-h-[calc(100dvh-2rem)] duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%] data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%] sm:p-6 sm:rounded-lg",
          contentLayerClass[layer],
          className,
        )}
        onEscapeKeyDown={(event) => {
          if (isFilePreviewOpen()) event.preventDefault();
          onEscapeKeyDown?.(event);
        }}
        onPointerDownOutside={(event) => {
          if (isFilePreviewOpen() || isInteractivePortalEvent(event)) event.preventDefault();
          onPointerDownOutside?.(event);
        }}
        onInteractOutside={(event) => {
          if (isFilePreviewOpen() || isInteractivePortalEvent(event)) event.preventDefault();
          onInteractOutside?.(event);
        }}
        onFocusOutside={(event) => {
          if (isFilePreviewOpen() || isInteractivePortalEvent(event)) event.preventDefault();
          onFocusOutside?.(event);
        }}
        onCloseAutoFocus={(event) => {
          onCloseAutoFocus?.(event);
          const activeDialog = document.activeElement?.closest?.('[role="dialog"], [role="alertdialog"]');
          if (
            !event.defaultPrevented
            && restoreFocusRef.current?.isConnected
            && (!activeDialog || activeDialog === dialogNodeRef.current || !activeDialog.isConnected)
          ) {
            event.preventDefault();
            restoreFocusRef.current.focus();
          }
        }}
        {...props}
      >
        {children}
        {!hideCloseButton && (
          <DialogPrimitive.Close className="absolute right-3 top-3 grid h-10 w-10 place-items-center rounded-xl opacity-70 ring-offset-background transition-opacity data-[state=open]:bg-accent data-[state=open]:text-muted-foreground hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none sm:right-4 sm:top-4">
            <X className="h-4 w-4" />
            <span className="sr-only">Tutup</span>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  );
});
DialogContent.displayName = DialogPrimitive.Content.displayName;

const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn("flex min-w-0 flex-col space-y-1.5 text-center", className)} {...props} />
);
DialogHeader.displayName = "DialogHeader";

const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div className={cn("flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2", className)} {...props} />
);
DialogFooter.displayName = "DialogFooter";

const DialogTitle = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn("min-w-0 break-words px-10 text-center text-lg font-semibold leading-tight tracking-tight", className)}
    {...props}
  />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

const DialogDescription = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description ref={ref} className={cn("min-w-0 break-words text-sm leading-5 text-muted-foreground", className)} {...props} />
));
DialogDescription.displayName = DialogPrimitive.Description.displayName;

export {
  Dialog,
  DialogPortal,
  DialogOverlay,
  DialogClose,
  DialogTrigger,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
};
