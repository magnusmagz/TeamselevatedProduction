import React, { useEffect } from 'react';
import { useEditor, EditorContent, Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { Markdown } from 'tiptap-markdown';

interface MarkdownEditorProps {
  value: string;
  onChange: (markdown: string) => void;
  placeholder?: string;
  minHeight?: number;
}

const ToolbarButton: React.FC<{
  onClick: () => void;
  active?: boolean;
  title: string;
  children: React.ReactNode;
}> = ({ onClick, active, title, children }) => (
  <button
    type="button"
    onMouseDown={(e) => e.preventDefault()}
    onClick={onClick}
    title={title}
    className={`px-2 py-1 text-sm rounded hover:bg-gray-100 ${
      active ? 'bg-gray-200 text-gray-900' : 'text-gray-700'
    }`}
  >
    {children}
  </button>
);

const Toolbar: React.FC<{ editor: Editor }> = ({ editor }) => {
  const promptForLink = () => {
    const previous = editor.getAttributes('link').href as string | undefined;
    const url = window.prompt('Link URL', previous || 'https://');
    if (url === null) return;
    if (url === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
  };

  return (
    <div className="flex flex-wrap items-center gap-1 px-2 py-1.5 border-b border-gray-300 bg-gray-50">
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
        active={editor.isActive('heading', { level: 2 })}
        title="Heading"
      >
        <strong>H</strong>
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBold().run()}
        active={editor.isActive('bold')}
        title="Bold"
      >
        <strong>B</strong>
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleItalic().run()}
        active={editor.isActive('italic')}
        title="Italic"
      >
        <em>I</em>
      </ToolbarButton>
      <span className="w-px h-5 bg-gray-300 mx-1" />
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBulletList().run()}
        active={editor.isActive('bulletList')}
        title="Bulleted list"
      >
        • List
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
        active={editor.isActive('orderedList')}
        title="Numbered list"
      >
        1. List
      </ToolbarButton>
      <span className="w-px h-5 bg-gray-300 mx-1" />
      <ToolbarButton
        onClick={promptForLink}
        active={editor.isActive('link')}
        title="Link"
      >
        🔗 Link
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleBlockquote().run()}
        active={editor.isActive('blockquote')}
        title="Quote"
      >
        ❝ Quote
      </ToolbarButton>
    </div>
  );
};

const MarkdownEditor: React.FC<MarkdownEditorProps> = ({
  value,
  onChange,
  placeholder,
  minHeight = 240,
}) => {
  const editor = useEditor({
    extensions: [
      StarterKit,
      Link.configure({ openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' } }),
      Markdown.configure({ html: false, transformPastedText: true, transformCopiedText: true, breaks: true }),
    ],
    content: value || '',
    editorProps: {
      attributes: {
        class: 'prose prose-sm max-w-none focus:outline-none px-3 py-2',
        style: `min-height: ${minHeight}px`,
        ...(placeholder ? { 'data-placeholder': placeholder } : {}),
      },
    },
    onUpdate: ({ editor: e }) => {
      const md = ((e.storage as unknown as { markdown: { getMarkdown: () => string } }).markdown).getMarkdown();
      onChange(md);
    },
  });

  useEffect(() => {
    if (!editor) return;
    const current = ((editor.storage as unknown as { markdown: { getMarkdown: () => string } }).markdown.getMarkdown());
    if (value !== current) {
      editor.commands.setContent(value || '');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, editor]);

  if (!editor) {
    return (
      <div className="border border-gray-300 rounded-md bg-white" style={{ minHeight: minHeight + 40 }} />
    );
  }

  return (
    <div className="border border-gray-300 rounded-md bg-white overflow-hidden focus-within:ring-2 focus-within:ring-brand-primary focus-within:border-brand-primary">
      <Toolbar editor={editor} />
      <EditorContent editor={editor} />
    </div>
  );
};

export default MarkdownEditor;
