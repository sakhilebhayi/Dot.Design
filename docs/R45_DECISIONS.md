# R-45 decisions log

Append-only. Format: `D-n (agent, date): decision. Why.` On merge conflicts keep both sides.

D-1 (coordinator, 2026-11-09): Workspace tenancy = Jetstream Team; projects stay private to the owner by default, `visibility=team` shares with the workspace. Why: matches InfoDot team sync and the brief's business-workspace model without breaking existing owner-only rows.
D-2 (coordinator, 2026-11-09): Editor is vanilla ES modules built by Vite with ONE SVG renderer for screen and export. Why: export parity, no Alpine/Livewire start-order coupling, testable pure modules.
D-3 (coordinator, 2026-11-09): Document schema lives in resources/design/document-schema.json, read by PHP and JS. Why: one source of truth, no drift.
D-4 (coordinator, 2026-11-09): No real-time co-editing, background removal, social publishing, stock photos or MP4 export; each is stated in the report as not built. Why: no honest implementation is possible on the shared host / without licences or APIs.
D-5 (coordinator, 2026-11-09): schema bind_key pattern is ^[a-z][a-z0-9_]{0,39}$ so photo_1..3 are valid (found by business agent, D-402).
