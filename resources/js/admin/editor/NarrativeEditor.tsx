import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { type ReactElement } from 'react';

/** Tiptap rich-text editor for `narrative` blocks (CLAUDE.md §11.3). */
export function NarrativeEditor({
    value,
    onChange,
}: {
    value: string;
    onChange: (html: string) => void;
}): ReactElement {
    const editor = useEditor({
        extensions: [StarterKit],
        content: value,
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
    });

    return (
        <div className="ir-rounded-md ir-border ir-bg-background ir-p-2 ir-text-sm">
            <EditorContent editor={editor} />
        </div>
    );
}
