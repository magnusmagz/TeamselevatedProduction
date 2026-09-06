import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import { ComplianceRequirements } from '../../pages/ComplianceRequirements';

/**
 * The requirement builder (GOTR G4).
 *
 * What matters here is what leaves the browser: an inherited rule must not be
 * editable, a document requirement must carry the storage note, and the save
 * body must be the shape api/compliance-gateway.php validates — a wrong key
 * name is a 400 the admin reads as "it just does not save".
 */

jest.mock('../../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 100, isClubAdmin: true }),
}));

const requirements = [
  {
    id: 10,
    org_unit_id: 1,
    club_profile_id: null,
    kind: 'training',
    name: 'SafeSport',
    description: null,
    proof: 'document',
    proof_url: null,
    validity_days: 365,
    required: true,
    active: true,
    sort_order: 1,
    roles: [],
    origin: { scope: 'national', name: 'Girls on the Run', label: 'National — Girls on the Run', editable: false },
  },
  {
    id: 13,
    org_unit_id: null,
    club_profile_id: 100,
    kind: 'custom',
    name: 'Council parking pass',
    description: null,
    proof: 'attested_date',
    proof_url: null,
    validity_days: null,
    required: false,
    active: true,
    sort_order: 4,
    roles: ['volunteer'],
    origin: { scope: 'club', name: null, label: 'This club', editable: true },
  },
];

const vocabulary = {
  kinds: ['background_check', 'cpr_first_aid', 'training', 'document', 'custom'],
  proofs: ['document', 'attested_date', 'external_link'],
  roles: ['head_coach', 'junior_coach', 'team_helper', 'volunteer', 'coach', 'club_admin'],
};

const ok = (body: unknown) => ({ ok: true, status: 200, json: async () => body });

describe('ComplianceRequirements', () => {
  beforeEach(() => {
    localStorage.setItem('auth_token', 'tok');
    global.fetch = jest.fn(async (url: any) => {
      if (String(url).includes('action=requirements')) {
        return ok({ success: true, available: true, requirements, vocabulary });
      }
      return ok({ success: true, id: 99 });
    }) as any;
  });

  test('inherited rules are shown with their origin and cannot be edited here', async () => {
    render(<ComplianceRequirements />);

    await waitFor(() => expect(screen.getByTestId('inherited-10')).toBeInTheDocument());

    // The council admin has to SEE national's rule, or they add a fourth copy.
    const inherited = screen.getByTestId('inherited-10');
    expect(inherited).toHaveTextContent('SafeSport');
    expect(inherited).toHaveTextContent('National — Girls on the Run');
    // No Edit / Remove for a rule this club does not own. (The "Reminder
    // stream" toggle IS there — the cadence for this club's people is this
    // club's to write even when the rule is national's.)
    expect(inherited).not.toHaveTextContent('Edit');
    expect(inherited).not.toHaveTextContent('Remove');
    expect(inherited).toHaveTextContent('Reminder stream');

    // Their own is editable.
    const own = screen.getByTestId('own-13');
    expect(own).toHaveTextContent('This club');
    expect(own).toHaveTextContent('Edit');
  });

  test('an empty role list reads as "everyone", never as a blank', async () => {
    render(<ComplianceRequirements />);
    await waitFor(() => expect(screen.getByTestId('inherited-10')).toBeInTheDocument());
    // Rendering nothing there would read as "applies to nobody" — the opposite.
    expect(screen.getByTestId('inherited-10')).toHaveTextContent('everyone');
  });

  test('choosing the document proof type shows the storage note', async () => {
    render(<ComplianceRequirements />);
    await waitFor(() => expect(screen.getByText('Add requirement')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Add requirement'));
    expect(screen.queryByTestId('document-storage-note')).toBeNull();

    fireEvent.change(screen.getByLabelText('What counts as proof'), {
      target: { value: 'document' },
    });
    expect(screen.getByTestId('document-storage-note')).toHaveTextContent(
      /uploads arrive with durable storage/i
    );
  });

  test('saving a new requirement posts the shape the gateway validates', async () => {
    render(<ComplianceRequirements />);
    await waitFor(() => expect(screen.getByText('Add requirement')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Add requirement'));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'State background check' } });
    fireEvent.change(screen.getByLabelText('Category'), { target: { value: 'background_check' } });
    fireEvent.change(screen.getByLabelText(/Valid for /), { target: { value: '730' } });
    fireEvent.click(screen.getByRole('button', { name: 'Head coach' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
      expect(
        (global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('requirement-save'))
      ).toBe(true)
    );

    const call = (global.fetch as jest.Mock).mock.calls.find((c) =>
      String(c[0]).includes('requirement-save')
    );
    const body = JSON.parse(call[1].body);

    expect(body).toMatchObject({
      name: 'State background check',
      kind: 'background_check',
      proof: 'attested_date',
      validity_days: 730,
      roles: ['head_coach'],
      required: true,
      active: true,
      // Only ever sent on a CREATE. On an update the owner comes from the
      // stored row, so a club cannot re-home somebody else's rule.
      club_profile_id: 100,
    });
    expect(body.id).toBeUndefined();
    // proof is attested_date, so no stale URL rides along.
    expect(body.proof_url).toBeNull();
  });

  test('a blank validity means "never expires", not zero days', async () => {
    render(<ComplianceRequirements />);
    await waitFor(() => expect(screen.getByText('Add requirement')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Add requirement'));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Concussion protocol' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() =>
      expect(
        (global.fetch as jest.Mock).mock.calls.some((c) => String(c[0]).includes('requirement-save'))
      ).toBe(true)
    );

    const call = (global.fetch as jest.Mock).mock.calls.find((c) =>
      String(c[0]).includes('requirement-save')
    );
    expect(JSON.parse(call[1].body).validity_days).toBeNull();
  });

  test('an unapplied migration says so instead of showing an empty list', async () => {
    (global.fetch as jest.Mock).mockImplementation(async () =>
      ok({ success: true, available: false, requirements: [], vocabulary })
    );

    render(<ComplianceRequirements />);

    // An empty list would read as "this club has no requirements", which is a
    // different and wrong fact.
    await waitFor(() =>
      expect(screen.getByText(/not switched on for this database yet/i)).toBeInTheDocument()
    );
  });
});
