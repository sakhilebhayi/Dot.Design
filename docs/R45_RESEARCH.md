# R-45 design research

Honest method note: this is a study from public product behaviour, documentation and common user reports as known to the author, not a hands-on teardown of each product in this session. Nothing here is copied; it decides what Dot.Design adopts, rejects and does differently.

| Product | Strength worth learning | Weakness / tension | Dot.Design decision |
|---|---|---|---|
| Canva | Template-first starting point; plain language ("Position", "Effects"); huge format picker; instant brand kit; magic resize | Everything is a template carousel; pro depth is shallow; brand kit paywalled; templates leak the same look | Template + brief first, but templates are structured with named slots; brand kit free and central; smart resize that re-lays-out |
| Adobe Express | Quick actions, strong "make a flyer from a brief" onboarding, good brand controls, animation presets | Mode confusion between quick actions and the editor | One document model, two views (Simple / Pro) with lossless switching |
| Illustrator | Precision: anchors, align/distribute, artboards, pathfinder, type controls, SVG fidelity | Intimidating; learning cliff | Precision tools live in Pro mode only; shapes + path element; alignment, distribution, guides, multi-page |
| Photoshop | Non-destructive effects/masks, layer model | Raster-first; heavy | Masks and filters are element properties (non-destructive), never baked into pixels |
| Figma | Fast multiplayer, constraints, components, inspector panel clarity, keyboard-first, smart guides with distances | Web-UI oriented, not print/marketing; steep for non-designers | Inspector pattern and smart guides with spacing hints; keyboard map; no multiplayer (honest limit) but CAS conflict handling |
| VistaCreate | Big format list, simple animated templates | Templates feel generic; limited brand depth | Animation presets per element with staggering; GIF/WebM export |
| Microsoft Designer | Describe-to-design with AI suggestions | AI output is hard to edit when it is image-like | AI returns structured, editable documents (proposals), never flattened images |
| Affinity Designer | One-time price, vector/raster hybrid, strong export persona, clean dark UI | Desktop-only | Export presets and per-page/slice style export dialog; honest browser-side PDF/PNG/JPG/SVG |

## Patterns adopted
Left rail for what to add, right inspector for the selection, top bar for document actions, a bottom page strip; contextual floating toolbar on the selection; smart guides with distances; zoom to cursor; spacebar pan; marquee select; one command history; plain-language labels in Simple mode ("Make the text bigger", "Move forward"); progressive disclosure instead of modes that hide data.

## Patterns rejected
Template carousels as the only entry; modal tutorials; dark-pattern upsells; flattened AI images; mode switches that convert the document; infinite panels of unlabeled icons.

## Distinctive choices for Dot.Design
1. **The brief**: a short, skippable, plain-language interview produces a structured brief that fills template slots; the same brief feeds the assistant.
2. **Slots**: templates are real documents whose business facts (name, offer, phone, logo) are bound slots, so the first draft already carries the user's information.
3. **Never silent**: brand updates, AI output and template swaps are proposals the user applies; each applies as one undo step after an automatic version snapshot.
4. **One renderer**: what you see is exactly what exports (PNG, JPG, SVG, PDF, GIF, WebM) because screen and export share a single SVG renderer and text layout.
5. **The Light Table**: a print-studio identity (crop and registration marks, swatch chips, a colour-true stage) instead of a generic SaaS dashboard.
