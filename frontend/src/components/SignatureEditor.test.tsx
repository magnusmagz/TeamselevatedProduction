import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * The signature editor's TOOLBAR and its EXTENSION SET.
 *
 * @tiptap ships raw .ts out of node_modules and Jest's transformIgnorePatterns
 * will not transform it, so importing the real library fails the suite at parse
 * time — the same wall documented in TournamentCreate.test.tsx. Rather than stub
 * the whole component and test nothing, tiptap is mocked at the MODULE boundary:
 * the real SignatureEditor renders, the real toolbar buttons are clicked, and
 * the commands they issue are recorded. That is where this component's bugs
 * actually live — the wrong command, a missing .focus(), a toolbar that steals
 * the selection before the toggle applies — none of which needs a real editor.
 *
 * What is NOT covered here is tiptap's own serialisation, which is tiptap's
 * problem. What IS covered, and matters more, is that the editor cannot produce
 * markup lib/signature_html.php would silently strip.
 */

// create-react-app's Jest config sets resetMocks: true, which strips a mock's
// IMPLEMENTATION between tests, not just its recorded calls. Defining the chain
// once at module scope therefore leaves chain() returning undefined from the
// second test onward. The objects keep their identity (the jest.mock factory
// closed over them) and are re-armed in beforeEach.
const mockChain: any = {};
const mockEditor: any = {};

function armMocks() {
  const self = () => mockChain;
  for (const command of [
    'focus',
    'extendMarkRange',
    'toggleBold',
    'toggleItalic',
    'toggleUnderline',
    'setLink',
    'unsetLink',
    'setHardBreak',
  ]) {
    mockChain[command] = jest.fn(self);
  }
  mockChain.run = jest.fn();

  mockEditor.chain = jest.fn(() => mockChain);
  mockEditor.isActive = jest.fn(() => false);
  mockEditor.getAttributes = jest.fn(() => ({}));
  mockEditor.getHTML = jest.fn(() => '<p>Coach <strong>Smith</strong></p>');
}

let mockCapturedOptions: any = null;
let mockStarterKitConfig: any = null;

jest.mock('@tiptap/react', () => ({
  __esModule: true,
  useEditor: (options: any) => {
    mockCapturedOptions = options;
    return mockEditor;
  },
  EditorContent: () => <div data-testid="editor-content" />,
  Editor: class {},
}));

jest.mock('@tiptap/starter-kit', () => ({
  __esModule: true,
  default: {
    configure: (config: any) => {
      mockStarterKitConfig = config;
      return { name: 'starterKit', config };
    },
  },
}));

// eslint-disable-next-line import/first
import SignatureEditor from './SignatureEditor';

describe('SignatureEditor', () => {
  beforeEach(() => {
    armMocks();
    mockCapturedOptions = null;
    mockStarterKitConfig = null;
  });

  const renderEditor = (onChange = jest.fn()) => {
    render(<SignatureEditor value="<p>Coach</p>" onChange={onChange} />);
    return onChange;
  };

  describe('the extension set restates the server allowlist', () => {
    // A button whose output never survives a save is worse than no button: the
    // staff member formats their signature, saves, and watches it come back
    // plain with nothing on screen explaining why.
    it.each([
      'heading',
      'codeBlock',
      'code',
      'blockquote',
      'bulletList',
      'orderedList',
      'listItem',
      'horizontalRule',
      'strike',
    ])('disables %s, which te_sig_allowed_tags() would strip', (extension) => {
      renderEditor();

      expect(mockStarterKitConfig[extension]).toBe(false);
    });

    it('bounds link protocols to the three te_sig_safe_href() accepts', () => {
      renderEditor();

      // Refusing javascript: at the point of PASTE is the point. A URL accepted
      // in the editor and stripped on save is a link the staff member believes
      // they added.
      expect(mockStarterKitConfig.link.protocols).toEqual(['http', 'https', 'mailto']);
      expect(mockStarterKitConfig.link.HTMLAttributes.rel).toBe('noopener noreferrer');
    });

    it('disables the trailing node, which would append a blank line to every send', () => {
      renderEditor();

      expect(mockStarterKitConfig.trailingNode).toBe(false);
    });

    it('seeds the editor with the value it was given', () => {
      renderEditor();

      expect(mockCapturedOptions.content).toBe('<p>Coach</p>');
    });
  });

  describe('the toolbar', () => {
    it('bold, italic and underline each issue their own focused command', () => {
      renderEditor();

      fireEvent.click(screen.getByTitle('Bold'));
      expect(mockChain.toggleBold).toHaveBeenCalled();

      fireEvent.click(screen.getByTitle('Italic'));
      expect(mockChain.toggleItalic).toHaveBeenCalled();

      fireEvent.click(screen.getByTitle('Underline'));
      expect(mockChain.toggleUnderline).toHaveBeenCalled();

      // .focus() before each, or the toggle applies to nothing.
      expect(mockChain.focus).toHaveBeenCalledTimes(3);
      expect(mockChain.run).toHaveBeenCalledTimes(3);
    });

    it('inserts a hard break rather than a paragraph', () => {
      renderEditor();

      fireEvent.click(screen.getByTitle('Line break'));

      expect(mockChain.setHardBreak).toHaveBeenCalled();
    });

    it('sets a link from the prompt, over the whole mark range', () => {
      const prompt = jest.spyOn(window, 'prompt').mockReturnValue('https://club.example');
      renderEditor();

      fireEvent.click(screen.getByTitle('Link'));

      // extendMarkRange, or editing an existing link only changes the part of it
      // the caret happens to be inside.
      expect(mockChain.extendMarkRange).toHaveBeenCalledWith('link');
      expect(mockChain.setLink).toHaveBeenCalledWith({ href: 'https://club.example' });

      prompt.mockRestore();
    });

    it('an emptied prompt removes the link instead of setting an empty one', () => {
      const prompt = jest.spyOn(window, 'prompt').mockReturnValue('');
      renderEditor();

      fireEvent.click(screen.getByTitle('Link'));

      expect(mockChain.unsetLink).toHaveBeenCalled();
      expect(mockChain.setLink).not.toHaveBeenCalled();

      prompt.mockRestore();
    });

    it('a cancelled prompt changes nothing at all', () => {
      // null is Cancel; '' is "clear the link". Treating them the same would
      // strip a link every time someone opened the dialog and thought better
      // of it.
      const prompt = jest.spyOn(window, 'prompt').mockReturnValue(null);
      renderEditor();

      fireEvent.click(screen.getByTitle('Link'));

      expect(mockChain.setLink).not.toHaveBeenCalled();
      expect(mockChain.unsetLink).not.toHaveBeenCalled();

      prompt.mockRestore();
    });

    it('does not take focus away from the document on mousedown', () => {
      renderEditor();

      const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
      screen.getByTitle('Bold').dispatchEvent(event);

      // Without preventDefault the editor loses its selection the instant the
      // button is pressed, and every toggle appears to do nothing.
      expect(event.defaultPrevented).toBe(true);
    });
  });

  describe('reporting changes upward', () => {
    it('hands the parent the editor HTML on every update', () => {
      const onChange = renderEditor();

      mockCapturedOptions.onUpdate({ editor: mockEditor });

      expect(onChange).toHaveBeenCalledWith('<p>Coach <strong>Smith</strong></p>');
    });
  });
});
