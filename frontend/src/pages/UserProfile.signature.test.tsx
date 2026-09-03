import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * The signature half of the profile page: which key the PUT carries, and what
 * the preview shows.
 *
 * The editor itself is stubbed with a textarea. @tiptap ships raw .ts out of
 * node_modules and Jest will not transform it (see SignatureEditor.test.tsx,
 * which mocks the library instead to cover the toolbar). Nothing here needs a
 * real editor — what is under test is the contract between this page and
 * api/user-profile.php.
 */

jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 7, name: 'Coach Smith' }, updateUser: jest.fn() }),
}));

jest.mock('../components/PushNotificationToggle', () => ({
  __esModule: true,
  default: () => null,
}));

jest.mock('../components/SignatureEditor', () => ({
  __esModule: true,
  default: ({ value, onChange }: { value: string; onChange: (html: string) => void }) => (
    <textarea
      data-testid="rich-signature"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));

// eslint-disable-next-line import/first
import UserProfile from './UserProfile';

const baseUser = {
  id: 7,
  email: 'coach@club.example',
  first_name: 'Coach',
  last_name: 'Smith',
  phone: '',
  email_signature: '',
  email_signature_format: 'text',
  created_at: '2026-01-01',
};

const mockFetch = jest.fn();

/** Route by method, not by call order — the page GETs on mount, then PUTs. */
function routeFetch(getUser: any, putUser: any) {
  mockFetch.mockImplementation((_url: string, options?: any) =>
    Promise.resolve({
      ok: true,
      json: async () =>
        options?.method === 'PUT'
          ? { success: true, user: putUser }
          : { success: true, user: getUser },
    })
  );
}

/** The body of the most recent PUT. */
function lastPutBody() {
  const put = mockFetch.mock.calls.filter((c) => c[1]?.method === 'PUT').pop();
  return JSON.parse(put![1].body);
}

describe('UserProfile — email signature', () => {
  beforeEach(() => {
    global.fetch = mockFetch as any;
    mockFetch.mockReset();
    window.localStorage.setItem('auth_token', 'token');
  });

  it('shows the plain textarea for a text signature and PUTs email_signature', async () => {
    const stored = { ...baseUser, email_signature: 'Coach Smith\nRiverside SC' };
    routeFetch(stored, stored);

    render(<UserProfile />);

    const textarea = await screen.findByLabelText('Email signature');
    expect(textarea).toHaveValue('Coach Smith\nRiverside SC');
    expect(screen.queryByTestId('rich-signature')).not.toBeInTheDocument();

    fireEvent.change(textarea, { target: { value: 'Coach Smith' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => expect(lastPutBody().email_signature).toBe('Coach Smith'));
    // The rich key must be absent, or the endpoint stamps format='html' on a
    // signature that is plain text — and that row's contents ship unescaped.
    expect(lastPutBody().email_signature_html).toBeUndefined();
  });

  it('opens the rich editor for an html signature and PUTs email_signature_html', async () => {
    const stored = {
      ...baseUser,
      email_signature: '<p>Coach <strong>Smith</strong></p>',
      email_signature_format: 'html',
    };
    routeFetch(stored, stored);

    render(<UserProfile />);

    const editor = await screen.findByTestId('rich-signature');
    expect(editor).toHaveValue('<p>Coach <strong>Smith</strong></p>');

    fireEvent.change(editor, { target: { value: '<p>Coach <em>Smith</em></p>' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() =>
      expect(lastPutBody().email_signature_html).toBe('<p>Coach <em>Smith</em></p>')
    );
  });

  it('falls back to the plain textarea when the backend sends no format at all', async () => {
    // ?? not ||. An ABSENT format means a backend older than this deploy, and
    // must land on the escaping path — the safe one — rather than being read as
    // html and emitted raw.
    const { email_signature_format, ...noFormat } = baseUser;
    const stored = { ...noFormat, email_signature: '<b>not really html</b>' };
    routeFetch(stored, stored);

    render(<UserProfile />);

    const textarea = await screen.findByLabelText('Email signature');
    expect(textarea).toHaveValue('<b>not really html</b>');
    expect(screen.queryByTestId('rich-signature')).not.toBeInTheDocument();
  });

  it('carries an existing text signature into the rich editor, escaped', async () => {
    const stored = { ...baseUser, email_signature: 'Coach <b>Smith</b>\nRiverside' };
    routeFetch(stored, stored);

    render(<UserProfile />);
    await screen.findByLabelText('Email signature');

    fireEvent.click(screen.getByRole('button', { name: /use formatting/i }));

    const editor = await screen.findByTestId('rich-signature');
    expect(editor).toHaveValue('<p>Coach &lt;b&gt;Smith&lt;/b&gt;</p><p>Riverside</p>');
  });

  it('switching back to plain text keeps the words and asks first', async () => {
    const stored = {
      ...baseUser,
      email_signature: '<p>Coach <strong>Smith</strong></p><p>Riverside</p>',
      email_signature_format: 'html',
    };
    routeFetch(stored, stored);
    const confirm = jest.spyOn(window, 'confirm').mockReturnValue(true);

    render(<UserProfile />);
    await screen.findByTestId('rich-signature');

    fireEvent.click(screen.getByRole('button', { name: /switch to plain text/i }));

    expect(confirm).toHaveBeenCalled();
    expect(await screen.findByLabelText('Email signature')).toHaveValue(
      'Coach Smith\nRiverside'
    );

    confirm.mockRestore();
  });

  it('declining the confirmation leaves the rich signature alone', async () => {
    const stored = {
      ...baseUser,
      email_signature: '<p>Coach</p>',
      email_signature_format: 'html',
    };
    routeFetch(stored, stored);
    const confirm = jest.spyOn(window, 'confirm').mockReturnValue(false);

    render(<UserProfile />);
    await screen.findByTestId('rich-signature');

    fireEvent.click(screen.getByRole('button', { name: /switch to plain text/i }));

    expect(screen.getByTestId('rich-signature')).toBeInTheDocument();
    confirm.mockRestore();
  });

  describe('the preview', () => {
    it('renders what the SERVER stored, not what the editor produced', async () => {
      // The round trip is the whole point: a tag the sanitiser removed has to be
      // visibly gone, or the staff member approves something other than what
      // families receive.
      const stored = {
        ...baseUser,
        email_signature: '<p>Coach</p>',
        email_signature_format: 'html',
      };
      const sanitised = { ...stored, email_signature: '<p>Coach <strong>S</strong></p>' };
      routeFetch(stored, sanitised);

      render(<UserProfile />);
      const editor = await screen.findByTestId('rich-signature');

      // The editor is told something the sanitiser will strip.
      fireEvent.change(editor, {
        target: { value: '<p>Coach <strong>S</strong><script>x()</script></p>' },
      });
      fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

      const preview = await screen.findByTestId('signature-preview');
      await waitFor(() => expect(preview).toHaveTextContent('Coach S'));
      expect(preview.innerHTML).toContain('<strong>S</strong>');
      expect(preview.innerHTML).not.toContain('script');
    });

    it('escapes a plain-text signature, matching te_render_signature_html', async () => {
      // The text path is ESCAPED at send time. A preview that rendered it as
      // markup would show the staff member something the family never sees.
      const stored = { ...baseUser, email_signature: 'Coach <b>Smith</b>' };
      routeFetch(stored, stored);

      render(<UserProfile />);

      const preview = await screen.findByTestId('signature-preview');
      expect(preview).toHaveTextContent('Coach <b>Smith</b>');
      expect(preview.querySelector('b')).toBeNull();
    });

    it('is absent entirely when nothing has been saved', async () => {
      routeFetch(baseUser, baseUser);

      render(<UserProfile />);
      await screen.findByLabelText('Email signature');

      expect(screen.queryByTestId('signature-preview')).not.toBeInTheDocument();
    });

    it('says so while there are unsaved changes', async () => {
      const stored = { ...baseUser, email_signature: 'Coach' };
      routeFetch(stored, stored);

      render(<UserProfile />);
      const textarea = await screen.findByLabelText('Email signature');

      expect(screen.queryByText(/unsaved changes/i)).not.toBeInTheDocument();

      fireEvent.change(textarea, { target: { value: 'Coach Smith' } });

      expect(screen.getByText(/unsaved changes/i)).toBeInTheDocument();
    });
  });
});
