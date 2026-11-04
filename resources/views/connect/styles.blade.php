<style>
    :root { --accent: {{ $accent ?? '#18181b' }}; color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; padding: 2.5rem 1rem;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        background: #f4f5f7; color: #18181b; -webkit-font-smoothing: antialiased;
    }
    main { width: 100%; max-width: 34rem; margin: 0 auto; }
    .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 1rem; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px -12px rgba(16,24,40,.12); overflow: hidden; }
    .head { padding: 1.75rem 1.75rem 1.25rem; border-top: 4px solid var(--accent); }
    .brand { display: flex; align-items: center; gap: .65rem; margin-bottom: 1.25rem; font-size: .85rem; font-weight: 600; color: #3f3f46; }
    .brand img { max-height: 2rem; max-width: 9rem; object-fit: contain; }
    .brand .mark { width: 2rem; height: 2rem; border-radius: .6rem; display: grid; place-items: center; background: var(--accent); color: #fff; font-weight: 700; }
    h1 { font-size: 1.35rem; font-weight: 650; margin: 0 0 .5rem; letter-spacing: -.015em; line-height: 1.3; }
    p { color: #52525b; margin: 0; line-height: 1.55; font-size: .92rem; }
    .site { display: inline-flex; align-items: center; gap: .4rem; margin-top: .9rem; padding: .3rem .65rem; border-radius: 9999px; background: #f4f4f5; font-size: .8rem; color: #3f3f46; }
    .list { border-top: 1px solid #f0f0f2; }
    .row { padding: 1rem 1.75rem; border-bottom: 1px solid #f0f0f2; }
    .row:last-child { border-bottom: 0; }
    .row-top { display: flex; align-items: center; gap: .85rem; }
    .icon { flex: none; width: 2.4rem; height: 2.4rem; border-radius: .7rem; display: grid; place-items: center; font-weight: 700; font-size: .8rem; color: #fff; }
    .name { font-weight: 600; font-size: .93rem; }
    .state { font-size: .78rem; color: #71717a; margin-top: .1rem; }
    .state.ok { color: #15803d; }
    .state.warn { color: #b45309; }
    .grow { flex: 1; min-width: 0; }
    form { margin: 0; }
    .inline { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
    .inline input, .inline select { flex: 1 1 14rem; min-width: 0; font: inherit; font-size: .88rem; padding: .55rem .7rem; border: 1px solid #d4d4d8; border-radius: .55rem; background: #fff; color: #18181b; }
    .inline input:focus, .inline select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
    .help { font-size: .76rem; color: #71717a; margin-top: .4rem; line-height: 1.45; }
    .field-error { font-size: .78rem; color: #b91c1c; margin-top: .35rem; }
    button { font: inherit; font-weight: 600; font-size: .86rem; padding: .55rem 1rem; border-radius: .55rem; cursor: pointer; border: 1px solid transparent; background: var(--accent); color: #fff; white-space: nowrap; }
    button:hover { filter: brightness(1.08); }
    button.ghost { background: #fff; color: #18181b; border-color: #e4e4e7; }
    button.ghost:hover { background: #fafafa; filter: none; }
    .notice { margin: 0 0 1rem; padding: .8rem 1rem; border-radius: .75rem; font-size: .88rem; line-height: 1.5; }
    .notice.ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
    .notice.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .foot { padding: 1rem 1.75rem 1.4rem; background: #fafafa; border-top: 1px solid #f0f0f2; }
    .foot p { font-size: .78rem; color: #71717a; }
    .foot ul { margin: 0 0 .6rem; padding: 0; list-style: none; display: grid; gap: .35rem; }
    .foot li { display: flex; gap: .5rem; font-size: .8rem; color: #52525b; line-height: 1.45; }
    .foot li::before { content: '✓'; color: #15803d; font-weight: 700; }
    .powered { text-align: center; margin-top: 1.25rem; font-size: .72rem; color: #a1a1aa; }
    @media (max-width: 480px) {
        body { padding: 1rem .75rem; }
        .head, .row, .foot { padding-left: 1.15rem; padding-right: 1.15rem; }
        .row-top { flex-wrap: wrap; }
        .row-top > form { width: 100%; }
        .row-top > form button, .inline button { width: 100%; }
    }
</style>
