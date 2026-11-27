import { useMemo, useState } from "react";
import { Check, Search } from "lucide-react";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { cn } from "@/lib/utils";

export type ResponsiveSelectOption = {
  value: string | number;
  label: string;
  disabled?: boolean;
};

const EMPTY_VALUE = "__bimbelku_empty_option__";

const encodeValue = (value: string | number) => String(value) === "" ? EMPTY_VALUE : String(value);
const decodeValue = (value: string) => value === EMPTY_VALUE ? "" : value;

export function ResponsiveSelect({
  id,
  value,
  onValueChange,
  options,
  placeholder = "Pilih opsi",
  ariaLabel,
  className,
  contentClassName,
  disabled = false,
  tone = "indigo",
}: {
  id?: string;
  value: string | number;
  onValueChange: (value: string) => void;
  options: ResponsiveSelectOption[];
  placeholder?: string;
  ariaLabel?: string;
  className?: string;
  contentClassName?: string;
  disabled?: boolean;
  tone?: "indigo" | "emerald";
}) {
  const encodedValue = encodeValue(value);

  return (
    <Select
      value={encodedValue}
      onValueChange={(next) => onValueChange(decodeValue(next))}
      disabled={disabled}
    >
      <SelectTrigger
        id={id}
        aria-label={ariaLabel || placeholder}
        className={cn(
          "form-field min-w-0 justify-between text-left focus:ring-4 focus:ring-indigo-100 focus:ring-offset-0",
          tone === "emerald" && "focus:border-emerald-500 focus:ring-emerald-100",
          className,
        )}
      >
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent
        position="popper"
        sideOffset={6}
        collisionPadding={12}
        className={cn(
          "z-[var(--layer-detail-popover)] max-h-[min(20rem,calc(100dvh-1.5rem))] max-w-[calc(100dvw-1.5rem)] rounded-2xl border-slate-200 bg-white shadow-2xl",
          tone === "emerald" && "border-emerald-200",
          contentClassName,
        )}
      >
        {options.map((option, index) => (
          <SelectItem
            key={`${encodeValue(option.value)}-${index}`}
            value={encodeValue(option.value)}
            disabled={option.disabled}
            className={cn(
              "min-h-11 min-w-0 rounded-xl py-2.5 pr-3 text-sm font-bold text-slate-700",
              tone === "emerald" && "focus:bg-emerald-50 focus:text-emerald-800 data-[state=checked]:bg-emerald-50 data-[state=checked]:text-emerald-800",
            )}
          >
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

export function ResponsiveMultiSelect({
  values,
  onChange,
  options,
  placeholder = "Pilih beberapa opsi",
  title = "Pilih opsi",
  description = "Pilihan dapat diubah kembali sebelum data disimpan.",
  className,
}: {
  values: string[];
  onChange: (values: string[]) => void;
  options: ResponsiveSelectOption[];
  placeholder?: string;
  title?: string;
  description?: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  const selected = useMemo(() => new Set(values.map(String)), [values]);
  const filtered = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase("id-ID");
    if (!normalized) return options;
    return options.filter((option) => option.label.toLocaleLowerCase("id-ID").includes(normalized));
  }, [options, query]);

  const summary = useMemo(() => {
    if (!values.length) return placeholder;
    const selectedLabels = options
      .filter((option) => selected.has(String(option.value)))
      .map((option) => option.label);
    if (selectedLabels.length <= 2) return selectedLabels.join(", ");
    return `${selectedLabels.length} opsi dipilih`;
  }, [options, placeholder, selected, values.length]);

  const toggle = (value: string | number) => {
    const normalized = String(value);
    if (selected.has(normalized)) {
      onChange(values.filter((item) => String(item) !== normalized));
      return;
    }
    onChange([...values, normalized]);
  };

  return (
    <Dialog open={open} onOpenChange={(next) => {
      setOpen(next);
      if (!next) setQuery("");
    }}>
      <DialogTrigger asChild>
        <button
          type="button"
          className={cn(
            "form-field flex min-w-0 items-center justify-between gap-3 text-left",
            className,
          )}
        >
          <span className={cn("min-w-0 flex-1 truncate", values.length ? "text-slate-700" : "text-slate-400")}>{summary}</span>
          <span className="shrink-0 rounded-full bg-indigo-50 px-2 py-1 text-[10px] font-black text-indigo-700">{values.length}</span>
        </button>
      </DialogTrigger>
      <DialogContent className="flex max-h-[calc(100dvh-1rem)] w-[calc(100vw-1rem)] max-w-xl flex-col gap-0 overflow-hidden rounded-[1.75rem] p-0 sm:w-[calc(100vw-2rem)] sm:rounded-[2rem]">
        <DialogHeader className="border-b border-slate-100 p-5 pr-12 text-left">
          <DialogTitle className="font-black text-slate-900">{title}</DialogTitle>
          <DialogDescription className="mt-1 leading-5">{description}</DialogDescription>
        </DialogHeader>
        <div className="border-b border-slate-100 p-4">
          <label className="relative block">
            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={17} />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm font-semibold outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              placeholder="Cari opsi…"
            />
          </label>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3">
          {filtered.length ? filtered.map((option) => {
            const checked = selected.has(String(option.value));
            return (
              <button
                key={String(option.value)}
                type="button"
                role="checkbox"
                aria-checked={checked}
                disabled={option.disabled}
                onClick={() => toggle(option.value)}
                className={cn(
                  "mb-2 flex min-h-12 w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left text-sm font-bold transition last:mb-0",
                  checked ? "border-indigo-200 bg-indigo-50 text-indigo-800" : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50",
                  option.disabled && "cursor-not-allowed opacity-50",
                )}
              >
                <span className={cn("grid h-5 w-5 shrink-0 place-items-center rounded-md border", checked ? "border-indigo-600 bg-indigo-600 text-white" : "border-slate-300 bg-white")}>{checked && <Check size={14} />}</span>
                <span className="min-w-0 flex-1 break-words">{option.label}</span>
              </button>
            );
          }) : <p className="p-8 text-center text-sm font-semibold text-slate-500">Opsi tidak ditemukan.</p>}
        </div>
        <DialogFooter className="grid grid-cols-1 gap-2 border-t border-slate-100 bg-white p-4 min-[360px]:grid-cols-2 sm:flex sm:flex-row sm:justify-between sm:space-x-0">
          <button type="button" onClick={() => onChange([])} className="min-h-11 min-w-0 rounded-xl border border-slate-200 px-4 py-2 text-sm font-black text-slate-700">Kosongkan</button>
          <button type="button" onClick={() => setOpen(false)} className="min-h-11 min-w-0 rounded-xl bg-indigo-600 px-5 py-2 text-sm font-black text-white">Selesai ({values.length})</button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
