<style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        background: #fafafa; color: #18181b;
    }
    main { width: 100%; max-width: 30rem; background: #fff; border: 1px solid #e4e4e7; border-radius: .75rem; padding: 1.75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .5rem; letter-spacing: -.01em; }
    p { color: #52525b; margin: 0 0 1rem; line-height: 1.5; font-size: .925rem; }
    .muted { color: #71717a; font-size: .8rem; }
    fieldset { border: 1px solid #e4e4e7; border-radius: .5rem; padding: .5rem .9rem; margin: 0 0 1.25rem; }
    legend { font-size: .8rem; font-weight: 600; padding: 0 .25rem; color: #3f3f46; }
    label { display: flex; gap: .6rem; align-items: flex-start; padding: .45rem 0; font-size: .9rem; cursor: pointer; }
    input[type=checkbox] { margin-top: .2rem; }
    .actions { display: flex; gap: .6rem; justify-content: flex-end; flex-wrap: wrap; }
    button, a.button { font: inherit; font-weight: 500; font-size: .9rem; padding: .6rem 1.1rem; border-radius: .5rem; cursor: pointer; border: 1px solid #e4e4e7; background: #fff; color: #18181b; text-decoration: none; }
    button.primary { background: #18181b; color: #fafafa; border-color: #18181b; }
    .badge { width: 2.75rem; height: 2.75rem; margin-bottom: 1rem; display: grid; place-items: center; border-radius: 9999px; background: #f4f4f5; font-size: 1.25rem; }
    @media (prefers-color-scheme: dark) {
        body { background: #09090b; color: #fafafa; }
        main { background: #18181b; border-color: #27272a; }
        p { color: #a1a1aa; }
        fieldset { border-color: #27272a; }
        legend { color: #d4d4d8; }
        button, a.button { background: #18181b; color: #fafafa; border-color: #3f3f46; }
        button.primary { background: #fafafa; color: #18181b; border-color: #fafafa; }
        .badge { background: #27272a; }
    }
</style>
