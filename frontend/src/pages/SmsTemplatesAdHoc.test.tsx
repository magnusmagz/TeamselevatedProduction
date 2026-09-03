import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import SmsTemplates from './SmsTemplates';

const mockFetch = jest.fn();
global.fetch = mockFetch;

jest.mock('../contexts/OrgContext', () => ({
  useOrg: () => ({ activeContext: { scope_id: 51, scope_type: 'club' } }),
}));
jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, system_role: 'club_admin', activeRole: { role: 'club_admin' } } }),
}));

// The compose modal is lazy-loaded; stub it so the test is about the door, not
// the room behind it.
jest.mock('../components/communications/SmsCompose', () => ({
  SmsCompose: ({ isOpen, preselectedTemplate }: any) =>
    isOpen ? <div>compose open: {preselectedTemplate ? preselectedTemplate.name : 'no template'}</div> : null,
}));

const templates = [
  { id: 5, name: 'Practice Reminder', body_text: 'See you at practice', category: 'practice', scope: 'club', channel: 'sms' },
];

describe('SMS Templates — sending without a template', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockFetch.mockResolvedValue({ ok: true, json: async () => ({ success: true, templates, merge_fields: [] }) });
  });

  test('the free-form CTA opens compose with nothing preloaded', async () => {
    render(<SmsTemplates />);
    fireEvent.click(await screen.findByText('Send a message without a template'));
    await waitFor(() => expect(screen.getByText(/compose open: no template/)).toBeInTheDocument());
  });

  test("a template's Send button still preloads that template", async () => {
    render(<SmsTemplates />);
    await waitFor(() => expect(screen.getByText('Practice Reminder')).toBeInTheDocument());
    fireEvent.click(screen.getAllByTitle('Send this template to recipients')[0]);
    await waitFor(() => expect(screen.getByText(/compose open: Practice Reminder/)).toBeInTheDocument());
  });
});
