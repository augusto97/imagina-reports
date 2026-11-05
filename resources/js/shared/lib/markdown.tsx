import { Fragment, type ReactElement, type ReactNode } from 'react';

/**
 * The bit of Markdown an AI answer uses — paragraphs, line breaks, «- » and «1. » lists, **bold**,
 * *italic*, `code` and «## headings» (shown bold) — rendered as React elements. Never injects HTML,
 * so a reply can't carry markup or scripts; anything else shows as the plain text it is.
 */
export function SimpleMarkdown({ text, className }: { text: string; className?: string }): ReactElement {
    const blocks = text.replace(/\r\n?/g, '\n').trim().split(/\n{2,}/);

    return (
        <div className={className}>
            {blocks.map((block, index) => (
                <Fragment key={index}>{renderBlock(block, index)}</Fragment>
            ))}
        </div>
    );
}

const BULLET = /^\s*[-*•]\s+(.*)$/;
const NUMBERED = /^\s*\d+[.)]\s+(.*)$/;
const HEADING = /^\s*#{1,6}\s+(.*)$/;

function renderBlock(block: string, key: number): ReactNode {
    const lines = block.split('\n').filter((line) => line.trim() !== '');
    const nodes: ReactNode[] = [];
    let paragraph: string[] = [];
    let list: { ordered: boolean; items: string[] } | null = null;

    const flushParagraph = (): void => {
        if (paragraph.length > 0) {
            const lines_ = paragraph;
            nodes.push(
                <p key={`p${nodes.length}`} className="ir-my-0 [&:not(:first-child)]:ir-mt-2">
                    {lines_.map((line, index) => (
                        <Fragment key={index}>
                            {index > 0 && <br />}
                            {inline(line)}
                        </Fragment>
                    ))}
                </p>,
            );
            paragraph = [];
        }
    };
    const flushList = (): void => {
        if (list !== null) {
            const { ordered, items } = list;
            const Tag = ordered ? 'ol' : 'ul';
            nodes.push(
                <Tag key={`l${nodes.length}`} className={`ir-my-0 ir-space-y-1 ir-pl-5 [&:not(:first-child)]:ir-mt-2 ${ordered ? 'ir-list-decimal' : 'ir-list-disc'}`}>
                    {items.map((item, index) => (
                        <li key={index}>{inline(item)}</li>
                    ))}
                </Tag>,
            );
            list = null;
        }
    };

    for (const line of lines) {
        const bullet = BULLET.exec(line);
        const numbered = bullet === null ? NUMBERED.exec(line) : null;
        const heading = HEADING.exec(line);
        if (bullet !== null || numbered !== null) {
            flushParagraph();
            const ordered = numbered !== null;
            if (list === null || list.ordered !== ordered) {
                flushList();
                list = { ordered, items: [] };
            }
            list.items.push((bullet ?? numbered)?.[1] ?? '');
        } else if (heading !== null) {
            flushParagraph();
            flushList();
            nodes.push(
                <p key={`h${nodes.length}`} className="ir-my-0 ir-font-semibold [&:not(:first-child)]:ir-mt-2">
                    {inline(heading[1] ?? '')}
                </p>,
            );
        } else {
            flushList();
            paragraph.push(line);
        }
    }
    flushParagraph();
    flushList();

    return <div key={key} className="[&:not(:first-child)]:ir-mt-2.5">{nodes}</div>;
}

/** **bold**, __bold__, *italic*, `code` — everything else as plain text. */
function inline(text: string): ReactNode[] {
    const out: ReactNode[] = [];
    const pattern = /(\*\*[^*\n]+\*\*|__[^_\n]+__|`[^`\n]+`|\*[^*\s][^*\n]*\*)/g;
    let last = 0;
    for (const match of text.matchAll(pattern)) {
        const token = match[0];
        const at = match.index ?? 0;
        if (at > last) {
            out.push(text.slice(last, at));
        }
        if (token.startsWith('**') || token.startsWith('__')) {
            out.push(<strong key={at}>{token.slice(2, -2)}</strong>);
        } else if (token.startsWith('`')) {
            out.push(
                <code key={at} className="ir-rounded ir-bg-muted ir-px-1 ir-text-[0.9em]">
                    {token.slice(1, -1)}
                </code>,
            );
        } else {
            out.push(<em key={at}>{token.slice(1, -1)}</em>);
        }
        last = at + token.length;
    }
    if (last < text.length) {
        out.push(text.slice(last));
    }

    return out;
}
