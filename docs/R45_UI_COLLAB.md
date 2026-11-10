# R-45 collaboration UI (collab agent)

Owner: collab agent. Decisions `D-1200` to `D-1213` in `docs/R45_DECISIONS.md`. Skills: `docs/R45_SKILLS.md` (collab section). Screens: `docs/R45_screens/collab/`. The backend this sits on is `docs/R45_BACKEND.md`; the module contract is `docs/R45_UI_CONTRACT.md`.

A business owner makes a design, asks a colleague to review it, people comment on exact spots, the reviewer approves or asks for changes, history can be restored and a client can look through a share link. Everything respects roles and workspace isolation (the server decides; the UI only offers what the rules allow and always shows the server's refusal in plain words).

## What was built

| Piece | Where | Notes |
|---|---|---|
| Share dialog | `ui/modules/share.js` | Who can open it (Only me / workspace), people with access (read-only, link to workspace settings), share links: view or comment, expiry, "Allow downloads", copy once, list with status and views, turn off with confirm. A sentence beside the controls says what a link allows. |
| Comments | `ui/modules/comments.js`, `collab/comment-ui.js`, `collab/pins.js`, `collab/mentions.js`, `collab/comments-state.js` | Left tab "Comments", pins on the canvas, popover thread, replies, resolve/reopen, @mentions, guest label, filters, edit and delete own, plain-text rendering. |
| Version history | `ui/modules/versions.js`, `collab/versions-state.js` | Left tab "History", "Save this version", read-only snapshot through the engine viewer, diff summary vs now, Restore. |
| Review | `ui/modules/review.js`, `collab/review-state.js` | Status chip, Request review, reviewer bar (Approve, Request changes with required note, Compare with now), cancel, stale banner, "Download final". |
| Save to Dot.Files | `ui/modules/files.js` | Only when `config.flags.filesVault` and the shell can render an export. |
| Review inbox | `routes/studio/collab.php`, `ReviewInboxController`, `views/design/reviews/index.blade.php`, `css/pages/reviews.css` | Route `design.reviews.index` at `/design/review`. Server rendered, no script. The rail shows it by route name. |
| Share viewer | `views/design/share.blade.php`, `share-unavailable.blade.php`, `js/studio/pages/share-viewer.js`, `css/pages/share.css`, `Support/Design/ShareFonts.php` | `/s/{token}`. |
| Activity | `ActivityPageController`, `views/design/activity/index.blade.php`, `css/pages/activity.css`, `Support/Design/ActivitySentence.php` | Route `design.activity.page` at `/design/activity/log`; owners and admins. Menu link added to the layout (admins only). |
| People endpoint | `PeopleController`, route `design.projects.people` | `GET design/projects/{id}/people`: names, roles and what each person can do with this design. Feeds the mention picker, the reviewer picker and the access list. No addresses. |
| Shared helpers | `js/studio/collab/*.js` | Pure logic (state machines, geometry, validation, wording) plus `dom.js` (text-only element builder) and `ui.js`. Modules import these, never each other. |
| Styles | `css/studio-collab.css` | `cb-` classes, tokens only; imported by the modules so it ships with whichever entry loads them. |

## How each requirement was met

**Share.** Permission gated by `config.permissions.share` (or the backend name `share_link`). Visibility change is a `PUT project {visibility}` and is offered only when the people endpoint says the person may change it. The created link's URL exists only in the create response; it is held in `createLinkVault` in memory, shown once with Copy, and forgotten on "Done" or when the dialog closes. The list afterwards shows created, expiry, downloads, views and last opened, never an address. 403, 404, 409 (too many links), 429 and network failures each have their own sentence (`collab/errors.js`).

**Comments.** Choose "Add comment" (or press C, or the "Comment on this" context action), then click the design. With one element selected and the click inside it the comment is anchored to the element, otherwise to the point. Convention (decision D-1202): with an element id, x/y are an offset in the element's own unrotated box; the pin is carried through the element's matrix (rotation, flip, parent groups) so it moves, rotates and scales with it. An element that has been deleted shows no pin on the canvas (it stays in the list with its page). Pins are placed from `studio.viewport.view` and redrawn on the engine's `viewport`, `change` and `page` events, throttled to one frame. People with comment but not edit capability get the same panel and pins. Text is set with `textContent`; a test and a browser check confirm `<b>` and `<script>` in a comment stay text. Mentions: typing @ opens a listbox (arrows, Enter or Tab, Escape); people who cannot open the design yet are listed but disabled with the reason, because the server refuses mentions of people who cannot see it.

**Versions.** List from the server (cursor paged), filters, named versions, approved ones marked, "Kept for good" shown for everything but automatic saves. A snapshot opens in a dialog with the engine's `createViewer` (the editor's own renderer), pages, and the backend diff summarised in one sentence. Restore confirms in plain words, flushes unsaved edits first (so they land in the safety copy), posts the restore, discards the local crash journal and reloads the editor from the server (decision D-1203; `ctx.reloadDocument()` is used when the shell offers it).

**Review.** States map from the server (`collab/review-state.js`, tested): Draft, In review, Changes requested, Approved, Changed since approval (and Archived). Request review pins a snapshot ("Sent for review") and says so; the reviewer sees a bar with Approve, Request changes (a note is required, checked in the browser and by the server) and "Compare with now" which opens the pinned snapshot in the version dialog. The requester and workspace admins can cancel. The server's separation-of-duties messages are translated into everyday wording and shown exactly where the action happened. After an edit is saved while approved, the module looks again shortly after (`save-state` saved) and shows "This design changed after it was approved. Ask for a new review." with a "Request a new review" button.

**Notifications honesty (D-1206).** The integration reports only the outcome of its LAST delivery, not whether this request produced one. So the UI never says "A notification was sent". After a request it says the reviewer will see it in their Review inbox, and, only when Dot.Notify reports "connected", that an email is also queued if the business switched that event on. A test asserts no wording can claim "was sent".

**Share viewer.** Server draws every page into the HTML (this is the whole viewer without script and the source of the page-strip thumbnails). With script: the engine viewer (zoom, pan, pinch, keys), page strip, light and dark, and, for comment links, a form: name, comment with counter, optional "Point to a spot on the design", the honeypot field the backend requires, field and status messages for 422, 429, 404 and network. Guests see only the comments they made in this visit as pins and a short list: there is no endpoint that lists a design's comments to a link holder, on purpose (D-1205). No cookie is set, nothing is stored except an optional theme choice in `localStorage` (try/catch). `noindex` stays in the markup and the headers. Unknown, malformed, expired and revoked links, and the rate limit page, all render the same neutral page; it ignores the controller's message text so nothing can differ (D-1208).

**Review inbox.** Tabs: Assigned to me (count of waiting), Requested by me, Recent decisions (reviews decided on designs the viewer can see). Rows, not cards: thumbnail on the stage grey (the backend thumbnail route when the design has one, otherwise the neutral frame), name, who and when, the note, status tag, one action ("Open and review" for the person who must decide). Empty states explain the flow.

**Activity.** Owners and admins only (403 for others, 404 for non-members). Filters: what happened, design, from and to date; cursor paging; one plain sentence per row; never a token, address or comment text (the log scrubs them; the sentences only use action, actor name, design name and a few short values).

## Contract extensions this work relies on (all feature-detected; none is required)

The editor-ui shell may implement these; without them everything still works and the fallback is stated.

1. `registerTopbarAction(...)` returning a handle with `update({label, tone})`: the Review action then shows the status as its label. Fallback: the status chip on the canvas and in the Review dialog.
2. `registerLeftTab(...)` returning `{setBadge(n)}`: open-comment count on the tab. Fallback: the count is inside the panel.
3. `ctx.exports.render({format, scope}) -> Promise<Blob>` (formats png, jpg, pdf, svg; scope `all` or `page-N`): builds the same file the Export dialog would. Without it "Save to Dot.Files" stays hidden. When output's export module lands, the shell should expose it here.
4. `ctx.api.postForm(url, FormData)` (or `ctx.api.post` passing `FormData` through untouched, no JSON content type) for the Dot.Files upload.
5. `ctx.reloadDocument()` to reload the pages without a full page load after a restore. Fallback: `location.reload()`.
6. `ctx.openDialog('export')` exists (used by "Download final").
7. `config.urls` may name `projectBase`, `comments`, `shares`, `versions`, `reviews`, `people`, `dotFiles`, `reviewInbox`, `integrations`; otherwise URLs are built from `config.project.id` (the routes are fixed).
8. `config.permissions` accepts the contract names (`share`, `review`) or the backend's (`share_link`, `request_review`, or the `can` map).
9. The modules draw two overlays inside `studio.root` (comment pins and popover, the review bar). Both carry `data-studio-ignore` and stop pointer and key events, so the engine's gestures never see them. They need `studio.root`, `studio.viewport.view`, `studio.activePage()`, `studio.getState()`, `studio.on('viewport'|'change'|'page'|'save-state')` (all in `docs/R45_ENGINE.md`).
10. Icons: modules ask for none (`icon` strings are passed to the register calls only), so no icon helper is needed.

## Honest limits

- No live co-editing and no presence. Two people editing the same page get the engine's revision check (the second save conflicts and the person chooses). Comments are not pushed: the list refreshes when the tab opens, after your own actions and when the window regains focus.
- A review bar and the review state refresh on focus and shortly after a save while approved; a reviewer's answer reaches the requester when they next focus the window or open the dialog.
- Notifications: see above. Whether an email left depends on the business's Dot.Notify rules; the UI cannot know.
- Share viewer download: the "Allow downloads" setting is stored and returned, but the viewer shows no Download button yet. It needs the output agent's export module for a public page (pictures there are 10-minute signed URLs, so a client-side export has to fetch them first). The control stays hidden rather than pretending. Open item for the coordinator.
- Share viewer pictures are signed URLs that last 10 minutes; a tab left open longer shows missing pictures until reloaded.
- The version diff counts elements that the first save normalised (old v1 shapes gain default fields), so "N elements changed" can be higher than what a person changed. It comes from the backend.
- Pins of a comment anchored to a deleted element are not drawn (the list says so by page only). Rotation and flip are honoured; group rotation is honoured because the whole matrix chain is used.
- Mentions: names only; people who cannot open the design cannot be mentioned until it is shared with the workspace or with them.
- The thread popover and the left panel are separate renderings of the same data; a reply typed in one is not mirrored live into the other until the next refresh (it appears right after sending).
- The editor shell does not exist on this branch (editor-ui builds it in parallel), so the modules were exercised in a throwaway harness (a fake shell implementing the contract around the real engine, modules and API; not committed). The first thing to check after merge is the shell's mount points (left tab body height, dialog sizes, where topbar actions land).

## Verification

PHP: `tests/Feature/StudioCollabPagesTest.php` (19 tests: inbox by role and tab, isolation, decisions with notes, thumbnails, rail entry, activity sentences and scrubbing, filters, paging, permissions, link visibility, people endpoint incl. private designs and outsiders, share viewer markup, honeypot and limits, fonts, identical neutral page). JS: `tests/js/collab-*.test.mjs` (pin geometry incl. rotation, groups, zoom and pan; mention picker; review state machine and wording; version list and restore state machines; share link vault and wording; comment filters and validation; module registration and gating against a fake context).

Browser (built-in pane and isolated headless Chrome sessions, five users: owner, editor, reviewer ("viewer" role), admin, outsider, plus an anonymous guest): owner shared the design with the workspace, made a comment link (comment, downloads allowed), saw the URL once, copied; guest commented through the viewer with a pinned spot (success, count, own pin); owner saw the comment with a Guest label and a pin, replied with a mention, resolved and reopened, hit the 2000 character limit, checked that `<b>` and `<script>` stay text; owner requested a review; reviewer's Review inbox listed it; reviewer tried Request changes with no note (blocked), then sent a note; owner saw the note and asked again; reviewer approved; owner moved an element and the stale banner appeared on its own; owner saved a named version, viewed a snapshot, restored (safety copy appeared, pages reloaded); owner turned the link off and the guest got the neutral page; outsider received 404 on every project route and an empty inbox, 404 on the activity page; the reviewer role could comment (201) and could not edit, share or restore (403); a comment pinned to an element followed it through move, zoom and rotation; admin activity page filters. Screens at 1440 and 390, light and dark. `impeccable detect` on the rendered pages: no findings from the new CSS (the remaining ones belong to the ds shell: `lt-sheet` padding, html/body overflow clip, one accent glow).

## pmod update (2026-11-09): review bar, pins, controls, Dot.Files entry

Full account: `docs/R45_POLISH_MODULES.md`. Decisions D-2004, D-2007, D-2008, D-2009.

- The review bar is a strip above the canvas (child of `.st-stagewrap`, `--cb-bar-h`, `data-cb-bar`), compact: status chip, who asked, "Version sent" link, actions; wraps above the page on a phone.
- Pins: anchor dot, numbered marker beside the element's nearest edge, leader line; markers avoid each other and their own element (`layoutMarkers`, `markerHome`, `leader`, `elementScreenQuad` in `collab/pins.js`); hover or focus previews the thread; opening a thread outlines the element; "Hide comments" on the canvas and a switch in the panel; 44 px hit areas.
- `collab/ui.js`: `select()` is the custom select, `peopleCombobox()` is new, `field()` labels custom controls through their button or input. The reviewer picker is a combobox with avatars; share visibility is radio cards; "Allow downloads" is a switch; the Dot.Files acknowledgement is a custom check box.
- "Save to Dot.Files" is no longer a top bar action. It is a button in the Download and Share dialogs (`ui/modules/_files-entry.js`).
