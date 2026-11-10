# SITE DocuDrive — Agent Design System

**Follow this file for UI work.** Behavior, authorization, and search live in `docs/SYSTEM_GUIDE.md`. Local install is `docs/SETUP.md`. Agent workflow is `docs/AGENTS.md`.

This file replaces the archived lime-era design notes. Do not follow `docs/archive/legacy/AGENTDESIGN.md`.

Source of visual truth is compiled CSS and Blade, not screenshots: `resources/css/app.css` plus existing feature CSS. If this document and the CSS disagree, record the discrepancy; do not silently “fix” the whole stylesheet unless that is the task.

Latest workflow UI reference: **`86cd234` (2026-10-21)**, including the profile teaching summary from `788be16` and archive actions from `77c2ad8`. These changes reuse the approved palette and shared components; they do not establish a new color theme. Production/browser verification is not implied by this reference.

## How this folder works


| File                   | Follow for                                     |
| ---------------------- | ---------------------------------------------- |
| `docs/AGENTDESIGN.md`  | Colors, chrome, components, what not to invent |
| `docs/SYSTEM_GUIDE.md` | Roles, documents, search, requests, operations |
| `docs/AGENTS.md`       | How to read the guide and change the app       |
| `docs/SETUP.md`        | Clone, migrate, build, test commands           |


Root `AGENTS.md` and `README.md` only point here so Cursor and GitHub still find an entry file.

## Design principles (current)

1. **Institutional dark green, not lime.** New UI uses `--site-`* tokens (`#0d5c3b` family). Do not add new `#028a0f` / `#02b815` lime, gradients, or glass chrome.
2. **Square controls.** Global `border-radius: 0`. Do not add `rounded-`* to “modernize” a screen.
3. **Restrained motion.** Global rules suppress transitions, hover transforms, and decorative shadows. Preserve a **visible keyboard focus** outline where the UI already does (dialogs, destructive confirms). Compact error dialog may use a slight shadow; do not copy that onto every card.
4. **Shared chrome, role-specific data.** Dean, Coordinator, Faculty, and Secretary share the same shell language. Do not invent a different visual system per role. Content, actions, and authorization still differ by role — see the system guide.
5. **Tokens first, then shared classes.** Prefer `var(--site-primary)` and existing `.btn`, `.content-card`, `.data-table`, `.ui-dialog`, `.profile-panel`. Avoid one-off hex and inline `style` attributes.
6. **Feature CSS is allowed where it already exists.** `resources/css/teacher-loads.css` is current. Do not treat “everything must be only in `app.css`” as a rule. Do not create a new CSS file for a small tweak if a shared class already covers it.
7. **Keep approved flows.** Guided upload destination selection, login branding layout, and Teacher’s Load workspace width are product constraints, not styling toys.

Known mix in `app.css`: `--site-primary` is `#0d5c3b`, but many older rules (including `.btn-primary`) still hard-code `#028a0f`. **Do not proliferate lime.** Prefer tokens on new or touched rules. Do not rewrite every leftover lime selector unless the user asked for a color migration.

## Color tokens

Defined in `resources/css/app.css`:


| Token                     | Value     | Use                         |
| ------------------------- | --------- | --------------------------- |
| `--site-primary`          | `#0d5c3b` | Primary actions and accents |
| `--site-primary-strong`   | `#08472e` | Strong / pressed            |
| `--site-primary-hover`    | `#0a5135` | Interactive                 |
| `--site-sidebar`          | `#0d4f32` | Sidebar                     |
| `--site-sidebar-strong`   | `#083d27` | Sidebar emphasis            |
| `--site-heading`          | `#0b4931` | Headings                    |
| `--site-text`             | `#16241d` | Body                        |
| `--site-muted`            | `#68766f` | Secondary text              |
| `--site-surface`          | `#ffffff` | Cards / panels              |
| `--site-surface-subtle`   | `#f7faf8` | Page workspace              |
| `--site-surface-selected` | `#edf7f1` | Selection                   |
| `--site-border`           | `#d9e2dd` | Dividers                    |
| `--site-border-strong`    | `#9fb8aa` | Stronger edges              |


Status colors (success / warning / danger / info) stay semantic. Do not recode errors as brand green.

Font stack: Segoe UI, Tahoma, Geneva, Verdana, system sans-serif.

## Shell and layout

Dean Analytics places a compact Monthly history section before the live metrics. The close action saves the previous calendar month as a snapshot; its confirmation explicitly says live figures are unchanged. Document rows expose Recycle Bin and Dean-only permanent deletion as separate destructive actions with clear confirmation.

- Layout: `resources/views/layouts/dashboard.blade.php`
- Role sidebars: `partials/dean-sidebar.blade.php` (Dean, **Secretary**, and **Admin**; `secretary-sidebar.blade.php` now just includes it). Faculty open the shared request modal with the title "Request a document from the Secretary", Secretary-only recipients, and only the General Document category. `coordinator-sidebar.blade.php`, `faculty-sidebar.blade.php`, `secretary-sidebar.blade.php`. Pages that pick a sidebar use `hasOversightAccess()` / `isDeanOrSecretary()` rather than `isDean()` so Admin gets the Dean chrome. The Password Resets badge counts only requests the viewer may handle.
- Account/photo: `partials/sidebar-account.blade.php`

Dark green grouped sidebar, account controls at the bottom, consistent page title and utilities. Workspace is pale green-gray, content on white. Use compact statistic strips and readable tables — not a card around every number.

Documents: breadcrumbs, folder choice, consolidated toolbar, file list. Search scopes must stay obvious.

Guided upload destination step (`partials/document-upload-dialog.blade.php`): view shortcuts are one joined `.upload-picker-tab` bar using `aria-pressed`; navigation is a separate row with a borderless `‹ Back` (shown only inside a folder) followed by text breadcrumbs separated by `›`, current location bold with `aria-current`. Do not mix Back into the breadcrumb trail or style crumbs as boxed buttons.

The shared document scope bar labels its starred-file link **Favorite documents**, with a star icon and count (including zero). Reuse the existing `browse=favorites` view rather than adding a duplicate sidebar item or separate favorites page. Its heading explains that favorites span folders and categories; existing access checks still apply.

Login: split identity / sign-in. School branding stays in the identity panel; “Employee portal” in the header. Do not restyle login unless the user asked.

Profile: `profile-panel` tabs (details / password), not the old uneven password grid. File: `resources/views/profile/edit.blade.php`.

The profile details pane includes a compact **Current teaching assignments** list for Faculty and teaching Coordinators. Reuse `partials/teaching-assignment-summary.blade.php` in Teacher's Load: subject code/title first, then published section and schedules when available. Unscheduled assignments show names only with one section-level explanation. Use token-based borders/text and wrapping rows; keep subjects read-only on the employee's own profile and management links on authorized manager views.

The shared Create Coordinator/Faculty form, Dean/Coordinator employee edit forms, and editable own profiles show separate First name, optional Middle name, and Last name fields. Keep explicit labels and required markers, preserve each field on validation failure, and leave the existing account access and subject picker layout intact. For older full-name-only accounts, show the existing name and explain that blank parts preserve it until both first and last are entered. The server composes the stored display name.

## Shared components

Use these instead of inventing parallel widgets.

**Buttons**

```html
<button type="button" class="btn btn-primary">Save</button>
<button type="button" class="btn btn-secondary">Cancel</button>
<button type="button" class="btn btn-danger">Delete</button>
```

`.btn` has no border. Variants include `btn-primary`, `btn-secondary`, `btn-success`, `btn-warning`, `btn-danger`. Document row actions use `btn-action-view` / `btn-action-download` where those classes already wrap the control.

**Cards and tables**

```html
<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Title</h3>
    </div>
    <table class="data-table">…</table>
</div>
```

**Badges:** `badge` plus `badge-success` | `badge-warning` | `badge-danger` | `badge-info`.

**Forms:** labels aligned with controls, validation next to the field, preserve `old()` values on recoverable failure. Distinguish read-only account facts from editable inputs.

**Modals:** `dialog.ui-dialog` with `ui-dialog__head`, `ui-dialog__body`, `ui-dialog__foot`, visible close. Bounded scroll, restrained backdrop. Teacher’s Load keeps its wider workspace.

**Compact errors:** `partials/ui-error-dialog.blade.php` + `resources/js/site-request.js`. Max width ~420px, ~1.05rem title, ~.875rem body, two actions. Do not use that width for upload, Teacher’s Load, or other complex forms.

**Empty states:** prefer `partials/ui/empty-state.blade.php` when the screen already uses it.

## Documents, upload, Teacher’s Load

- Course Catalog switches between **All Courses** and **Sections** through the shared `catalog-tabs` navigation. Section management reuses content cards, tables, program/status filters, and native `ui-dialog` edit/remove/restore confirmations; retain validation values and concurrency warnings. Keep historical-load preservation text visible and use Remove/Restore instead of permanent delete.
- Teacher's Load uses a required section dropdown (active choices for the selected employee's program) with labeled saved/legacy options only on existing rows. The **Drafts (count)** link sits beside Create Teacher's Load, retains filters, and includes revision drafts. Only managers see it; use the same editor and green/white/charcoal tokens, not a second visual system.
- Dean Analytics places **Reset this month's analytics** beside the previous-month close action in the Monthly history header. The monthly counters and reset history sit inside that card; the separate live overview remains labeled as unchanged. Dean Activity Log places **Reset Activity Log** in the card header and provides **View earlier records** after a reset. Confirmations explain that source records remain. Disable buttons with a migration-pending note if the reset table is absent.
- Admin screens reuse Dean pages; Dean-only controls (Reset Activity Log, analytics close/reset, Archive/Restore school year, permanent delete, Create Coordinator) are hidden for Admin and the endpoints also refuse. Employees adds a Dean-only **Create Admin** tab using the existing `account-form` markup (name parts, username, temporary password; no program/subjects). Employee profiles add **Generate temporary password** in Account actions with a confirmation and a one-time warning notice; the Password Resets confirm dialog shows a required reason field only for Dean-account requests. **Rejected Files** (`dean/rejected-files.blade.php`) is a read-only `content-card` + `audit-filter-panel` + `data-table` list. Forgot Password copy says "Dean or Admin"; the layout is unchanged.
- Dean dashboard omits the duplicate Needs attention notification preview. The shared bell dropdown has **Clear notifications** beside Mark all read when items exist; confirm before deleting only that account's notices, update the list and badge, and show a retry message on failure. Dean Activity pulse follows the Activity Log reset boundary and shows its empty state until new regular actions occur.

- Explorer / tree: `partials/folder-tree.blade.php`, `folder-explorer.blade.php`, `documents-filter-panel.blade.php`
- Guided upload: `partials/document-upload-dialog.blade.php` — keep destination-then-files.
- Archive browsing: `resources/views/archives/show.blade.php` reuses the guided upload dialog with archive-specific destinations. Keep its folder toolbar, inline rename controls, and "Added later" badges within the existing square, token-based visual language.
- Do not restyle `partials/folder-tree-upload-form.blade.php` unless the user named that file.
- Teacher’s Load: `resources/css/teacher-loads.css`, `resources/js/teacher-loads.js`, `resources/views/teacher-loads/`
- Correction actions use native `dialog.ui-dialog`: Teacher's Load revise/void/discard/delete confirmations, request-management dialogs, and Course Catalog edits. Require a clear reason for lifecycle changes; keep destructive confirmation buttons semantic. Published history is shown in compact tables. Do not place a complex management form inside a document upload modal.

### Current correction screens

| Screen | Current UI and behavior to preserve | Source |
|---|---|---|
| Profile teaching summary | Subject code/title first; section and published schedule only when available. Existing unscheduled assignments remain visible without fabricated timetable fields. Unpublished revisions are hidden. Voiding removes the published schedule, not the account's subject assignments. | `resources/views/partials/teaching-assignment-summary.blade.php` |
| Teacher's Load | Managers see Edit draft, Revise/Edit revision, Discard revision, Void/Prepare replacement, Delete draft, and History when allowed by status/year. Native compact confirmations collect reasons; the existing wide editor handles actual load rows. Faculty see published/void records and history, never revision drafts. | `resources/views/teacher-loads/index.blade.php`, `history.blade.php`, `resources/js/teacher-loads.js` |
| Document request management | Sent requests link to a separate management page. Native dialogs edit details/deadlines, add/reinstate recipients, exempt, cancel, or reopen. Show parent request status separately from recipient review status; retain submissions and show readable change history. Field values survive validation errors. | `resources/views/document-requests/manage.blade.php` |
| Course Catalog | Edit course uses native `dialog.ui-dialog` for code/title, year level, semester, and lecture/lab units. Keep labels associated with controls, close/Cancel visible, and failed values recoverable. Change history is a separate table; inactive courses use Restore rather than permanent deletion. | `resources/views/partials/course-catalog.blade.php`, `course-metadata-fields.blade.php`, `resources/views/courses/history.blade.php` |

Reasons are required for load lifecycle/request-management actions. Course metadata edits record before/after values without a required reason field. Do not label every history entry as an approval. A saved load revision is still unpublished until finalization; its presence must not replace the published profile/PDF.

Teacher's Load validation stays inside the editor's bounded alert. List server field errors with step, subject/duty row, and schedule context; each entry opens that step and focuses its control. Finalize checks earlier steps and missing schedules without deleting entered data. Distinguish server/session failures from incomplete fields; never expose raw server exception details as field guidance.

Request creation's compact recipient checklist refreshes on open and via **Refresh recipients**. Keep the form and eligible selections intact, show loading/matching-count/retry text in a polite status region, reset list scroll when searching, and preserve the existing destination/upload flow. Search is labeled and includes name/username/program; no duplicate recipient-management modal is needed.

Keep selected names/count visible above the checklist. **Done selecting** collapses only the picker (not the form); **Change recipients** reopens it with appropriate expanded state and focus. Deadline guidance names the timezone and optional/no-deadline behavior; show field-level errors and an in-modal summary on failed creation, preserving inputs instead of leaving only a page-level red banner.



## Typography and spacing

Use the existing Tailwind scale already in the screens: `text-xs` / `text-sm` / `text-base` / `text-lg`; `font-normal` / `font-medium` / `font-semibold`. Typical padding `p-3`–`p-6`, gaps `gap-2`–`gap-4`. Match the neighboring page instead of introducing a new scale.

## Dark mode

Dark mode is a **neutral charcoal workspace** with the same green sidebar as light. It is not a green-tinted copy of the light palette.

`html[data-theme="dark"]` / `.dark` remaps `--site-*` surfaces, text, and borders. Light `:root` / `@theme` values stay unchanged.

| Token | Dark value | Role |
|---|---|---|
| `--site-sidebar` / `--site-sidebar-strong` | `#083d27` / `#062f1e` | Brand chrome only |
| `--site-surface-subtle` | `#121416` | Page canvas |
| `--site-surface` | `#1c1f24` | Cards, top bar, dialogs |
| `--site-surface-selected` | `#24352c` | Selection |
| `--site-text` / `--site-heading` | `#e8eaed` / `#f3f5f6` | Neutral type |
| `--site-muted` / `--site-border` | `#9aa3ab` / `#2c3036` | Secondary / edges |
| `--site-primary` | `#3d9b6e` | Buttons, active, charts, focus |

Do not paint the workspace or card fills with brand green. Do not add gradients or glass. If a panel is still white or mint in dark, add a dark token override rather than a new palette.

## Key files


| File                                                        | Role                             |
| ----------------------------------------------------------- | -------------------------------- |
| `resources/css/app.css`                                     | Tokens, shell, shared components |
| `resources/css/teacher-loads.css`                           | Teacher’s Load only              |
| `resources/views/layouts/dashboard.blade.php`               | Authenticated chrome             |
| `resources/views/auth/login.blade.php`                      | Login branding split             |
| `resources/views/profile/edit.blade.php`                    | Profile panel                    |
| `resources/views/partials/document-upload-dialog.blade.php` | Guided upload                    |
| `resources/views/partials/ui-error-dialog.blade.php`        | Compact errors                   |
| `resources/js/site-request.js`                              | Shared fetch / error mapping     |
| `resources/views/partials/teaching-assignment-summary.blade.php` | Shared profile/load subject list |
| `resources/views/document-requests/manage.blade.php`        | Request management and change history |
| `resources/views/partials/course-catalog.blade.php`          | Course actions and edit dialog |
| `resources/views/teacher-loads/history.blade.php`            | Saved published load revisions |


After CSS or Blade visual changes: `npm run build`, then hard-refresh (Ctrl+F5).

## Do not

- Follow the archived lime palette or “global CSS only / no feature CSS / no shadows ever” rules.
- Add rounded corners, hover lifts, or animation for decoration.
- Copy CDN Font Awesome / Chart.js / FullCalendar; they are already local packages.
- Infer permission from sidebar styling.
- Change Coordinator extra-program or private-folder behavior as a “UI cleanup”.
- Treat leftover `#028a0f` in `app.css` as the target color for new work.
- Paint dark-mode page/card fills with brand green. Dark surfaces stay charcoal; green is accent and sidebar only.



## Checklist (UI change)

- [ ] Tokens or existing shared classes, not a new hex
- [ ] Square corners, no decorative motion
- [ ] Same chrome language as sibling screens
- [ ] Role differences are data/actions, not a new theme
- [ ] Upload / login / Teacher’s Load constraints untouched unless requested
- [ ] `npm run build` after CSS/Blade asset changes
- [ ] Update this file and the system-guide UI section if you change tokens or shared patterns
