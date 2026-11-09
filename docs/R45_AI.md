# R-45 assistant: idea to design, safe improvements, honest copy help

Owner: the ai agent. Branch `feat/r45-ai`. Decisions: `docs/R45_DECISIONS.md` D-1100..D-1112. Code: `app/Services/Design/Assistant/**`, `config/design_ai.php`, `routes/studio/ai.php`, `app/Http/Controllers/Studio/Ai*`, `resources/js/studio/assist/**`, `resources/js/studio/ui/modules/assistant.js`, `resources/css/studio-assistant.css`, `app/Providers/StudioAiServiceProvider.php`, table `design_ai_proposals`.

## 1. What is AI, what is not

| Feature | Uses the model? | Always available? |
|---|---|---|
| Quick fixes: low contrast text, headline emphasis, text overflow, margins, near-alignment, even spacing, brand colours | **No.** Pure rules in `assist/heuristics.js`. Labelled "Automatic fix" | Yes |
| "Suitable for Instagram" and other platform sizes | **No.** Parsed from the words in code (`ResizeSuggester`); the answer is a resize suggestion for the resize module | Yes |
| Compose: idea to editable design | Yes, to PLAN only (template, format, style, palette choice, short copy, essential questions) | Only with a key |
| Improve: free instruction or chip ("more premium", "easier to read") | Yes, returns a short list of patch operations | Only with a key |
| Marketing copy suggestions (headline, promo, product description, call to action, caption, campaign idea) | Yes | Only with a key |
| Image generation, logos, background removal | **Not offered.** There is no provider contract. The old mock image driver and `AiGenerationController` route stay for API compatibility but nothing in the product links to them | n/a |
| Dot.Agents, Dot.Brain, Dot.Memory | **Not integrated.** `docs/R45_INTEGRATIONS.md` found no supported API; nothing here pretends otherwise | n/a |

No key (`ANTHROPIC_API_KEY` empty) or `DESIGN_AI_ENABLED=false` means the assistant is OFF: every model endpoint answers `{status: "unavailable", message}` with HTTP 200, nothing is sent anywhere, and the panel says so and lists what still works. Nothing is ever simulated.

## 2. Safety rules (each is enforced in code and tested)

1. **The model proposes, code decides.** Compose uses a validated plan, never layout JSON from the model: quality and safety. Improve uses a restricted operation set. Copy is plain strings that are filtered.
2. **Nothing changes a design without Apply.** An improve answer is a `design_ai_proposals` row (`proposed`). Apply records the decision, takes a version snapshot first (`VersionService::checkpoint`), and the browser then applies every operation as ONE undoable engine command (`doc.replacePages`). Dismiss records `dismissed`. A used proposal cannot be reused (409).
3. **Never replaces existing work.** Compose always creates a NEW draft, private project. Improve cannot delete (`delete` is not an operation), cannot change any existing word (`text` is not a property), cannot touch pictures, locked elements, or ids that are not on the page. Elements that bleed off the page are not moved.
4. **Text accuracy.** `LiteralExtractor` pulls phone numbers, email, website, social handle, prices, dates, times, addresses and plainly stated business names out of what the person typed. They are substituted by code and always win over model text. A business name the model proposes is used only if it appears verbatim in the person's words. Every number, link, email and handle in model copy (compose slots, new text, marketing copy) must already be in the person's text (`TextGuard::unsupported`), otherwise that suggestion is dropped and the person is told.
5. **Brand.** With a kit: model-proposed colours snap to the nearest brand colour (or black or white), fonts to the brand fonts, and compose applies the kit through the template's own tokens (`TemplateInstantiator`) only when a kit exists. Brand voice `avoid` words are enforced on copy. Automatic fixes keep the colours they chose.
6. **Readability.** After the operations are valid, any text colour or shape fill change that would leave text under 3:1 against its backdrop is repaired (best readable ink) or dropped.
7. **Prompt injection.** User text, page text, brand text and template names travel only inside delimited data blocks (`<user_request>`, `<page>`, `<brief>`, `<brand_kit>`, `<answers>`); `<` and `>` in them are neutralised so they cannot close a block; the system prompt states that block content is data. The tool schemas leave no free-form execution surface; whatever comes back is validated like client input. Covered by `ComposeTest`, `CopyTest`, `PipelineWithRealClientTest` (a hostile page text and prompt).
8. **Size and canvas.** Compose respects a requested format (a template of another size is swapped for one of the right size, or a blank page of the right size). Improve clamps every box inside the page.
9. **Secrets.** The key is read from `ANTHROPIC_API_KEY`, sent only in the `x-api-key` header, never logged. Logs carry status, error type and the provider request id only (tested). API errors shown to people are plain sentences with no model name or provider text.
10. **Tenancy.** Invisible projects are 404 before any model call; proposals belong to their user (404 for others) and applying re-checks the edit capability on the project (403 after a demotion).

## 3. Endpoints (JSON; all signed-in; routes in `routes/studio/ai.php`)

| Route name | Body | Answers |
|---|---|---|
| `GET design/assistant/status` (`design.assistant.status`) | | `{enabled, message, usage:{user:{used,limit}, workspace:{used,limit}}, automatic_fixes:true, image_generation:false}` |
| `POST design/assistant/compose` | `{prompt (3..1500), format_key?, answers?: {business_name\|what\|date\|venue\|offer: text}, brand_kit_id?}` | `201 {status:'ok', project_url, project:{id,name}, template, format_key, filled[], left[{slot,label,reason}], warnings[], brand_applied}` or `200 {status:'questions', questions:[{id,text}]}` (answer them by posting again with `answers`; questions are never asked twice) or `200 {status:'unavailable', message}` |
| `POST design/assistant/copy` | `{kind: headline\|promo\|product_description\|cta\|caption\|campaign_concept, business_name?, product?, audience?, offer?, details?, tone?, count<=5, brand_kit_id?, project_id?}` | `{status:'ok', suggestions:[{id,text}], dropped, proposal_id}` or `{status:'nothing', message}` |
| `POST design/projects/{project}/assistant/improve` | `{instruction (3..500), page_id, selection?: [element ids]}` | `201 {status:'ok', proposal:{id, type:'patch'\|'suggest_resize', source, page_id, base_revision, summary, changes[{element_id,label,prop,from,to,text}], ops[], notes[], format_key?}}` or `200 {status:'nothing', message}` |
| `POST design/projects/{project}/assistant/proposals` | `{page_id, label, ops[], selection?}` | An automatic fix computed in the browser, validated by the same `OpValidator`, stored as a proposal (no model call, not counted against caps) |
| `POST design/ai-proposals/{proposal}/apply` | | `{status:'applied', version_id}` (snapshot taken first). The browser then applies the ops |
| `POST design/ai-proposals/{proposal}/dismiss` | | `{status:'dismissed'}` |

Failures: `429 {status:'limit', scope:'user'|'workspace', message, usage}` (daily cap), `429 {status:'throttled'}` (per-minute limiter `design-ai`), `503/502 {status:'failed', message, retryable}` (provider trouble after retries or an unusable answer; nothing was changed), `422` validation.

Operation shapes: `{op:'set', id, props:{color|fill|fontSize|fontWeight|font|lineHeight|letterSpacing|align|x|y|width|height|opacity|rotation|shadow}}`, `{op:'add', element:{type:'text'|'shape', ...}, behind_id?}` (at most 3, simple rectangles and ellipses, text only if it adds no new facts), `{op:'reorder', id, to:'forward'|'back'|'front'|'back-most'}` (top level only).

## 4. Model access

`App\Services\Design\Assistant\AnthropicClient` calls `POST {base_url}/v1/messages` with Laravel's Http client (no SDK package: D-1100). One request is one tool call. Because the default model refuses a forced `tool_choice`, the tool is requested through the instructions with `tool_choice: auto`; an answer that is prose, truncated, a refusal or another tool is a failure, never guessed at. Retries on 429, 5xx and network errors (doubling backoff, honours `Retry-After` up to a cap); 4xx are not retried. A circuit opens after 5 failed calls in a row for 60 seconds (calls are refused without touching the network and do not count against caps). Token usage is stored on the proposal row (`tokens_in`, `tokens_out`). Everything is testable with `Http::fake` or by binding a fake `ModelClient` (`tests/Support/FakeModelClient.php`).

### Environment variables
| Variable | Default | Meaning |
|---|---|---|
| `ANTHROPIC_API_KEY` | empty | Shared with `services.anthropic`. Empty = assistant OFF |
| `DESIGN_AI_ENABLED` | true | Master switch |
| `DESIGN_AI_MODEL` | `claude-sonnet-5-5` | Model id |
| `DESIGN_AI_BASE_URL` | `https://api.anthropic.com` | Also how the local stub is reached in checks |
| `DESIGN_AI_API_VERSION` | `2026-10-21` | `anthropic-version` header |
| `DESIGN_AI_THINKING` | `between_tools` | `between_tools`, `adaptive` or `omit` |
| `DESIGN_AI_EFFORT` | `low` | `low`, `medium`, `high` or empty to omit |
| `DESIGN_AI_STRICT_TOOLS` | true | Send `strict: true` on the tool |
| `DESIGN_AI_TIMEOUT`, `DESIGN_AI_RETRIES` | 60, 2 | |
| `DESIGN_AI_USER_DAILY`, `DESIGN_AI_WORKSPACE_DAILY`, `DESIGN_AI_PER_MINUTE` | 60, 300, 12 | Caps |

## 5. Costs and caps
Caps count calls to the model per calendar day (application timezone), from `design_ai_proposals.model IS NOT NULL`; failed calls count, refused ones do not. Per person 60, per workspace 300, 12 a minute. Rough size of a call (estimates from the 2026-10-06 price table at $2 in / $10 out per million tokens, not measured against a bill): compose sends the catalogue of 58 templates and 42 formats, about 24 KB or 7 to 8 thousand tokens, so around 2 cents; improve sends one page digest (at most 80 elements), around 1 cent; copy under half a cent. Worst case at the caps is about $1.20 per person and $6 per workspace per day. Prompt caching is not used yet (the catalogue prefix is stable per workspace, so it is the obvious next saving). The usage meter in the panel reads the server's count after every call. `design:prune-ai-proposals` (scheduled daily 03:30) deletes rows older than `retention_days` (90; applied compose rows twice as long).

## 6. The panel (`ui/modules/assistant.js`)
Registered per `docs/R45_UI_CONTRACT.md`: left tabs "Ask for help" (Simple) and "Assistant" (Pro), one panel behind both. Improve section: quick fixes (always on, with an honest "not available" reason where a fix needs a brand kit or a text measurer), instruction chips and a free box (only with the AI flag), the usage meter, and an off-state well that says what still works. Review: before and after drawn by the engine's own renderer on a scratch copy, a plain list of changes (with colour swatches), notes, Apply and Dismiss. Write section: copy suggestions placed as a new text box or replacing the one selected text box (each one undo step). Loading is skeleton bars and a Cancel; a late answer after Cancel is ignored; every failure has a message and a way back. Logic lives in `assist/controller.js`, the state machine in `assist/proposal-state.js`, the applier in `assist/ops.js`, the rules in `assist/heuristics.js`. Dark and light, 390 px and desktop checked in a browser.

## 7. Tests
PHP (`tests/Feature/Ai`, `tests/Unit/Ai`): compose, improve, copy, caps and status, the raw client against `Http::fake`, the whole path with the real client, validators and guards (87 tests). JS (`tests/js/assist-*.test.mjs`): heuristics fixtures, the applier as one transaction with full undo against the real engine core, the state machine, the controller flows (51 tests).
Browser checks used a local stub of the Messages API (`php -S`, returns recorded tool-use shapes, never contacts the internet, no real key anywhere) and the real dev server.

## 8. Limitations (honest)
- **The real Anthropic API was never called.** The request shape follows the current API notes (tool use, `tool_choice` auto, `between_tools` thinking, low effort, strict tools, version `2026-10-21`). If the live API rejects a field, change `DESIGN_AI_THINKING=omit`, `DESIGN_AI_EFFORT=`, `DESIGN_AI_STRICT_TOOLS=false` or `DESIGN_AI_API_VERSION` without a code change. First production call should be done by hand.
- Compose can only choose from the template library; a blank result is an empty page of the right size. Template sample text that no slot replaced is reported in `left` so the UI can say it still shows sample text.
- Contrast repair checks top-level elements; children of groups are not contrast-checked.
- The engine has no public transaction, so Apply uses one `doc.replacePages` command plus a system `doc.reflow`; after undo then redo, grow-to-fit text heights are re-measured only on the next edit.
- If the engine refuses the change after the server recorded Apply, the proposal stays `applied` in the log although the design did not change (the panel says so and nothing was changed). A local dry run before the server call makes this very rare.
- Prompt injection is mitigated, not impossible: the worst a hostile text can do is produce a proposal that passes the validators, which the person still has to review.
- Daily caps are counted in the application timezone; a cache flush does not reset them (they are database rows).
- The panel opens the resize tool with `ctx.openDialog('resize', {format_key})`; if that dialog id does not exist the panel says so and tells the person where to find Resize.
