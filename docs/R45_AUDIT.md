# R-45 audit: Dot.Design as found on 2026-11-09

Method: read every route, controller, model, policy, migration, view, the editor scripts and the exporters; ran the 329-test suite (322 pass, 7 skipped, 0 fail); logged into a local copy and walked Home, New project and the editor in a browser at 1440 px; checked the live host (`/`, `/login`, `/up` all 200; last deploy 2026-10-31, run 41202748271, green). Classes: **F** fully functional, **I** functional but needs improvement, **P** partially implemented, **V** visually present but non-functional, **B** broken, **M** missing but essential.

## Product areas

| Area | Class | Finding | Root cause |
|---|---|---|---|
| Sign-in / SSO / team sync | F | InfoDot redeem (POST body token), team UUID sync, 2FA, sessions all work and are tested | n/a |
| Dashboard | I | Real counts, but four equal metric tiles and a filler "AI Generation Activity" block; no way to start anything | Built as an admin dashboard, not a creative start screen |
| Project create | P | Name + type + free width/height; no formats, no templates, no brief, no guidance | No format catalog or template concept exists |
| Editor: canvas | P | Pan/zoom/fit, drag/resize/rotate, snapping to edges/centres, multi-page tabs; canvas sits at 43% inside a card beside a side rail | Editor was built as a page section, not a full-bleed workspace |
| Editor: element types | P | Only text, rectangle, image. No ellipse/line/polygon/star/path, no icons, no groups, no gradients, no shadow, no masks | Document model has three types with fixed keys |
| Editor: typography | M | One font (Inter), size + colour only; no weight, alignment, line height, spacing, case, wrapping control, text editing in place | Fonts not self-hosted, text model minimal |
| Editor: selection | P | Single selection only; no marquee, no multi-select, no alignment/distribution, no grouping | Selection state is one id |
| Editor: layers | I | Layer list with order buttons; no lock/hide/rename, no drag reorder | Elements have no lock/hidden/name |
| Editor: undo/redo | F | Per-page snapshot history (100 steps), unit-tested | n/a (replaced by a command history for richer edits) |
| Editor: autosave | F | Debounced CAS save on `revision`, failed/conflict banners that persist, beforeunload guard; says saved only after 2xx | Good; ported, not rewritten, in the new engine |
| Editor: recovery | M | A crash before the debounce fires loses the edit; no local journal | No recovery journal |
| Editor: mobile | V | Grid scrolls horizontally; the desktop layout is shrunk | No touch interaction model |
| Editor: accessibility | I | Labels and live status present; canvas is mouse/pointer only, no keyboard way to select or move elements except nudging a selected one | Elements are not focusable |
| Beginner experience | M | None: no templates, no plain-language controls, no guided flow | Not built |
| Professional tools | M | No guides/rulers/grid, no distribute, no masks/crop, no effects, no components | Not built |
| Templates | M | No table, no seed data, no UI | Not built |
| Brand assets | M | No brand kit (colours, fonts, logos) | Not built |
| Uploads / assets | F | GD re-encode strips EXIF, size/dimension/quota caps, throttling, policy-served private files, usage counts | Good; kept, extended with folders/tags |
| Export | I | Server SVG and PNG; PNG refuses text unless a font file is configured (production has none), no JPG/PDF/GIF, no scale/quality, no transparent option, no page selection | Server-side GD text drawing; no browser export |
| Resize | M | Changing format means a new project | No smart resize |
| Animation / video | M | None | Not built |
| AI | V/P | A prompt box that is honest: it logs a request and returns a stock placeholder image, labelled as such. No design generation, no copy help, no layout advice | Mock driver by design; no model integration |
| Collaboration | M | Jetstream teams exist for membership only. Projects and assets are owner-only (HasUserScope). No sharing, comments, review, approval, version history | Tenancy boundary is the user, not the workspace |
| Design-token catalog | F | Token sets, components, drift scan, operator-only writes, consumer records | Separate domain; kept untouched |
| Dot ecosystem | P | InfoDot SSO + team sync and a daily aggregate snapshot to Analytics work. Files, Notify, Tasks, Projects, Agents, Brain, Memory: no code | Not built; contracts not inspected until now (see R45_INTEGRATIONS) |
| Security headers | I | Enforced baseline headers; CSP report-only because the layout loads Tailwind and fonts from CDNs and uses inline scripts | CDN dependencies |
| Performance | I | Fine for three element types; the whole page re-renders through Alpine bindings per drag; Google Fonts and CDN Tailwind block first paint | Architecture |
| Visual design | B (owner-rated) | Dark pink SaaS dashboard, Syne + Inter, metric tiles, inline styles everywhere, Material Symbols from a CDN | No design system |
| Tests | I | 329 tests, strong on server (uploads, CAS, exporters, auth); the editor glue is only exercised by geometry/history unit tests and manual browser checks | n/a |
| Database | I | Four design tables, JSON `elements`, unique page number, `revision`; no team_id, no versions, no soft states | Single-tenant-per-user design |

## Major problems, ranked

1. No beginner path and no professional depth: the product cannot yet do what its name says.
2. The workspace is owner-only; business collaboration is impossible.
3. Export is the weakest link (server PNG refuses text on the real host; no JPG/PDF).
4. The visual identity is a generic dashboard; the editor is boxed in.
5. No version history or crash recovery; one browser crash can lose up to 500 ms plus network time of edits, and a bad save has no undo beyond the session.
6. External CDN dependencies block an enforced CSP and make the app fail offline.

## What is preserved

Authentication and SSO, team sync, the upload pipeline and its hardening, the CAS save protocol, the token catalog and drift scan, the Analytics snapshot, the security headers, all 329 tests (updated only where behaviour legitimately changes), and every existing project, page and asset (v1 elements are upgraded on read and rewritten only on the next real save).
