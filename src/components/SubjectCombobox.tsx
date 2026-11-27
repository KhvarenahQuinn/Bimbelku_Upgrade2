import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { BookOpen, Check, ChevronDown, Loader2, Plus, Search, X } from "lucide-react";
import { cn } from "@/lib/utils";

export interface SubjectOption {
  id: number;
  name: string;
  group_name?: string;
  education_levels?: string[];
  grades?: string[];
  is_elective?: boolean;
  is_active?: boolean;
  show_chapter_selection?: boolean;
}

interface SubjectComboboxProps {
  id?: string;
  options: SubjectOption[];
  value: string;
  onChange: (value: string, option?: SubjectOption) => void;
  placeholder?: string;
  educationLevel?: string;
  grade?: string;
  allowCreate?: boolean;
  onCreate?: (name: string) => Promise<SubjectOption | null>;
  disabled?: boolean;
  className?: string;
}

type MenuPosition = {
  top: number;
  left: number;
  width: number;
  maxHeight: number;
  placement: "top" | "bottom";
};

const normalize = (value: string) => value.trim().replace(/\s+/g, " ").toLocaleLowerCase("id-ID");
const clamp = (value: number, minimum: number, maximum: number) => Math.min(Math.max(value, minimum), Math.max(minimum, maximum));

export default function SubjectCombobox({
  id,
  options,
  value,
  onChange,
  placeholder = "Pilih mata pelajaran",
  educationLevel,
  grade,
  allowCreate = false,
  onCreate,
  disabled = false,
  className,
}: SubjectComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState(value);
  const [creating, setCreating] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const [menuPosition, setMenuPosition] = useState<MenuPosition | null>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const listboxRef = useRef<HTMLDivElement>(null);
  const listboxId = useId();

  useEffect(() => {
    if (!open) setQuery(value);
  }, [open, value]);

  const eligible = useMemo(
    () => options.filter((option) => {
      if (option.is_active === false) return false;
      if (educationLevel && option.education_levels?.length && !option.education_levels.includes(educationLevel)) return false;
      if (grade && option.grades?.length && !option.grades.includes(grade)) return false;
      return true;
    }),
    [educationLevel, grade, options],
  );
  const filtered = useMemo(() => {
    const needle = normalize(query);
    if (!needle) return eligible;
    return eligible.filter((option) => normalize(`${option.name} ${option.group_name || ""}`).includes(needle));
  }, [eligible, query]);
  const exact = options.find((option) => normalize(option.name) === normalize(query));
  const canCreate = allowCreate && query.trim().length >= 2 && !exact;

  useEffect(() => {
    setActiveIndex(0);
  }, [query, educationLevel, grade]);

  useEffect(() => {
    if (activeIndex < filtered.length) return;
    setActiveIndex(Math.max(0, filtered.length - 1));
  }, [activeIndex, filtered.length]);

  const updateMenuPosition = useCallback(() => {
    const trigger = rootRef.current;
    if (!trigger || typeof window === "undefined") return;

    const rect = trigger.getBoundingClientRect();
    const visualViewport = window.visualViewport;
    const viewportLeft = visualViewport?.offsetLeft ?? 0;
    const viewportTop = visualViewport?.offsetTop ?? 0;
    const viewportWidth = visualViewport?.width ?? window.innerWidth;
    const viewportHeight = visualViewport?.height ?? window.innerHeight;
    const margin = 12;
    const gap = 8;
    const desiredHeight = 288;
    const availableBelow = viewportTop + viewportHeight - rect.bottom - gap - margin;
    const availableAbove = rect.top - viewportTop - gap - margin;
    const placement: "top" | "bottom" = availableBelow >= Math.min(desiredHeight, availableAbove) ? "bottom" : "top";
    const availableHeight = placement === "bottom" ? availableBelow : availableAbove;
    const width = Math.max(0, Math.min(rect.width, viewportWidth - margin * 2));
    const left = clamp(rect.left, viewportLeft + margin, viewportLeft + viewportWidth - width - margin);
    const top = placement === "bottom" ? rect.bottom + gap : rect.top - gap;
    // A modal dialog locks wheel/touch scrolling outside its own DOM subtree.
    // Keep the list inside the dialog and express fixed coordinates relative to
    // its transformed content box; elsewhere the list stays in document.body.
    const dialogRect = trigger.closest<HTMLElement>('[role="dialog"]')?.getBoundingClientRect();

    setMenuPosition({
      top: top - (dialogRect?.top ?? 0),
      left: left - (dialogRect?.left ?? 0),
      width,
      maxHeight: Math.max(0, Math.min(desiredHeight, availableHeight)),
      placement,
    });
  }, []);

  useEffect(() => {
    if (!open) setMenuPosition(null);
  }, [open]);

  useEffect(() => {
    if (!open || disabled) return;

    updateMenuPosition();
    const frame = window.requestAnimationFrame(updateMenuPosition);
    const onViewportChange = () => updateMenuPosition();
    window.addEventListener("resize", onViewportChange);
    window.addEventListener("scroll", onViewportChange, true);
    window.visualViewport?.addEventListener("resize", onViewportChange);
    window.visualViewport?.addEventListener("scroll", onViewportChange);

    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener("resize", onViewportChange);
      window.removeEventListener("scroll", onViewportChange, true);
      window.visualViewport?.removeEventListener("resize", onViewportChange);
      window.visualViewport?.removeEventListener("scroll", onViewportChange);
    };
  }, [disabled, open, updateMenuPosition]);

  useEffect(() => {
    const closeWhenOutside = (event: PointerEvent) => {
      const target = event.target as Node;
      if (rootRef.current?.contains(target) || listboxRef.current?.contains(target)) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", closeWhenOutside);
    return () => document.removeEventListener("pointerdown", closeWhenOutside);
  }, []);

  const select = (option: SubjectOption) => {
    setQuery(option.name);
    onChange(option.name, option);
    setOpen(false);
  };

  const create = async () => {
    if (!onCreate || !canCreate) return;
    setCreating(true);
    try {
      const created = await onCreate(query.trim().replace(/\s+/g, " "));
      if (created) select(created);
    } finally {
      setCreating(false);
    }
  };

  const menu = open && !disabled && menuPosition && typeof document !== "undefined" ? (
    <div
      ref={listboxRef}
      data-dialog-interactive-portal
      id={listboxId}
      role="listbox"
      className="pointer-events-auto fixed z-[var(--layer-detail-popover)] min-w-0 max-w-[calc(100dvw-1.5rem)] overflow-x-hidden overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"
      style={{
        top: menuPosition.top,
        left: menuPosition.left,
        width: menuPosition.width,
        maxHeight: menuPosition.maxHeight,
        transform: menuPosition.placement === "top" ? "translateY(-100%)" : undefined,
      }}
    >
      {filtered.map((option, index) => (
        <button
          id={`${listboxId}-option-${option.id}`}
          type="button"
          role="option"
          aria-selected={normalize(value) === normalize(option.name)}
          key={option.id}
          onClick={() => select(option)}
          onMouseEnter={() => setActiveIndex(index)}
          className={cn("flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left hover:bg-indigo-50", activeIndex === index && "bg-indigo-50")}
        >
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><BookOpen size={17} /></span>
          <span className="min-w-0 flex-1">
            <span className="block truncate text-sm font-bold text-slate-800">{option.name}</span>
            <span className="mt-0.5 block truncate text-[11px] text-slate-500">{option.group_name || "Mata pelajaran"}</span>
          </span>
          {normalize(value) === normalize(option.name) && <Check size={17} className="text-indigo-600" />}
        </button>
      ))}
      {canCreate && (
        <button
          type="button"
          disabled={creating}
          onClick={create}
          className="mt-1 flex w-full items-center gap-3 rounded-xl border border-dashed border-orange-200 bg-orange-50 px-3 py-3 text-left text-orange-800 hover:bg-orange-100"
        >
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white text-orange-600">
            {creating ? <Loader2 size={17} className="animate-spin" /> : <Plus size={17} />}
          </span>
          <span className="min-w-0 flex-1 break-words text-sm font-black">Tambahkan “{query.trim()}” sebagai mapel baru</span>
        </button>
      )}
      {!filtered.length && !canCreate && (
        <div className="px-4 py-8 text-center text-sm text-slate-500">
          Tidak ada mapel yang cocok untuk kelas ini.
        </div>
      )}
    </div>
  ) : null;

  return (
    <div ref={rootRef} className={cn("relative min-w-0 max-w-full", className)}>
      <div className={cn(
        "flex h-12 items-center gap-2 rounded-xl border border-input bg-background px-3 ring-offset-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2",
        disabled && "cursor-not-allowed opacity-50",
      )}>
        <Search size={16} className="shrink-0 text-slate-500" />
        <input
          id={id}
          role="combobox"
          aria-label={placeholder}
          aria-expanded={open}
          aria-controls={listboxId}
          aria-activedescendant={open && filtered[activeIndex] ? `${listboxId}-option-${filtered[activeIndex].id}` : undefined}
          aria-autocomplete="list"
          disabled={disabled}
          value={query}
          placeholder={placeholder}
          onFocus={() => setOpen(true)}
          onChange={(event) => {
            const nextQuery = event.target.value;
            const matchingOption = eligible.find((option) => normalize(option.name) === normalize(nextQuery));
            setQuery(nextQuery);
            setOpen(true);
            if (matchingOption) {
              onChange(matchingOption.name, matchingOption);
            } else {
              onChange("");
            }
          }}
          onKeyDown={(event) => {
            if (event.key === "ArrowDown") {
              event.preventDefault();
              setOpen(true);
              setActiveIndex((index) => Math.min(Math.max(0, filtered.length - 1), index + 1));
            } else if (event.key === "ArrowUp") {
              event.preventDefault();
              setOpen(true);
              setActiveIndex((index) => Math.max(0, index - 1));
            } else if (event.key === "Home" && open) {
              event.preventDefault();
              setActiveIndex(0);
            } else if (event.key === "End" && open) {
              event.preventDefault();
              setActiveIndex(Math.max(0, filtered.length - 1));
            } else if (event.key === "Enter" && open) {
              event.preventDefault();
              if (filtered[activeIndex]) select(filtered[activeIndex]);
              else if (canCreate) void create();
            } else if (event.key === "Escape") {
              event.preventDefault();
              setOpen(false);
            }
          }}
          className="h-full min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
        />
        {(query || value) && !disabled && (
          <button
            type="button"
            aria-label="Hapus pilihan mata pelajaran"
            title="Hapus pilihan"
            onClick={() => {
              setQuery("");
              onChange("");
              setOpen(true);
              window.requestAnimationFrame(() => rootRef.current?.querySelector("input")?.focus());
            }}
            className="grid min-h-11 min-w-11 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
          >
            <X size={16} />
          </button>
        )}
        <button
          type="button"
          aria-label={open ? "Tutup daftar mata pelajaran" : "Buka daftar mata pelajaran"}
          disabled={disabled}
          onClick={() => setOpen((current) => !current)}
          className="grid min-h-11 min-w-11 shrink-0 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
        >
          <ChevronDown size={17} className={cn("transition", open && "rotate-180")} />
        </button>
      </div>
      {menu ? createPortal(menu, rootRef.current?.closest('[role="dialog"]') ?? document.body) : null}
    </div>
  );
}
