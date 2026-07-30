import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MedicalInfoPage } from '../pages/MedicalInfoPage';

jest.mock('react-router-dom', () => ({ useParams: () => ({ id: '7' }) }));
jest.mock('../components/ParentHeader', () => ({
  ParentHeader: ({ title }: { title: string }) => <header>{title}</header>,
}));
jest.mock('../../contexts/FinancialPermissionsContext', () => ({
  useFinancialPermissions: jest.fn(),
}));

import { useFinancialPermissions } from '../../contexts/FinancialPermissionsContext';
const mockPerms = useFinancialPermissions as jest.MockedFunction<typeof useFinancialPermissions>;

const medicalRecord = {
  athlete_id: 7,
  exists: true,
  allergies: 'Peanuts',
  medications: 'Inhaler as needed',
  blood_type: 'O+',
  has_asthma: true,
  inhaler_location: 'Kit bag',
  return_to_play_date: '2026-08-15',
};

describe('MedicalInfoPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    localStorage.setItem('auth_token', 'test-token');
    mockPerms.mockReturnValue({ accessibleAthleteIds: [7], loading: false } as any);
  });

  /**
   * THE REGRESSION. This page used to read allergies/medications/blood_type off
   * `api/athletes/?action=get` — the `athletes` row, which has none of those
   * columns. Every value came back undefined and the old renderer hid empty
   * fields, so the page looked like an athlete with nothing on file instead of
   * looking broken. The record only exists in athlete_medical, and only
   * medical-gateway decrypts it.
   */
  test('reads the record from medical-gateway, not from the athletes endpoint', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, medical: medicalRecord }),
    }) as any;

    render(<MedicalInfoPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    const urls = (global.fetch as jest.Mock).mock.calls.map(([u]) => String(u));

    expect(urls.some((u) => u.includes('medical-gateway.php') && u.includes('athlete_id=7'))).toBe(true);
    expect(urls.some((u) => u.includes('/api/athletes/'))).toBe(false);

    expect(await screen.findByText('Peanuts')).toBeInTheDocument();
    expect(screen.getByText('O+')).toBeInTheDocument();
  });

  test('shows "Not provided" rather than hiding empty fields', async () => {
    global.fetch = jest.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, medical: { athlete_id: 7, exists: false } }),
    }) as any;

    render(<MedicalInfoPage />);

    // A blank record must read as blank, not as a page that failed to render.
    expect(await screen.findByText('Nothing on file yet.')).toBeInTheDocument();
    expect(screen.getAllByText('Not provided').length).toBeGreaterThan(0);
  });

  test('saves only the crew-editable keys, leaving clinical fields untouched', async () => {
    global.fetch = jest.fn().mockImplementation((_url: string, opts?: RequestInit) =>
      Promise.resolve({
        ok: true,
        json: async () =>
          opts?.method === 'POST'
            ? { success: true, message: 'Medical information updated' }
            : { success: true, medical: medicalRecord },
      })
    ) as any;

    render(<MedicalInfoPage />);
    fireEvent.click(await screen.findByText('Edit'));

    fireEvent.change(screen.getByLabelText('Allergies'), {
      target: { value: 'Peanuts, shellfish' },
    });
    fireEvent.click(screen.getByText('Save'));

    await waitFor(() => {
      const post = (global.fetch as jest.Mock).mock.calls.find(
        ([, o]) => o?.method === 'POST'
      );
      expect(post).toBeDefined();
      const body = JSON.parse(post[1].body);

      expect(body.athlete_id).toBe(7);
      expect(body.allergies).toBe('Peanuts, shellfish');

      // Clinical determinations are not the family's to change. Omitting the key
      // is what protects them — the gateway binds on array_key_exists.
      expect(body).not.toHaveProperty('return_to_play_date');
      expect(body).not.toHaveProperty('concussion_history');
      expect(body).not.toHaveProperty('last_concussion_date');
    });
  });

  test('a failed save surfaces the error and stays in the editor', async () => {
    global.fetch = jest.fn().mockImplementation((_url: string, opts?: RequestInit) =>
      Promise.resolve({
        ok: opts?.method !== 'POST',
        json: async () =>
          opts?.method === 'POST'
            ? { success: false, error: 'Medical information could not be saved' }
            : { success: true, medical: medicalRecord },
      })
    ) as any;

    render(<MedicalInfoPage />);
    fireEvent.click(await screen.findByText('Edit'));
    fireEvent.click(screen.getByText('Save'));

    expect(
      await screen.findByText('Medical information could not be saved')
    ).toBeInTheDocument();
    expect(screen.getByText('Save')).toBeInTheDocument(); // still editing
  });
});
