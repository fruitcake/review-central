<style>
    /* The colours: the default teal, or the open tenant's own over them. See App\Filament\Themes. */
    :root {
@foreach ($colours as $name => $colour)
        --rc-{{ $name }}: {{ $colour }};
@endforeach
        /* The admin panel's sidebar is darker, to tell it from a tenant. */
@if ($admin)
        --rc-sidebar: {{ $colours['sidebar-admin'] }};
@endif
        /* Filament's own shades follow, for the text, rings and switches it colours itself. */
        --primary-50: var(--rc-accent-tint); --primary-400: var(--rc-accent); --primary-500: var(--rc-accent-text);
        --primary-600: var(--rc-accent-text); --primary-700: var(--rc-accent-text);
        /* The reader's own interface font: nothing to load, and at home on every system. */
        --rc-font: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        --rc-display: var(--rc-font);
        --font-family: var(--rc-font);
    }
    .fi-body { font-family: var(--rc-font); }

    /* Ground and surfaces: white cards on a light ground, square, flat and bordered. */
    .fi-body, .fi-layout { background: var(--rc-ground); color: var(--rc-text); }
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-modal-window, .fi-dropdown-panel {
        border-radius: 0; box-shadow: none; border: 1px solid var(--rc-border); --tw-ring-shadow: 0 0 #0000; --tw-shadow: 0 0 #0000;
    }
    .fi-ta-ctn { overflow: hidden; }
    .fi-dropdown-panel { border-radius: 0; }
    .fi-btn, .fi-input-wrp, .fi-select-input, .fi-badge, .fi-icon-btn, .fi-tabs, .fi-tabs-item { border-radius: 0; }

    /* The main button, in the theme's own fill and text: a light accent carries the ink, not white. */
    .fi-btn.fi-color-primary:not(.fi-outlined):not(.fi-link) { background: var(--rc-button); color: var(--rc-button-text); }
    .fi-btn.fi-color-primary:not(.fi-outlined):not(.fi-link):hover { background: var(--rc-button-hover); color: var(--rc-button-text); }
    .fi-btn.fi-color-primary:not(.fi-outlined):not(.fi-link) > .fi-icon { color: var(--rc-button-text); }
    /* A button without a colour of its own is white, with a border. */
    .fi-btn:not(.fi-color):not(.fi-link) { border: 1px solid var(--rc-border); }

    /* A link is in the ink, underlined in the accent. */
    .fi-main a.fi-link { color: var(--rc-ink); }
    .fi-main a.fi-link, .rc-stack a { text-decoration: underline; text-decoration-color: var(--rc-accent); text-decoration-thickness: 2px; text-underline-offset: 3px; }
    .fi-btn { box-shadow: none; font-weight: 500; }
    .fi-input-wrp { box-shadow: none; border: 1px solid var(--rc-border); --tw-ring-shadow: 0 0 #0000; }
    .fi-badge { border-radius: 100px; --tw-ring-shadow: 0 0 #0000; }

    /* Headings. */
    .fi-header-heading { font-family: var(--rc-display); font-size: 2.5rem; line-height: 1.1; letter-spacing: -0.02em; font-weight: 600; color: var(--rc-ink); }
    .fi-section-header-heading, .fi-modal-heading { color: var(--rc-ink); font-weight: 700; }
    /* The row, not its cells: the cell above the row actions is not a header cell. */
    .fi-ta-table > thead > tr { background: var(--rc-ground); }
    .fi-ta-header-cell { color: var(--rc-muted); }
    .fi-ta-header-cell-label { color: var(--rc-muted); font-weight: 600; }

    /* The sidebar is the one dark surface: darker in the admin panel, to tell it from a tenant. */
    .fi-sidebar, .fi-sidebar-header, .fi-sidebar-footer { background: var(--rc-sidebar); color: #fff; box-shadow: none; --tw-ring-shadow: 0 0 #0000; border: 0; }
    .fi-sidebar-nav { padding: 1.25rem 1rem; gap: 1.5rem; }
    .fi-sidebar-header { padding: 1.25rem 1.5rem 0.25rem; height: auto; }
    .fi-sidebar-item-btn { border-radius: 0; padding: 0.625rem 0.75rem; gap: 0.75rem; }
    .fi-sidebar-item-btn, .fi-sidebar-item-btn .fi-sidebar-item-label, .fi-sidebar-item-btn .fi-icon,
    .fi-sidebar-item-btn .fi-sidebar-item-icon { color: #fff; }
    .fi-sidebar-item-label { font-weight: 400; }
    .fi-sidebar-item-btn:hover, .fi-sidebar-item-btn:focus-visible { background: var(--rc-ink-alt); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background: var(--rc-accent); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn, .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-sidebar-item-label,
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn .fi-icon { color: var(--rc-on-accent); font-weight: 600; }
    .fi-sidebar-group-label { color: rgba(255, 255, 255, 0.7); font-size: 0.75rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
    .fi-sidebar-group-btn .fi-icon, .fi-sidebar-group-collapse-btn { color: rgba(255, 255, 255, 0.7); }
    .fi-sidebar .fi-badge { background: rgba(255, 255, 255, 0.14); color: #fff; }
    .fi-sidebar-item.fi-active .fi-badge { background: rgba(255, 255, 255, 0.2); color: var(--rc-on-accent); }

    /* Tenant and user menus sit in the band. */
    .fi-sidebar .fi-tenant-menu-trigger, .fi-sidebar .fi-user-menu-trigger {
        background: var(--rc-ink-alt); border: 1px solid rgba(255, 255, 255, 0.18); border-radius: 0; color: #fff; box-shadow: none; --tw-ring-shadow: 0 0 #0000;
    }
    .fi-sidebar .fi-user-menu-trigger { background: transparent; border: 0; }
    .fi-sidebar .fi-tenant-menu-trigger *, .fi-sidebar .fi-user-menu-trigger * { color: #fff; }
    .fi-sidebar .fi-tenant-menu-trigger .fi-tenant-menu-trigger-tenant-name ~ *, .fi-sidebar .fi-tenant-menu-trigger-current-tenant-label { color: rgba(255, 255, 255, 0.75); }
    .fi-sidebar .fi-avatar { background: var(--rc-accent); color: var(--rc-on-accent); border-radius: 0; }
    .fi-sidebar-footer { border-top: 1px solid rgba(255, 255, 255, 0.14); }
    .fi-sidebar-footer .fi-avatar { border-radius: 100px; }

    /* The brand mark. */
    .rc-brand { display: flex; align-items: center; gap: 10px; color: #fff; }
    .rc-brand-mark { width: 32px; height: 32px; border-radius: 0; background: var(--rc-accent); color: var(--rc-on-accent); display: flex; align-items: center; justify-content: center; flex: 0 0 32px; }
    /* The arrows turn slowly around the check, which stands still; not for whoever asked for less motion. */
    @keyframes rc-spin { to { transform: rotate(360deg); } }
    .rc-spin { transform-origin: 12px 12px; transform-box: view-box; animation: rc-spin 6s linear infinite; }
    @media (prefers-reduced-motion: reduce) { .rc-spin { animation: none; } }
    .rc-brand-logo { height: 32px; width: auto; max-width: 96px; object-fit: contain; flex: 0 0 auto; }
    /* Who made it, at the foot of the sidebar. */
    .rc-credit { display: flex; flex-direction: column; gap: 8px; margin: 0 0 8px; padding: 14px 12px; background: var(--rc-ink-alt); color: #fff; text-decoration: none; }
    .rc-credit span { font-size: 12px; color: #cbd5e1; }
    .rc-credit svg { display: block; }
    .rc-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .rc-brand-name { font-family: var(--rc-display); font-weight: 600; font-size: 18px; letter-spacing: -0.02em; }
    .rc-brand-sub { font-size: 12px; font-weight: 600; color: #cbd5e1; }
    .fi-simple-layout .rc-brand { color: var(--rc-ink); }
    .fi-simple-layout .rc-brand-sub { color: var(--rc-accent-text); }
    .fi-simple-main { border-radius: 0; box-shadow: none; border: 1px solid var(--rc-border); --tw-ring-shadow: 0 0 #0000; }

    /* The pages drawn by hand: cards, figures, chips and plain tables. */
    .rc-stack { display: flex; flex-direction: column; gap: 24px; font-size: 14px; line-height: 1.4; color: var(--rc-text); }
    .rc-row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; }
    .rc-grow-1 { flex: 1 1 300px; min-width: 0; }
    .rc-grow-2 { flex: 2 1 520px; min-width: 0; }
    .rc-card { background: #fff; border: 1px solid var(--rc-border); border-radius: 0; overflow: hidden; display: flex; flex-direction: column; }
    .rc-pad { padding: 20px 24px; gap: 16px; }
    .rc-card h2, .rc-attention h2 { margin: 0; font-size: 16px; font-weight: 700; color: var(--rc-ink); }
    .rc-card-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: baseline; }
    .rc-card-head > div:first-child { display: flex; flex-direction: column; gap: 2px; }
    .rc-card-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 24px; border-bottom: 1px solid var(--rc-border); }
    .rc-stack a { color: var(--rc-ink); font-weight: 600; font-size: 13px; }
    .rc-stack a:hover { color: var(--rc-ink-soft); text-decoration-color: var(--rc-accent-dark); }
    /* A whole row or card that is a link is not underlined. */
    .rc-stack .rc-activity a, .rc-stack a.rc-timeline-run { text-decoration: none; }
    .rc-muted { color: var(--rc-muted); font-size: 13px; }
    .rc-tone-primary { color: var(--rc-ink); }
    .rc-tone-danger { color: var(--rc-danger-text); }
    .rc-tone-neutral { color: var(--rc-ink-soft); }
    .rc-fill-primary { background: var(--rc-accent); }
    .rc-fill-danger { background: var(--rc-danger); }
    .rc-fill-grey { background: var(--rc-grey); }
    .rc-fill-accent { background: var(--rc-accent); }
    /* Striped, so a cache write is told from plain input without a colour of its own. */
    .rc-fill-sky { background: repeating-linear-gradient(45deg, var(--rc-grey), var(--rc-grey) 3px, var(--rc-soft) 3px, var(--rc-soft) 6px); }
    .rc-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

    .rc-chip { align-self: flex-start; display: inline-flex; align-items: center; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 100px; white-space: nowrap; }
    .rc-chip-danger, .rc-chip-changes, .rc-chip-medium { background: var(--rc-danger-tint); color: var(--rc-danger-text); }
    .rc-chip-neutral, .rc-chip-low { background: var(--rc-soft); color: var(--rc-ink-soft); }
    .rc-chip-primary { background: var(--rc-ink); color: #fff; }
    .rc-chip-approved { background: var(--rc-approved-tint); color: var(--rc-accent-text); }
    .rc-chip-running { background: var(--rc-accent-tint); color: var(--rc-ink); }
    .rc-chip-manual { background: #fff; color: var(--rc-ink); border: 1px solid var(--rc-ink); }
    .rc-chip-failed, .rc-chip-high { background: var(--rc-danger); color: var(--rc-on-danger); }
    .rc-chip-none { background: #fff; color: var(--rc-muted); border: 1px solid var(--rc-border); }
    .rc-tag { font-size: 12px; padding: 2px 8px; border-radius: 0; background: var(--rc-soft); color: var(--rc-ink-soft); white-space: nowrap; }
    .rc-dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 100px; display: inline-block; }
    .rc-dot-approved { background: var(--rc-ink); }
    .rc-dot-changes, .rc-dot-failed { background: var(--rc-danger); }
    .rc-dot-running { background: var(--rc-accent); }
    .rc-dot-manual { background: #fff; border: 2px solid var(--rc-ink); }
    .rc-dot-neutral { background: var(--rc-grey); }

    .rc-attention { background: var(--rc-accent-tint); border-radius: 0; padding: 20px 24px; display: flex; flex-direction: column; gap: 12px; }
    .rc-attention-head { display: flex; align-items: center; gap: 10px; color: var(--rc-ink); }
    .rc-attention-head span { font-size: 13px; color: var(--rc-ink-soft); }
    .rc-attention-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; }
    .rc-attention-card { background: #fff; border-radius: 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; }
    .rc-attention-card a { margin-top: auto; }

    .rc-figures { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; }
    .rc-figure { padding: 18px 20px; gap: 6px; }
    .rc-figure-value { font-family: var(--rc-display); font-size: 30px; font-weight: 700; color: var(--rc-ink); letter-spacing: -0.01em; line-height: 1.15; }
    .rc-figure-note { font-size: 12px; font-weight: 600; }
    .rc-bar { height: 6px; border-radius: 0; background: var(--rc-soft); overflow: hidden; display: flex; }
    .rc-bar-tall { height: 8px; }
    .rc-bar-fill { height: 100%; }

    .rc-legend { display: flex; flex-wrap: wrap; gap: 16px; font-size: 13px; color: var(--rc-ink-soft); align-items: center; }
    .rc-legend span { display: flex; align-items: center; gap: 6px; }
    .rc-legend i { width: 10px; height: 10px; border-radius: 0; display: inline-block; }
    .rc-chart { height: 200px; display: flex; align-items: flex-end; gap: 4px; border-bottom: 1px solid var(--rc-border); padding-top: 8px; }
    .rc-chart-day { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; min-width: 2px; }
    .rc-chart-day > div:first-child { border-radius: 0; }
    .rc-chart-axis { display: flex; justify-content: space-between; font-size: 12px; color: var(--rc-muted); }
    .rc-category { display: flex; flex-direction: column; gap: 6px; }
    .rc-category-head { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; color: var(--rc-ink-soft); }

    .rc-scroll { overflow-x: auto; }
    .rc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .rc-table th { text-align: left; color: var(--rc-muted); background: var(--rc-ground); padding: 10px 12px; font-weight: 600; white-space: nowrap; }
    .rc-table td { padding: 12px; border-top: 1px solid var(--rc-soft); vertical-align: middle; }
    .rc-table th:first-child, .rc-table td:first-child { padding-left: 24px; }
    .rc-table th:last-child, .rc-table td:last-child { padding-right: 24px; }
    .rc-table .rc-num { text-align: right; font-variant-numeric: tabular-nums; }
    .rc-repository { display: flex; align-items: center; gap: 8px; }
    .rc-repository > span:last-child { display: flex; flex-direction: column; }
    .rc-repository .rc-muted { font-size: 12px; }
    .rc-activity { display: flex; flex-direction: column; padding: 8px 0; }
    .rc-activity a { display: flex; gap: 12px; padding: 10px 24px; color: var(--rc-text); font-size: 14px; }
    .rc-activity a:hover { background: var(--rc-ground); }
    .rc-activity .rc-dot { margin-top: 6px; }
    .rc-activity a > span:last-child { display: flex; flex-direction: column; gap: 2px; }
    .rc-activity .rc-muted { font-size: 12px; font-weight: 400; }

    .rc-column { display: flex; flex-direction: column; gap: 16px; }
    .rc-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; font-size: 14px; color: var(--rc-ink-soft); }
    .rc-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
    .rc-timeline { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
    .rc-stack a.rc-timeline-run { display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; border: 1px solid var(--rc-border); border-radius: 0; background: var(--rc-ground); color: var(--rc-text); font-weight: 400; }
    .rc-stack a.rc-timeline-run:hover { text-decoration: none; border-color: var(--rc-ink); }
    .rc-stack a.rc-timeline-run.rc-current { border: 2px solid var(--rc-ink); background: #fff; padding: 11px 13px; }
    .rc-timeline-top { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
    .rc-prose { font-size: 14px; line-height: 1.5; display: flex; flex-direction: column; gap: 10px; }
    .rc-prose code, .rc-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; background: var(--rc-soft); border-radius: 0; }
    .rc-prose code { padding: 1px 5px; }
    .rc-prose ul { list-style: disc; padding-left: 20px; }
    .rc-prose ol { list-style: decimal; padding-left: 20px; }
    .rc-code { padding: 12px 14px; overflow-x: auto; white-space: pre; margin: 0; }
    .rc-question { padding: 12px 0; border-top: 1px solid var(--rc-soft); }
    .rc-ask { display: flex; flex-direction: column; gap: 8px; margin: 4px 0 12px; }
    .rc-ask textarea { width: 100%; font: inherit; padding: 10px 12px; border: 1px solid var(--rc-border); border-radius: 0; background: var(--rc-ground); color: inherit; resize: vertical; }
    .rc-ask .rc-button { background: var(--rc-button); color: var(--rc-button-text); border: 0; border-radius: 0; padding: 8px 18px; font-weight: 600; cursor: pointer; }
    .rc-ask .rc-button:hover { background: var(--rc-button-hover); }
    .rc-ask .rc-button[disabled] { opacity: .6; cursor: progress; }
    .rc-question:first-of-type { border-top: 0; }
    .rc-question p { margin: 0 0 4px; }
    .rc-log { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; line-height: 1.5; background: var(--rc-ink); color: #fff; border-radius: 0; padding: 12px 14px; margin: 12px 0 0; max-height: 420px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
    .rc-card details > summary { cursor: pointer; }
    .rc-finding { border: 1px solid var(--rc-border); border-radius: 0; padding: 16px 18px; display: flex; flex-direction: column; gap: 8px; }
    .rc-finding h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--rc-text); }
    .rc-finding-tags { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .rc-finding-id { font-weight: 700; color: var(--rc-ink); }
    .rc-pairs { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
    .rc-pairs > div { display: flex; flex-direction: column; gap: 2px; }
    .rc-pairs strong { font-family: var(--rc-display); font-size: 20px; color: var(--rc-ink); }
    .rc-legend-stacked { flex-direction: column; align-items: flex-start; gap: 6px; }
    .rc-note { background: var(--rc-ground); border-radius: 0; padding: 10px 12px; font-size: 13px; }
    /* What to do about a check that is not right: set apart, with its commands readable. */
    .rc-updates { display: flex; flex-direction: column; gap: 8px; }
    .rc-update summary { cursor: pointer; display: flex; gap: 8px; align-items: baseline; }
    .rc-diff { max-height: 420px; overflow: auto; font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; background: var(--rc-ground); border: 1px solid var(--rc-border); border-radius: 0; padding: 8px 0; margin-top: 6px; white-space: pre-wrap; }
    .rc-diff span { display: block; padding: 0 12px; }
    .rc-diff-add { background: var(--rc-approved-tint); }
    .rc-diff-del { background: var(--rc-danger-tint); }
    .rc-diff-gap { color: var(--rc-muted); }
    .rc-setup-fix { display: block; margin-top: 4px; font-size: 13px; }
    .rc-setup-fix p { margin: 0; }
    .rc-setup-fix p + p, .rc-setup-fix ol + p { margin-top: 6px; }
    .rc-setup-fix ol { list-style: decimal; padding-left: 20px; margin: 4px 0 0; }
    .rc-setup-fix li + li { margin-top: 4px; }
    .rc-setup-fix code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; background: var(--rc-ground); padding: 1px 5px; border-radius: 0; word-break: break-all; }
    /* Under the sign-in form: the other way in, and why it did not work. */
    .rc-sign-in-with { display: block; margin-top: 12px; padding: 10px 14px; border: 1px solid var(--rc-border, #e2e8f0); border-radius: 0; text-align: center; font-weight: 600; color: var(--rc-ink, #0f172a); text-decoration: none; background: #fff; }
    .rc-sign-in-with:hover, .rc-sign-in-with:focus-visible { border-color: var(--rc-ink, #0f172a); }
    .rc-sign-in-refused { margin-top: 12px; padding: 10px 12px; border-radius: 0; background: var(--rc-danger-tint, #fee2e2); color: var(--rc-danger-text, #b91c1c); font-size: 13px; }
    .rc-details { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; margin: 0; font-size: 13px; }
    .rc-details dt { color: var(--rc-muted); }
    .rc-details dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
    .rc-rate { background: var(--rc-ink); border-color: var(--rc-ink); color: #fff; font-size: 13px; }
    .rc-rate h2 { color: #fff; }
    .rc-stack .rc-rate a { color: #fff; }

    .rc-initials { width: 32px; height: 32px; flex: 0 0 32px; border-radius: 0; background: var(--rc-ink); color: #fff; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; }
    .rc-budget { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
    .rc-advice { display: flex; flex-direction: column; gap: 8px; }
    .rc-advice-item { display: flex; gap: 12px; align-items: flex-start; background: var(--rc-ground); border-radius: 0; padding: 12px 14px; }
    .rc-advice-item > span:last-child { display: flex; flex-direction: column; gap: 2px; }
    .rc-figures-plain { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .rc-figures-plain .rc-figure { display: flex; flex-direction: column; border: 1px solid var(--rc-border); border-radius: 0; }
    .rc-chart-short { height: 120px; position: relative; margin-top: 12px; }
    .rc-chart-line { position: absolute; left: 0; right: 0; border-top: 1px dashed var(--rc-grey); }

    .rc-verdicts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; border-top: 1px solid var(--rc-soft); padding-top: 12px; margin-top: 4px; }
    .rc-verdict { border: 1px solid var(--rc-border); border-radius: 0; background: #fff; color: var(--rc-text); padding: 6px 12px; font-size: 13px; font-weight: 500; min-height: 32px; cursor: pointer; }
    .rc-verdict:hover { border-color: var(--rc-ink); }
    .rc-verdict-valid { color: var(--rc-ink); border-color: var(--rc-ink); }
    .rc-verdict-valid.rc-on { background: var(--rc-ink); color: #fff; }
    .rc-verdict-not_a_problem.rc-on { background: var(--rc-ink-soft); border-color: var(--rc-ink-soft); color: #fff; }
    .rc-verdict-wrong.rc-on { background: var(--rc-danger); border-color: var(--rc-danger); color: var(--rc-on-danger); }
    .rc-stars { display: flex; gap: 6px; }
    .rc-stars button { width: 40px; height: 40px; border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 0; background: transparent; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .rc-stars button.rc-on { background: var(--rc-accent); border-color: var(--rc-accent); color: var(--rc-on-accent); }

    .rc-readiness { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; font-size: 14px; }
    .rc-readiness li { display: flex; gap: 12px; align-items: flex-start; }
    .rc-readiness li > span:last-child { display: flex; flex-direction: column; gap: 2px; }
    .rc-mark { width: 24px; height: 24px; flex: 0 0 24px; border-radius: 100px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
    .rc-mark-ok { background: var(--rc-accent); color: var(--rc-on-accent); }
    .rc-mark-problem { background: var(--rc-danger); color: var(--rc-on-danger); }
    .rc-mark-unknown { background: var(--rc-soft); color: var(--rc-ink-soft); }
</style>
