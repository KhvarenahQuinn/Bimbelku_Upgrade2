import { useEffect, useRef, useState } from "react";
import { Input } from "@/components/ui/input";

type DateOfBirthInputProps = {
  id?: string;
  value: string;
  max: string;
  onChange: (value: string) => void;
  className?: string;
};

const formatIsoDate = (value: string) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "";
};

const formatTypedDate = (value: string) => {
  const digits = value.replace(/\D/g, "").slice(0, 8);
  if (digits.length <= 2) return digits;
  if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`;
  return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
};

const toIsoDate = (value: string, max: string) => {
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
  if (!match) return "";

  const day = Number(match[1]);
  const month = Number(match[2]);
  const year = Number(match[3]);
  const candidate = new Date(year, month - 1, day);
  const valid = candidate.getFullYear() === year
    && candidate.getMonth() === month - 1
    && candidate.getDate() === day;
  if (!valid) return "";

  const iso = `${String(year).padStart(4, "0")}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
  return iso <= max ? iso : "";
};

export default function DateOfBirthInput({ id, value, max, onChange, className = "" }: DateOfBirthInputProps) {
  const [displayValue, setDisplayValue] = useState(() => formatIsoDate(value));
  const inputRef = useRef<HTMLInputElement>(null);
  const helpId = id ? `${id}-help` : "date-of-birth-help";

  useEffect(() => {
    if (value && value !== toIsoDate(displayValue, max)) {
      setDisplayValue(formatIsoDate(value));
    }
  }, [displayValue, max, value]);

  const update = (rawValue: string) => {
    const formatted = formatTypedDate(rawValue);
    const iso = toIsoDate(formatted, max);
    setDisplayValue(formatted);
    onChange(iso);

    if (inputRef.current) {
      const fullButInvalid = formatted.length === 10 && !iso;
      inputRef.current.setCustomValidity(fullButInvalid ? "Masukkan tanggal lahir yang valid dan tidak melewati hari ini." : "");
    }
  };

  return (
    <div>
      <Input
        ref={inputRef}
        id={id}
        required
        type="text"
        inputMode="numeric"
        autoComplete="bday"
        enterKeyHint="next"
        maxLength={10}
        pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}"
        aria-describedby={helpId}
        className={className}
        value={displayValue}
        onChange={(event) => update(event.target.value)}
        onBlur={() => {
          if (inputRef.current && displayValue.length > 0 && !toIsoDate(displayValue, max)) {
            inputRef.current.setCustomValidity("Masukkan tanggal lahir yang valid dengan format HH/BB/TTTT.");
          }
        }}
        placeholder="HH/BB/TTTT"
      />
      <p id={helpId} className="mt-2 text-xs font-medium leading-5 text-slate-500">
        Ketik delapan angka. Contoh: 17082005.
      </p>
    </div>
  );
}
