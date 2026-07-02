import DOMPurify from 'dompurify';

/**
 * Sanitize agency-authored Tiptap HTML before it is rendered into the report (portal + PDF).
 * The narrative block stores rich text as HTML; rendering it raw would be a stored-XSS vector
 * (an agency author against their own clients), and rendering it escaped shows literal
 * `<p>` tags. DOMPurify strips scripts, event handlers and `javascript:` URLs while keeping
 * the formatting Tiptap produces (paragraphs, bold, lists, links).
 */
export function sanitizeReportHtml(html: string): string {
    return DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target', 'rel'],
    });
}

/** Plain-text content of an HTML string — used to tell whether a narrative is effectively empty. */
export function htmlToText(html: string): string {
    if (typeof document === 'undefined') {
        return html.replace(/<[^>]*>/g, '').trim();
    }

    const el = document.createElement('div');
    el.innerHTML = html;

    return (el.textContent ?? '').trim();
}
