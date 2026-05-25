import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AnnouncementItem, EventItem, AthleteCard, PaymentAlert } from '../components/DashboardCard';

// PAR-10: each dashboard quick action must open the correct detail target.
describe('Dashboard quick-action nav targets', () => {
  test('AnnouncementItem links to the announcement detail route, not the list', () => {
    render(
      <MemoryRouter>
        <AnnouncementItem
          announcement={{
            id: 42,
            title: 'Team Meeting',
            message: 'Meeting next week',
            created_at: new Date().toISOString(),
          }}
        />
      </MemoryRouter>
    );

    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/parent/announcements/42');
    // Must NOT point at the bare list route.
    expect(link).not.toHaveAttribute('href', '/parent/announcements');
  });

  test('EventItem links to the schedule RSVP detail route with the real event id', () => {
    render(
      <MemoryRouter>
        <EventItem
          event={{
            id: 7,
            title: 'Practice',
            date: '2026-06-01',
            time: '16:00',
            location: 'Main Field',
          }}
        />
      </MemoryRouter>
    );

    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('href', '/parent/schedule/rsvp/7');
  });

  test('AthleteCard links to the athlete detail route', () => {
    render(
      <MemoryRouter>
        <AthleteCard
          athlete={{ id: 3, first_name: 'John', last_name: 'Doe' }}
          to="/parent/athlete/3"
        />
      </MemoryRouter>
    );

    expect(screen.getByRole('link')).toHaveAttribute('href', '/parent/athlete/3');
  });

  test('PaymentAlert links to the payments page', () => {
    render(
      <MemoryRouter>
        <PaymentAlert amount={300} dueDate="Feb 15" />
      </MemoryRouter>
    );

    expect(screen.getByRole('link')).toHaveAttribute('href', '/parent/payments');
  });
});
