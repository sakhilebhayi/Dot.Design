# R-45 privacy record (POPIA)

Owner: the security agent (wave 3). Branch `feat/r45-security`. Companion: `docs/R45_SECURITY.md`.

**This is a technical record of what the software does, written for the owner's review and for the Information Officer. It is not legal advice and it does not replace the published Privacy Policy (`resources/markdown/policy.md`), which is the owner's text and was not rewritten. Where the software does something the policy does not say, section 10 lists it.**

Everything below was read from the code and the database schema, and the behaviours that matter were tested (`tests/Feature/Security/AccountErasureTest`, `PrivacyRightsAndRetentionTest`, `SecurityEventLogTest`, `PublicShareAbuseTest`).

## 1. Data inventory

"Subject" means whose information it is. "Business" content is what a workspace's members create and may contain other people's information (customer names, prices, phone numbers on a poster): the business is the responsible party for what it puts in a design; Dot.Design only stores it.

| Information | Where | Subject | Purpose | Kept |
|---|---|---|---|---|
| Name, email, password hash, two-factor secret and recovery codes (encrypted by Laravel), email verified time, current workspace | `users` | The person | Account and sign-in. Accounts are created by InfoDot sign-in; the password is a random one unless the person sets their own | Until the account is deleted (section 5) |
| Workspace names, who owns it, who is a member and in what role | `teams`, `team_user` | The person, the business | Collaboration and permissions | Until the workspace or the member is removed |
| Email address of a person invited to a workspace | `team_invitations` | The invitee | Sending and accepting the invitation | Until accepted, cancelled, the workspace is deleted, or the invitee's account is deleted |
| Browser session: **IP address, browser agent string**, user id, last activity | `sessions` | The person | Keeping them signed in; "your other browser sessions" on the profile page | Until the session expires (2 hours without activity; Laravel's purge runs on 2% of requests), at sign-out, or at account deletion |
| Sign-in throttle counters (email plus address, for one minute; per address and per account, for one minute and one hour) | cache | The person | Stopping password guessing | At most one hour. Counters added in R-45 use a SHA-256 of the address or email; Fortify's own per-email-and-address counter stores the pair in clear for one minute |
| Link-probing counter | cache | A link visitor | Stopping link guessing | Ten minutes; a SHA-256 of the address only |
| Designs: name, size, pages, every element and the text in it, status, visibility, brief answers, brand link | `design_projects`, `design_canvases` | Business content | The product | Until the business deletes the design |
| Version snapshots of a design (full pages), with who took them | `design_versions` | Business content | History and restore | Newest 50 automatic ones per design; manual, restore and approved ones until the design is deleted |
| Pictures (re-encoded files on a private disk; EXIF and GPS removed), name, folder, tags | `design_assets`, `design_asset_folders` and files | The person who uploaded, and anyone in the picture | The product | Until deleted by the person, or by the account-deletion rules |
| Thumbnails of designs | private disk | Business content | Showing designs in lists | With the design |
| Comments (body, author, pinned position, mentions) | `design_comments` | The author | Collaboration | With the design; the author's name becomes "Former member" when the account is deleted |
| **Guest comments**: the display name a link visitor typed and the comment. No address, no agent, no cookie | `design_comments` (`guest_name`) | The visitor | Feedback on a shared design | With the design |
| Reviews: who asked, who reviewed, notes, decision | `design_reviews` | The requester and the reviewer | Approval workflow | With the design |
| Share links: a SHA-256 of the token, permission, expiry, revoked time, who made it, a view count and the last view time | `design_shares` | The person who made it | Sharing | Until the design is deleted; a link that expired or was turned off is removed 90 days later |
| Access grants | `design_project_grants` | Both people | Private designs opened to one member | Until removed or either account is deleted |
| Audit trail: who shared, unshared, exported, requested or decided a review, restored, deleted, changed visibility, applied a brand, published a template; the design and workspace; short metadata. No addresses | `design_activity` (append-only) | The actor | Accountability for the workspace's administrators | 730 days (`design.retention.activity_days`); the actor's id is removed at account deletion |
| Export log: format, pages, scale, size, who, when | `design_exports` | The person | Analytics counts and the audit | 365 days |
| Brand kits: colours, fonts, voice, tagline, website, **contact details** (phone, WhatsApp, email, address, social handle), guidelines | `brand_kits` | The business | Applying a brand | Until the business deletes the kit |
| Templates the business saved | `design_templates` | Business content | Reuse | Until deleted |
| Assistant requests: what was typed, the status, tokens used, the model | `design_ai_proposals` | The person | Showing and auditing what the assistant proposed, daily caps | 90 days; applied "compose" rows 180 days (`design_ai.retention_days`, `design:prune-ai-proposals`) |
| Image-request prompts (v1 mock generator; nothing in the product links to it) | `ai_generation_logs` | The person | History | 90 days (`design.retention.ai_image_logs_days`) |
| Studio preferences | `design_user_settings` | The person | The editor remembers them | Until account deletion |
| Filed exports | `design_file_links` here; the file itself is in Dot.Files | The person, the business | "Save to Dot.Files" | The link with the design; the file stays in the business's Dot.Files (its record, not this app's) |
| Daily counts of designs and pictures | sent to Dot.Analytics | None (aggregate) | Platform statistics | n/a |
| Security log lines (`security.login`, `login_failed`, `lockout`, `password_reset`, `password_changed`, `two_factor_enabled`, `two_factor_disabled`, `recovery_codes_regenerated`, `account_deleted`, plus `csp.violation`) | application log | The person | Incident response | Log rotation: 14 days with `LOG_STACK=daily` (set in `.env.example`; the host's `.env` must match) |

**IP addresses, plainly:** Dot.Design does not store a visitor's IP address in any table except `sessions.ip_address` (with the browser agent), for signed-in sessions only. Share-link visitors, guest commenters and people who probe links are never recorded: limiters use a hash for minutes, share and comment rows have no such column (a test lists every column of those tables), and the audit trail holds none. The security log lines carry a keyed hash of the address (HMAC with the app key), so one address's activity can be correlated without the address being written down. The web server's own access log (outside this app) will hold addresses; that is the host's setting.

## 2. Lawful basis notes and purpose limitation

Technical notes for the Information Officer to confirm; the app does not decide a lawful basis.

- **Account and workspace data**: necessary to provide the service the person or their business asked for (contract). Created through InfoDot sign-in; the identity (name, email, verified flag, workspaces and roles) comes from InfoDot.
- **Business content, comments, reviews, brand kits**: processed on behalf of the workspace. A business that puts customers' details on a poster is the responsible party for them; Dot.Design should be described to it as an operator.
- **Share links and guest comments**: a link holder is anonymous by design. The comment form tells them their name and comment are visible to the people who made the design, and asks only for a name. Nothing else is collected.
- **Audit trail, security log, rate limits, sessions**: legitimate interest in security and accountability; kept short and without addresses where possible.
- **The assistant**: optional and off without a key. A person has to open it and press a button for anything to be sent. Consent is therefore per use, but the policy should say so (section 10).
- **Purpose limitation in code**: the assistant gets only the fields it needs (section 4); brand contacts and website are not sent; Analytics gets two counts; Notify gets the minimum for an email (section 4); personal data is not used for anything except the feature that collected it (no advertising, no profiling, no third-party trackers, no CDN: the app loads nothing from another host, asserted by `ContentSecurityPolicyPagesTest`).

## 3. Retention as actually implemented

| What | Rule | Enforced by |
|---|---|---|
| Version snapshots | newest 50 automatic per design | `VersionService` on every snapshot |
| Assistant proposals | 90 days; applied compose rows 180 | `design:prune-ai-proposals`, daily 03:30 |
| Audit trail | 730 days | `design:prune-expired`, daily 03:45 (new) |
| Export log | 365 days | same |
| Dead share links (expired or turned off) | removed 90 days after; guest comments stay with the design | same |
| Image-request prompts | 90 days | same |
| Sessions | 2 hours idle | Laravel session lottery |
| Rate-limit counters | minutes to one day | cache expiry |
| Security log | 14 days | `LOG_STACK=daily`, `LOG_DAILY_DAYS=14` (host `.env`) |
| Designs, comments, reviews, kits, templates, pictures | until the business deletes them, or the account-deletion rules apply | `AccountErasure` |
| Crash-recovery copy of an unsaved page (in the browser) | removed when saved; 30 days at most | `recovery.js` |
| Backups | not covered: a host matter. A deleted record leaves a backup only when the backup rotates | not verified in this review |

Periods are config (`config/design.php` `retention`, environment variables `DESIGN_RETENTION_*`). The command refuses periods below a floor (30 days for the audit trail and exports, 7 for links and image logs) so a typo cannot erase everything; `--dry-run` counts without deleting.

## 4. What leaves this app, to whom

| Processor | When | What is sent | Where it is switched off |
|---|---|---|---|
| **Anthropic** (the assistant; optional) | Only when a person presses compose, improve or copy and `ANTHROPIC_API_KEY` is set | **Compose:** the person's description (up to 1,500 characters), answers to follow-up questions, a summary of the chosen brand kit (colour roles and hex values, font names, tagline, voice words, whether a logo exists), a catalogue of the template library and formats (no personal data). **Improve:** the page's size and background, up to 80 elements with their type, position and up to 240 characters of each one's text, the instruction (up to 500 characters), the brand summary. **Copy:** the brief fields the person typed (business name, product, audience, offer, details, tone). Fixed instructions travel with each call. **Not sent:** names, emails, addresses of the person or the business's members, brand contacts and website, pictures or any file, the design's name, workspace names, IP addresses, cookies. A person's own text may of course contain personal information if they typed it | Empty `ANTHROPIC_API_KEY`, or `DESIGN_AI_ENABLED=false`: every model endpoint answers "unavailable" and nothing is sent. Quick fixes and size suggestions run in code and never leave the app |
| **Dot.Notify** (email notifications; optional) | A comment, a review request or decision on a design, when `DESIGN_INTEGRATION_NOTIFY` is on | The recipient's email, the design's name, the actor's name, a link, an event name, signed with an HMAC | `DESIGN_INTEGRATION_NOTIFY=false` (the default). Recipients are workspace members only, re-checked when the job runs |
| **Dot.Files** (optional) | A person chooses "Save to Dot.Files" and ticks the acknowledgement that everyone in the business will see the file | The exported file, its name, the workspace's InfoDot company id | `DESIGN_INTEGRATION_FILES=false` (the default) |
| **Dot.Analytics** | Daily | Two counts (designs, pictures) and the company id. No personal data | Unset URL or secret |
| **InfoDot** (sign-in, membership) | At sign-in; and InfoDot calls us when a membership changes | In: name, email, verified flag, workspaces and roles. Out: the single-use sign-in token to be redeemed | `INFODOT_*` settings |
| **Mail transport** (invitations, password reset) | When a workspace admin invites someone, or a person resets a password | The recipient's address, the workspace name and inviter for an invitation | The host's `MAIL_*` settings (`log` in the example file) |
| **Hosting provider** | Always | Everything above, on a shared cPanel host | Not in scope; its location and agreement were not verified |

No font, script, style, image or analytics is loaded from any third-party host; the Content-Security-Policy would block it (S-5).

**Cross-border transfer statement (technical).** Calls to the assistant go from this server to Anthropic's API (`https://api.anthropic.com`), a provider outside South Africa (the provider's processing locations were not verified here). POPIA section 72 conditions for transferring personal information outside the Republic (a recipient bound by comparable protections, the person's consent, or necessity) need to be confirmed by the owner for this processor, and the policy should name the transfer. Because the assistant is optional and the text sent is whatever the person types into it, one safe approach is to say so next to the assistant's input; the code does not currently show such a notice.

## 5. Data subject rights

| Right | What exists |
|---|---|
| Access | **Self-service:** Studio settings, "download a copy of everything Dot.Design holds about you" (`GET design/settings/my-data`, one JSON file: account, workspaces, settings, their designs with documents, pictures (metadata), comments, reviews, share links they made (no tokens exist to give), grants, audit entries about them, exports, assistant requests, brand kits and templates they made, folders). Never secrets: no password hash, two-factor secret or recovery codes. **By request:** `php artisan design:export-user-data {email} [--file=...] [--no-documents]` (file written with 0600). Tested: contains the person's data and nothing of another business or colleague |
| Correction | Name and email on the profile page (and at InfoDot, the source). Designs, comments (own) and kits are editable by their makers |
| Deletion | Profile and security, "Delete account" (Jetstream). Rules in `AccountErasure`, tested across every table and file: **refused** while the person owns a workspace that still has other members (nobody loses designs by accident; they remove the members or delete the workspace first); **transferred** to the workspace owner when the workspace continues: designs shared with the workspace, the pictures those designs use, workspace brand assets, brand kits and team templates; **deleted with their files**: private designs, personal library pictures and thumbnails, personal templates, assistant requests and logs, settings, folders, grants, sessions, invitations addressed to them; **anonymised**: their comments stay in the thread as "Former member", audit rows lose their id, reviews waiting on them are cancelled; **revoked**: share links they made. Files are deleted only after the database commit. A workspace they own alone is deleted with everything in it |
| Objection, withdrawal of consent | The assistant and the two integrations are opt-in per use or per deployment. No marketing or profiling exists to object to. Other processing (sessions, audit) has no switch; the Information Officer handles such requests |
| Portability | The JSON download |
| Restriction | Not built |
| Complaint | Policy section 9 (Information Regulator) |

What the software cannot do: take back what a business has already filed in Dot.Files, or a design another member copied; delete a person from a backup; tell a person who has viewed a share link (by design, visits are counted but not attributed).

## 6. Cookie and browser-storage inventory against the cookie policy

| Item | Set by | Policy page | Match |
|---|---|---|---|
| `dotdesign-session` (HttpOnly, SameSite Lax, host-only, Secure under https) | Laravel session | Said `dot-design-session` | **Mismatch fixed** (S-15) |
| `XSRF-TOKEN` (SameSite Lax) | Laravel | Listed | yes |
| `remember_web_*` (only with "Remember me") | Fortify | Listed (5 years) | yes |
| localStorage: theme, list or grid view, last panel, measurement unit, saved colours, studio view | the editor and design system | Not mentioned | **Added** to the policy as "Settings kept in your browser" |
| localStorage `dotdesign:studio:journal:*` crash-recovery copy of an unsaved page (up to 30 days) | the editor | Not mentioned | **Added**, with a note for shared computers |
| sessionStorage: a one-shot message after saving a brand kit, the brief answers in progress | the pages | Not mentioned | Same section (functional, cleared with the tab) |
| Share-link viewers: **no cookie at all** (asserted and checked in a browser); localStorage theme only | | Not mentioned | Added |
| Third-party cookies | none | "Does not use" | yes (no third-party host is loaded) |

The policy page's "last updated" date was moved to 9 November 2026 because of this correction. The wording is the owner's to approve.

## 7. Breach response: what is logged, what can be answered

Sources an investigator has, today:

| Question | Source |
|---|---|
| Who signed in, from which "network" (keyed hash), when; failed attempts and whether the account exists; lockouts; password and second-factor changes; account deletions | `security.*` log lines |
| A browser blocked something the page tried to load or run | `csp.violation` (directive and blocked host only) |
| Someone forging requests, replaying a webhook, probing links | 419/429/401 responses; "Membership webhook refused" warnings (reason only) |
| Who created or turned off a share link, exported, requested or decided a review, restored a version, deleted a design, changed visibility, applied a brand, published a template, when | `design_activity` (730 days) |
| How many times a link was opened and when last | `design_shares.view_count`, `last_viewed_at` (not who) |
| Which designs a person made or touched | `design:export-user-data` |

Containment steps the software supports: revoke a share link (owner action, immediate); a removed member loses access on their next request; rotate `APP_KEY` (signs everyone out; share links are unaffected because they are hashed independently; signed picture URLs stop working); rotate `INFODOT_SERVICE_SECRET`, `NOTIFY_INGEST_SECRET`, `FILES_PLATFORM_SECRET`, `ANTHROPIC_API_KEY`; set `SECURITY_CSP_REPORT_ONLY=false` and review reports; delete rows in `sessions` to sign everyone out. Notification duties under POPIA section 22 (the Information Regulator and the people affected) are decided by the Information Officer; this record only says what the system can show.

Gaps worth knowing: no per-visitor record exists for share links (a deliberate privacy choice that also means "who saw this leaked link" cannot be answered); the security log lines are as durable as the log files (14 days); there is no bulk "revoke every link" command.

## 8. Account deletion across the new tables (checked)

| Table | On deletion of the person |
|---|---|
| `design_projects` | their private designs deleted; team-visible designs in a continuing workspace transferred to its owner |
| `design_canvases`, `design_versions`, `design_comments` (others'), `design_reviews`, `design_shares`, `design_exports`, `design_brand_updates`, `design_file_links` | go with a deleted design; with a transferred design they stay |
| `design_assets` and files | transferred if a continuing workspace's designs, brand assets or kits use them; otherwise row and file deleted |
| design thumbnails | deleted with the design (file after commit) |
| `design_comments` they wrote | stay, `user_id` blank, name "Former member" |
| `design_reviews` | requested-by and reviewer ids blank; a pending review on them is cancelled |
| `design_shares` they made | revoked |
| `design_activity` | `user_id` blank (rows are kept: the audit of what happened) |
| `design_project_grants`, `design_user_settings`, `design_asset_folders`, `design_ai_proposals`, `ai_generation_logs`, `sessions`, `team_invitations` to their address, `password_reset_tokens` | deleted |
| `brand_kits`, `brand_assets` | kits of a continuing workspace transferred; personal kits deleted with the person |
| `design_templates` | team templates transferred; personal ones deleted |
| Their browser's localStorage | not reachable from the server; the cookie policy tells people to clear it on shared computers |

## 9. Commands, routes and settings added

| Item | Purpose |
|---|---|
| `php artisan design:prune-expired [--dry-run]` | Retention (scheduled daily 03:45) |
| `php artisan design:export-user-data {email} [--file=] [--no-documents]` | Access request |
| `GET design/settings/my-data` (`design.settings.my-data`, signed in, throttled) | Self-service download |
| `config/design.php` `retention.*`, env `DESIGN_RETENTION_ACTIVITY_DAYS`, `_EXPORTS_DAYS`, `_DEAD_SHARES_DAYS`, `_AI_IMAGE_LOGS_DAYS` | Periods |
| `config/security.php` `login_per_ip_per_minute`, `login_per_account_per_hour` | Sign-in throttles |
| `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=info` (host `.env`) | Log rotation and the level the `security.*` lines need |

## 10. Things the owner should decide (the policy as published does not say them)

1. Name the AI provider and the cross-border transfer (section 4), and say the assistant is optional and what is sent.
2. Say that share links let anonymous people view a design and leave a name and a comment, and that nothing else is collected from them.
3. Say that comments and designs are shared with a workspace, that a person leaving hands shared work to the workspace owner, and describe the deletion rules (section 5) and the owner refusal.
4. Say the retention periods (section 3) instead of "a reasonable period", and what happens to backups.
5. Name Dot.Notify, Dot.Files, Dot.Analytics and InfoDot as places information goes (each optional or aggregate as described).
6. Mention the self-service download and the Information Officer address for requests the screens do not cover.
7. Confirm the hosting location and agreement, the mail transport, and that the host `.env` has `LOG_STACK=daily` and `LOG_LEVEL=info`.
8. Decide whether `SESSION_ENCRYPT=true` is wanted (sessions hold an address and agent string and a password check value).
