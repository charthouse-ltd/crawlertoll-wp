import { Alerts } from "./views/Alerts";
import { Dashboard } from "./views/Dashboard";
import { Logs } from "./views/Logs";
import { Pricing } from "./views/Pricing";
import { Rails } from "./views/Rails";

export type ProView = "dashboard" | "logs" | "pricing" | "alerts" | "rails";

// Thin router: each Pro tab mounts the same pro-app and tags its node with
// data-view (read in main.tsx). Only one tab renders per page, so there's no
// client-side navigation to manage — the PHP tab nav still drives which view
// loads. New surfaces slot in here.
export function App({ view }: { view: ProView }) {
  const body =
    view === "logs" ? (
      <Logs />
    ) : view === "pricing" ? (
      <Pricing />
    ) : view === "alerts" ? (
      <Alerts />
    ) : view === "rails" ? (
      <Rails />
    ) : (
      <Dashboard />
    );
  return <div className="ct-pro">{body}</div>;
}
