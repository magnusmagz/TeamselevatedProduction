import React from 'react';
import { useEditor, EditorContent, Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';

interface SignatureEditorProps {
  /** Initial markup. The editor is uncontrolled after mount — see below. */
  value: string;
  onChange: (html: string) => void;
}

/**
 * The rich email-signature editor (roadmap 2.5, R13).
 *
 * tiptap, because the project already depends on it (MarkdownEditor in the
 * tournament module) and adding a second rich-text library to edit eight tags
 * would be worse than reusing the one that is here.
 *
 * ⚠️ THE EXTENSION SET IS THE ALLOWLIST, RESTATED. Everything StarterKit offers
 * that lib/signature_html.php would strip is switched OFF here, so the editor
 * cannot produce markup the server then silently removes. A heading button whose
 * output never survives a save is worse than no heading button: the staff member
 * formats their signature, saves, and watches it come back plain with no
 * explanation. When a tag is added to te_sig_allowed_tags(), enable it here in
 * the same commit — and when one is removed there, disable it here.
 *
 * Links are DELIBERATELY not a toolbar button that opens a dialog of its own.
 * StarterKit's Link is enabled for paste and for the prompt below, bounded to
 * the same three schemes the server accepts, so a pasted `javascript:` URL is
 * refused at the point of paste rather than accepted, saved, and stripped.
 */
const ToolbarButton: React.FC<{
  onClick: () => void;
  active?: boolean;
  title: string;
  children: React.ReactNode;
}> = ({ onClick, active, title, children }) => (
  <button
    type="button"
    // Without this the editor loses selection the instant the button is pressed,
    // and a toggle applied to nothing is a button that appears to do nothing.
    onMouseDown={(e) => e.preventDefault()}
    onClick={onClick}
    title={title}
    aria-label={title}
    aria-pressed={!!active}
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

    if (url.trim() === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run();
      return;
    }

    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
  };

  return (
    <div className="flex flex-wrap items-center gap-1 px-2 py-1.5 border-b border-brand-secondary bg-gray-50">
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
      <ToolbarButton
        onClick={() => editor.chain().focus().toggleUnderline().run()}
        active={editor.isActive('underline')}
        title="Underline"
      >
        <u>U</u>
      </ToolbarButton>
      <span className="w-px h-5 bg-gray-300 mx-1" />
      <ToolbarButton
        onClick={promptForLink}
        active={editor.isActive('link')}
        title="Link"
      >
        Link
      </ToolbarButton>
      <ToolbarButton
        onClick={() => editor.chain().focus().setHardBreak().run()}
        title="Line break"
      >
        Break
      </ToolbarButton>
    </div>
  );
};

const SignatureEditor: React.FC<SignatureEditorProps> = ({ value, onChange }) => {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        // Kept: paragraph, bold, italic, underline, hardBreak, link, undoRedo.
        // Everything below produces markup te_sig_allowed_tags() does not accept.
        heading: false,
        codeBlock: false,
        code: false,
        blockquote: false,
        bulletList: false,
        orderedList: false,
        listItem: false,
        listKeymap: false,
        horizontalRule: false,
        strike: false,
        // A trailing empty paragraph is an editing convenience upstream; here it
        // would append a blank line to every signature that ships.
        trailingNode: false,
        link: {
          openOnClick: false,
          autolink: true,
          // The same three schemes te_sig_safe_href() accepts. Refusing a
          // javascript: URL at the point of paste is why the editor and the
          // sanitiser have to agree: a URL accepted here and stripped on save
          // is a link the staff member believes they added.
          protocols: ['http', 'https', 'mailto'],
          HTMLAttributes: { rel: 'noopener noreferrer' },
        },
      }),
    ],
    content: value,
    editorProps: {
      attributes: {
        class: 'prose prose-sm max-w-none px-3 py-2 focus:outline-none min-h-[7rem]',
        'aria-label': 'Email signature',
        'data-testid': 'signature-editor-surface',
      },
    },
    onUpdate: ({ editor: instance }) => onChange(instance.getHTML()),
  });

  if (!editor) return null;

  return (
    <div className="border border-brand-secondary rounded-md overflow-hidden bg-white">
      <Toolbar editor={editor} />
      <EditorContent editor={editor} />
    </div>
  );
};

export default SignatureEditor;
