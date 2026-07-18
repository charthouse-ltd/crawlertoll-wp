import { useState, type ReactNode } from "react";
import { useSettings } from "../api";
import type { SettingsResponse } from "../types";

// Shared presentational primitives for the Pro app (scoped under .ct-pro).

export function Card({ title, desc, children, bodyClass }: { title?: string; desc?: string; children: ReactNode; bodyClass?: string }) {
  return (
    <div className="ct-pro-card p-5">
      {title && <h3 className="text-[15px] font-semibold">{title}</h3>}
      {desc && (
        <p className="mt-0.5 text-[13px]" style={{ color: "var(--ct-muted)" }}>
          {desc}
        </p>
      )}
      <div className={title || desc ? `mt-4 ${bodyClass ?? ""}` : bodyClass}>{children}</div>
    </div>
  );
}

export function EmptyState({ children }: { children: ReactNode }) {
  return (
    <p className="py-8 text-center text-[13px]" style={{ color: "var(--ct-muted)" }}>
      {children}
    </p>
  );
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="ct-pro-card p-8 text-center">
      <div className="text-[15px] font-semibold">Couldn’t load this view</div>
      <p className="mx-auto mt-1 max-w-sm text-[13px]" style={{ color: "var(--ct-muted)" }}>
        {message}. Your data is safe — this is just the live view.
      </p>
      <button
        type="button"
        onClick={onRetry}
        className="mt-4 rounded-lg px-4 py-2 text-[13px] font-semibold text-white"
        style={{ background: "var(--ct-accent)" }}
      >
        Try again
      </button>
    </div>
  );
}

const ACTION_STYLE: Record<string, { label: string; bg: string; fg: string }> = {
  allow: { label: "Allow", bg: "#d1fae5", fg: "#065f46" },
  "402": { label: "402", bg: "#fef3c7", fg: "#92400e" },
  block: { label: "403", bg: "#fee2e2", fg: "#991b1b" },
};

export function ActionBadge({ action }: { action: string }) {
  const s = ACTION_STYLE[action] ?? ACTION_STYLE.allow;
  return (
    <span
      className="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold"
      style={{ background: s.bg, color: s.fg }}
    >
      {s.label}
    </span>
  );
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
        {label}
      </span>
      {children}
    </label>
  );
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode }) {
  return (
    <label className="flex cursor-pointer items-center gap-3">
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className="relative h-6 w-11 shrink-0 rounded-full transition-colors"
        style={{ background: checked ? "var(--ct-accent)" : "var(--ct-border)" }}
      >
        <span
          className="block h-5 w-5 rounded-full bg-white transition-transform"
          style={{ transform: checked ? "translateX(22px)" : "translateX(2px)" }}
        />
      </button>
      <span className="text-[13px]">{label}</span>
    </label>
  );
}

// Save button with inline saving/saved/error feedback. onSave should throw on
// failure (the api save* helpers do).
export function SaveButton({ onSave, label = "Save changes" }: { onSave: () => Promise<void>; label?: string }) {
  const [status, setStatus] = useState<"idle" | "saving" | "saved" | "error">("idle");
  const [err, setErr] = useState("");

  const run = async () => {
    setStatus("saving");
    setErr("");
    try {
      await onSave();
      setStatus("saved");
      window.setTimeout(() => setStatus("idle"), 2500);
    } catch (e: unknown) {
      setStatus("error");
      setErr(e instanceof Error ? e.message : "Save failed");
    }
  };

  return (
    <div className="flex items-center gap-3">
      <button
        type="button"
        onClick={run}
        disabled={status === "saving"}
        className="rounded-lg px-4 py-2 text-[13px] font-semibold text-white disabled:opacity-60"
        style={{ background: "var(--ct-accent)" }}
      >
        {status === "saving" ? "Saving…" : label}
      </button>
      {status === "saved" && (
        <span className="text-[12px] font-semibold" style={{ color: "var(--ct-success)" }}>
          ✓ Saved
        </span>
      )}
      {status === "error" && (
        <span className="text-[12px] font-semibold" style={{ color: "var(--ct-danger)" }}>
          {err}
        </span>
      )}
    </div>
  );
}

// Loads /settings once, then renders the form. Each form seeds its own local
// state from the resolved data (so it only renders after load).
export function SettingsGate({ render }: { render: (s: SettingsResponse) => ReactNode }) {
  const [tick, setTick] = useState(0);
  const { data, loading, error } = useSettings(tick);

  if (error) {
    return <ErrorBox message={error} onRetry={() => setTick((t) => t + 1)} />;
  }
  if (loading || !data) {
    return (
      <div className="ct-pro-card p-5">
        <div className="ct-skeleton h-5 w-40" />
        <div className="ct-skeleton mt-4 h-9 w-full" />
        <div className="ct-skeleton mt-3 h-9 w-2/3" />
      </div>
    );
  }
  return <>{render(data)}</>;
}
