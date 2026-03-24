import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useParentAthletes } from './hooks/useParentAthletes';
import { ParentHeader } from './components/ParentHeader';
import { AthleteSelector } from './components/AthleteSelector';
import {
  DashboardCard,
  QuickAction,
  AthleteCard,
  PaymentAlert,
  EventItem,
  AnnouncementItem,
} from './components/DashboardCard';

interface Invoice {
  id: number;
  athlete_id: number;
  athlete_name: string;
  total_amount: number;
  paid_amount: number;
  balance_due: number;
  due_date?: string;
  status: string;
}

interface Event {
  id: number;
  title: string;
  date: string;
  time?: string;
  type?: string;
  location?: string;
  team_id?: number;
}

interface Announcement {
  id: number;
  title: string;
  message: string;
  created_at: string;
  team_name?: string;
}

export const ParentDashboard: React.FC = () => {
  const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';
  const { user } = useAuth();
  const { athletes, loading: athletesLoading, selectedAthleteId, selectAthlete } = useParentAthletes();

  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [events, setEvents] = useState<Event[]>([]);
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [dataLoading, setDataLoading] = useState(true);

  // Calculate outstanding balance
  const outstandingBalance = invoices
    .filter((inv) => inv.status !== 'paid')
    .reduce((sum, inv) => sum + inv.balance_due, 0);

  // Get upcoming due date
  const upcomingDueDate = invoices
    .filter((inv) => inv.status !== 'paid' && inv.due_date)
    .sort((a, b) => new Date(a.due_date!).getTime() - new Date(b.due_date!).getTime())[0]?.due_date;

  useEffect(() => {
    const fetchData = async () => {
      if (!user) return;

      setDataLoading(true);
      const token = localStorage.getItem('auth_token');

      try {
        // Fetch invoices
        const invoicesRes = await fetch(
          `${API_URL}/api/invoices.php?action=family`,
          {
            headers: { Authorization: `Bearer ${token}` },
          }
        );
        const invoicesData = await invoicesRes.json();
        if (invoicesData.success && invoicesData.invoices) {
          const mapped = invoicesData.invoices.map((inv: Record<string, unknown>) => ({
            id: inv.id,
            athlete_id: inv.athlete_id,
            athlete_name: `${inv.athlete_first || ''} ${inv.athlete_last || ''}`.trim(),
            total_amount: parseFloat(String(inv.total_amount || 0)),
            paid_amount: parseFloat(String(inv.amount_paid || 0)),
            balance_due: parseFloat(String(inv.amount_remaining || 0)),
            due_date: inv.due_date as string | undefined,
            status: inv.is_overdue ? 'overdue' : (inv.status as string),
          }));
          setInvoices(mapped);
        }
      } catch (err) {
        console.error('Error fetching invoices:', err);
      }

      try {
        // Fetch upcoming events
        const eventsRes = await fetch(`${API_URL}/api/events.php?action=upcoming&limit=5`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const eventsData = await eventsRes.json();
        if (eventsData.success && eventsData.events) {
          setEvents(eventsData.events);
        }
      } catch (err) {
        console.error('Error fetching events:', err);
      }

      try {
        // Fetch announcements
        const announcementsRes = await fetch(`${API_URL}/api/announcements.php?action=list&limit=3`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const announcementsData = await announcementsRes.json();
        if (announcementsData.success && announcementsData.announcements) {
          setAnnouncements(announcementsData.announcements);
        }
      } catch (err) {
        console.error('Error fetching announcements:', err);
      }

      setDataLoading(false);
    };

    fetchData();
  }, [API_URL, user]);

  const loading = athletesLoading || dataLoading;

  return (
    <div className="min-h-screen bg-gray-50">
      <ParentHeader
        rightElement={
          athletes.length > 1 ? (
            <AthleteSelector
              athletes={athletes}
              selectedAthleteId={selectedAthleteId}
              onSelect={selectAthlete}
              showAllOption={true}
            />
          ) : undefined
        }
      />

      <div className="pt-14 px-4 pb-4 space-y-4">
        {/* Welcome message */}
        <div className="pt-4">
          <h1 className="text-2xl font-bold text-brand-primary">
            Welcome, {user?.name?.split(' ')[0]}!
          </h1>
          <p className="text-gray-600 mt-1">Here's what's happening with your athletes.</p>
        </div>

        {/* Quick Actions */}
        <div className="grid grid-cols-2 gap-3">
          <QuickAction
            icon={
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
              </svg>
            }
            label="Make Payment"
            to="/parent/payments"
            variant="primary"
          />
          <QuickAction
            icon={
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            }
            label="View Schedule"
            to="/parent/schedule"
            variant="secondary"
          />
        </div>

        {/* Outstanding Payment Alert */}
        {outstandingBalance > 0 && (
          <PaymentAlert
            amount={outstandingBalance}
            dueDate={
              upcomingDueDate
                ? new Date(upcomingDueDate).toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                  })
                : undefined
            }
          />
        )}

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center py-8">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-primary"></div>
          </div>
        )}

        {/* Athletes Section */}
        {!loading && athletes.length > 0 && (
          <DashboardCard
            title="My Athletes"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            }
            headerAction={
              <Link to="/parent/athletes" className="text-sm text-brand-primary hover:underline">
                View All
              </Link>
            }
          >
            <div className="space-y-2">
              {athletes.slice(0, 3).map((athlete) => (
                <AthleteCard key={athlete.id} athlete={athlete} to={`/parent/athlete/${athlete.id}`} />
              ))}
            </div>
          </DashboardCard>
        )}

        {/* Upcoming Events */}
        {!loading && events.length > 0 && (
          <DashboardCard
            title="Upcoming Events"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            }
            headerAction={
              <Link to="/parent/schedule" className="text-sm text-brand-primary hover:underline">
                Full Schedule
              </Link>
            }
          >
            <div>
              {events.slice(0, 5).map((event) => (
                <EventItem key={event.id} event={event} />
              ))}
            </div>
          </DashboardCard>
        )}

        {/* Announcements */}
        {!loading && announcements.length > 0 && (
          <DashboardCard
            title="Announcements"
            icon={
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
              </svg>
            }
            headerAction={
              <Link to="/parent/announcements" className="text-sm text-brand-primary hover:underline">
                View All
              </Link>
            }
          >
            <div>
              {announcements.slice(0, 3).map((announcement) => (
                <AnnouncementItem key={announcement.id} announcement={announcement} />
              ))}
            </div>
          </DashboardCard>
        )}

        {/* Empty state when no athletes */}
        {!loading && athletes.length === 0 && (
          <div className="text-center py-12">
            <svg
              className="mx-auto h-12 w-12 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
              />
            </svg>
            <h3 className="mt-2 text-lg font-medium text-brand-primary">No Athletes Found</h3>
            <p className="mt-1 text-sm text-gray-500">
              You don't have any athletes linked to your account yet.
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

export default ParentDashboard;
