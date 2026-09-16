import { Component, type ErrorInfo, type ReactNode } from "react";

/**
 * Last line of defence for every React root the plugin mounts (2026-09-16).
 *
 * Without it, one thrown render leaves the reader a blank sealed region or the
 * publisher a blank dashboard, with nothing to click and nothing recorded.
 * Each root supplies its own fallback (the wall keeps the Sealed look; the
 * admin apps show a card) and an onError hook that reports the failure to the
 * site so it lands in Settings → Health next to the PHP errors.
 */
type Props = {
  children: ReactNode;
  fallback: (error: Error, reset: () => void) => ReactNode;
  onError?: (error: Error, info: ErrorInfo) => void;
};

type State = { error: Error | null };

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    try {
      this.props.onError?.(error, info);
    } catch {
      /* the boundary itself must never throw */
    }
  }

  reset = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    return this.state.error ? this.props.fallback(this.state.error, this.reset) : this.props.children;
  }
}

/** One-line, bounded description for an error report (no stack, no HTML). */
export function describeError(error: Error): string {
  const name = error?.name && error.name !== "Error" ? `${error.name}: ` : "";
  return `${name}${error?.message ?? "unknown error"}`.replace(/\s+/g, " ").slice(0, 160);
}
