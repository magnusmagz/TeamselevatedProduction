import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import PublicPageSettings, { SLUG_HELP } from './PublicPageSettings';

function renderIt(over: Partial<React.ComponentProps<typeof PublicPageSettings>> = {}) {
  const onSave = jest.fn(async () => null as string | null);
  const utils = render(
    <MemoryRouter>
      <PublicPageSettings
        profile={{ slug: 'central-kansas-united', public_page_enabled: true, public_page_tagline: '' }}
        clubName="Central Kansas United"
        saving={false}
        onSave={onSave}
        {...over}
      />
    </MemoryRouter>
  );
  return { ...utils, onSave };
}

describe('PublicPageSettings', () => {
  it('shows the link, the feed URL and a QR code for the slug', () => {
    renderIt();
    expect(screen.getByDisplayValue('central-kansas-united')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open page/i })).toHaveAttribute('href', expect.stringMatching(/\/club\/central-kansas-united$/));
    expect(screen.getByDisplayValue(/action=ics&slug=central-kansas-united/)).toBeInTheDocument();
    expect(screen.getByTestId('public-page-qr').querySelector('svg')).not.toBeNull();
  });

  it('normalises what is typed into the slug and refuses an invalid one before saving', async () => {
    const { onSave } = renderIt();
    const input = screen.getByLabelText(/your link/i);
    fireEvent.change(input, { target: { value: 'Central Kansas!' } });
    expect(input).toHaveValue('centralkansas');
    fireEvent.change(input, { target: { value: 'ab' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(SLUG_HELP);
    expect(onSave).not.toHaveBeenCalled();
  });

  it('saves the switch, slug and tagline together and reports the server refusal', async () => {
    const onSave = jest.fn(async () => 'That link is already taken by another club.');
    renderIt({ onSave });
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.change(screen.getByLabelText(/tagline/i), { target: { value: 'Youth soccer since 2014' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    await waitFor(() => expect(onSave).toHaveBeenCalledWith({
      slug: 'central-kansas-united', public_page_enabled: false, public_page_tagline: 'Youth soccer since 2014',
    }));
    expect(await screen.findByRole('alert')).toHaveTextContent('already taken');
  });

  it('greys out the switch and tagline while the migration is pending', () => {
    renderIt({ profile: { slug: 'x-fc', public_page_enabled: true, public_page_migration_pending: true } });
    expect(screen.getByRole('switch')).toBeDisabled();
    expect(screen.getByLabelText(/tagline/i)).toBeDisabled();
    expect(screen.getByText(/next database update/i)).toBeInTheDocument();
  });
});
