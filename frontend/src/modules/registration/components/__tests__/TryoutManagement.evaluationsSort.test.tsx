/**
 * CKU report R85 — the tryout Evaluations tab was "one massive list".
 *
 * Covers the toolbar added above that list: the four sorts, the two filters,
 * and the localStorage round-trip. Everything here is client-side; the fetch
 * mock returns ONE fixed payload and never changes, so any difference in what
 * is rendered comes from the controls and nothing else.
 */
import React from 'react';
import { render, screen, fireEvent, cleanup, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import TryoutManagement from '../TryoutManagement';
import { ageGroup } from '../../../../utils/ageGroup';

jest.mock('../../../../contexts/OrgContext', () => ({
  useOrg: () => ({ currentClubId: 32, activeContext: null }),
}));

jest.mock('../EvaluationModal', () => ({
  __esModule: true,
  default: () => null,
}));

const SORT_KEY = 'te.tryoutEvaluations.sort';

// PDO hands back Postgres numerics and COUNT(*) as STRINGS. The fixture keeps
// them as strings on purpose — a sort that only works on real numbers is one of
// the things this guards.
const REGISTRATIONS = [
  {
    id: 1,
    athlete_id: 11,
    first_name: 'Zoe',
    last_name: 'Adams',
    date_of_birth: '2012-05-04',
    tryout_status: 'evaluated',
    tryout_number: '12',
    overall_score: '3.0',
    evaluation_count: '1',
  },
  {
    id: 2,
    athlete_id: 12,
    first_name: 'Ana',
    last_name: 'Baker',
    date_of_birth: '2014-03-02',
    tryout_status: 'evaluated',
    tryout_number: '3',
    overall_score: '4.8',
    evaluation_count: '2',
  },
  {
    id: 3,
    athlete_id: 13,
    first_name: 'Mia',
    last_name: 'Carter',
    date_of_birth: '2012-09-10',
    tryout_status: 'checked_in',
    tryout_number: '7',
    overall_score: null,
    evaluation_count: '0',
  },
];

const jsonResponse = (body: unknown) =>
  Promise.resolve({ ok: true, json: () => Promise.resolve(body) } as unknown as Response);

const fetchImpl = (input: unknown) => {
  const url = String(input);
  if (url.includes('path=registrations')) return jsonResponse(REGISTRATIONS);
  if (url.includes('path=sessions')) return jsonResponse([]);
  if (url.includes('path=criteria')) return jsonResponse([]);
  return jsonResponse([]);
};

/** Athlete names in the order the evaluations table renders them. */
const renderedNames = (): string[] =>
  screen
    .getAllByRole('row')
    .slice(1) // drop the header row
    .map(row => (within(row).getAllByRole('cell')[1].textContent || '').trim());

const openEvaluationsTab = async () => {
  render(
    <TryoutManagement
      programId={7}
      programName="Fall Tryouts"
      currentUserId={1}
      onClose={() => {}}
    />
  );
  await screen.findByText('Zoe Adams');
  fireEvent.click(screen.getByRole('button', { name: 'Evaluate' }));
  await screen.findByLabelText('Sort evaluations');
};

const sortSelect = () => screen.getByLabelText('Sort evaluations') as HTMLSelectElement;
const statusSelect = () => screen.getByLabelText('Filter evaluations by status');
const ageGroupSelect = () => screen.getByLabelText('Filter evaluations by age group');

describe('TryoutManagement evaluations toolbar', () => {
  beforeEach(() => {
    window.localStorage.clear();
    // CRA's jest config sets `resetMocks: true`, which wipes a mock's
    // implementation before every test — the fetch stub has to be rebuilt here,
    // not once at module scope, or every test after the first sees `undefined`.
    (global as any).fetch = jest.fn(fetchImpl);
  });

  it('defaults to name A-Z', async () => {
    await openEvaluationsTab();
    expect(renderedNames()).toEqual(['Zoe Adams', 'Ana Baker', 'Mia Carter']);
  });

  it('sorts by overall score high to low, with an unscored athlete last', async () => {
    await openEvaluationsTab();
    fireEvent.change(sortSelect(), { target: { value: 'score' } });
    expect(renderedNames()).toEqual(['Ana Baker', 'Zoe Adams', 'Mia Carter']);
  });

  it('sorts by tryout number numerically, not as text', async () => {
    await openEvaluationsTab();
    fireEvent.change(sortSelect(), { target: { value: 'tryout_number' } });
    // "12" must not sort before "3" — it would as a string.
    expect(renderedNames()).toEqual(['Ana Baker', 'Mia Carter', 'Zoe Adams']);
  });

  it('sorts by evaluation count high to low', async () => {
    await openEvaluationsTab();
    fireEvent.change(sortSelect(), { target: { value: 'evaluations' } });
    expect(renderedNames()).toEqual(['Ana Baker', 'Zoe Adams', 'Mia Carter']);
  });

  it('persists the chosen sort and restores it the next time the tab is opened', async () => {
    await openEvaluationsTab();
    fireEvent.change(sortSelect(), { target: { value: 'score' } });
    expect(window.localStorage.getItem(SORT_KEY)).toBe('score');

    cleanup();
    await openEvaluationsTab();
    expect(sortSelect().value).toBe('score');
    expect(renderedNames()).toEqual(['Ana Baker', 'Zoe Adams', 'Mia Carter']);
  });

  it('ignores a stored value that is not a sort we offer', async () => {
    window.localStorage.setItem(SORT_KEY, 'whatever');
    await openEvaluationsTab();
    expect(sortSelect().value).toBe('name');
    expect(renderedNames()).toEqual(['Zoe Adams', 'Ana Baker', 'Mia Carter']);
  });

  it('survives a localStorage that throws instead of answering', async () => {
    // Only the sort key throws — the component reads `auth_token` from the same
    // store, and breaking that would test the fetch path instead of this one.
    const realGet = Storage.prototype.getItem;
    const realSet = Storage.prototype.setItem;
    const get = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(function (
      this: Storage,
      key: string
    ) {
      if (key === SORT_KEY) throw new Error('site data blocked');
      return realGet.call(this, key);
    });
    const set = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(function (
      this: Storage,
      key: string,
      value: string
    ) {
      if (key === SORT_KEY) throw new Error('site data blocked');
      realSet.call(this, key, value);
    });
    try {
      await openEvaluationsTab();
      expect(sortSelect().value).toBe('name');
      fireEvent.change(sortSelect(), { target: { value: 'score' } });
      expect(renderedNames()).toEqual(['Ana Baker', 'Zoe Adams', 'Mia Carter']);
    } finally {
      get.mockRestore();
      set.mockRestore();
    }
  });

  it('the status filter hides rows', async () => {
    await openEvaluationsTab();
    fireEvent.change(statusSelect(), { target: { value: 'awaiting' } });
    expect(renderedNames()).toEqual(['Mia Carter']);
    expect(screen.queryByText('Ana Baker')).not.toBeInTheDocument();
    expect(screen.getByText('Showing 1 of 3')).toBeInTheDocument();

    fireEvent.change(statusSelect(), { target: { value: 'evaluated' } });
    expect(renderedNames()).toEqual(['Zoe Adams', 'Ana Baker']);
  });

  it('the age-group filter hides rows from other birth years', async () => {
    await openEvaluationsTab();
    // Derived from date_of_birth via utils/ageGroup, so the assertion does not
    // rot when the season year rolls over on Aug 1.
    const older = ageGroup('2012-05-04') as string;
    const younger = ageGroup('2014-03-02') as string;
    expect(older).not.toEqual(younger);

    fireEvent.change(ageGroupSelect(), { target: { value: younger } });
    expect(renderedNames()).toEqual(['Ana Baker']);

    fireEvent.change(ageGroupSelect(), { target: { value: older } });
    expect(renderedNames()).toEqual(['Zoe Adams', 'Mia Carter']);
  });

  it('keeps the toolbar on screen when the filters match nothing', async () => {
    await openEvaluationsTab();
    fireEvent.change(statusSelect(), { target: { value: 'awaiting' } });
    fireEvent.change(ageGroupSelect(), { target: { value: ageGroup('2014-03-02') as string } });
    expect(screen.getByText('No athletes match these filters.')).toBeInTheDocument();
    // The control that emptied the list must still be reachable.
    expect(statusSelect()).toBeInTheDocument();
  });

  it('keeps the per-row evaluate action', async () => {
    await openEvaluationsTab();
    // `COUNT(*)` arrives as a STRING, and "0" is truthy — an athlete nobody has
    // evaluated must still be offered "Evaluate", not "View/Edit".
    const unevaluated = screen.getByText('Mia Carter').closest('tr') as HTMLElement;
    expect(within(unevaluated).getByRole('button', { name: 'Evaluate' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'View/Edit' })).toHaveLength(2);
  });

  it('counts evaluations as a number, not as the string the API sends', async () => {
    await openEvaluationsTab();
    const singular = screen.getByText('Zoe Adams').closest('tr') as HTMLElement;
    expect(within(singular).getByText('1 evaluation')).toBeInTheDocument();
    const plural = screen.getByText('Ana Baker').closest('tr') as HTMLElement;
    expect(within(plural).getByText('2 evaluations')).toBeInTheDocument();
  });
});
