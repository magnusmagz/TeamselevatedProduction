import React, { useState, useEffect } from 'react';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface CommunicationHistoryProps {
  contactType: 'athlete' | 'guardian' | 'user';
  contactId: number;
  clubProfileId: number;
}

interface CommunicationEntry {
  id: number;
  channel: 'email' | 'sms';
  recipient_name: string;
  recipient_email?: string;
  recipient_phone?: string;
  subject?: string;
  body: string;
  status: 'queued' | 'sending' | 'sent' | 'delivered' | 'failed' | 'bounced' | 'rejected';
  sender_first_name: string;
  sender_last_name: string;
  open_count: number;
  click_count: number;
  created_at: string;
  sent_at: string;
  delivered_at: string;
}

const STATUS_COLORS: Record<string, string> = {
  queued: 'bg-gray-100 text-gray-700',
  sending: 'bg-yellow-100 text-yellow-700',
  sent: 'bg-blue-100 text-blue-700',
  delivered: 'bg-green-100 text-green-700',
  failed: 'bg-red-100 text-red-700',
  bounced: 'bg-orange-100 text-orange-700',
  rejected: 'bg-red-100 text-red-700',
};

function formatDateTime(dateStr: string): string {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export const CommunicationHistory: React.FC<CommunicationHistoryProps> = ({
  contactType,
  contactId,
  clubProfileId,
}) => {
  const [entries, setEntries] = useState<CommunicationEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [expandedId, setExpandedId] = useState<number | null>(null);

  // Compose modals
  const [showEmailCompose, setShowEmailCompose] = useState(false);
  const [showSmsCompose, setShowSmsCompose] = useState(false);

  // Lazy-loaded compose components
  const [EmailCompose, setEmailCompose] = useState<React.FC<any> | null>(null);
  const [SmsCompose, setSmsCompose] = useState<React.FC<any> | null>(null);

  useEffect(() => {
    if (showEmailCompose && !EmailCompose) {
      import('./EmailCompose').then((mod) => {
        setEmailCompose(() => mod.default || mod.EmailCompose);
      });
    }
  }, [showEmailCompose, EmailCompose]);

  useEffect(() => {
    if (showSmsCompose && !SmsCompose) {
      import('./SmsCompose').then((mod) => {
        setSmsCompose(() => mod.default || mod.SmsCompose);
      });
    }
  }, [showSmsCompose, SmsCompose]);

  const fetchHistory = () => {
    setLoading(true);
    const token = localStorage.getItem('auth_token');

    fetch(
      `${API_URL}/api/communications.php?action=contact-history&contact_type=${contactType}&contact_id=${contactId}&club_profile_id=${clubProfileId}`,
      {
        headers: { Authorization: `Bearer ${token}` },
      }
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.success) {
          setEntries(data.entries || data.data || []);
        }
      })
      .catch((err) => console.error('Error fetching communication history:', err))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (contactId && clubProfileId) {
      fetchHistory();
    }
  }, [contactType, contactId, clubProfileId]);

  const handleComposeSent = () => {
    setShowEmailCompose(false);
    setShowSmsCompose(false);
    fetchHistory();
  };

  const toggleExpand = (id: number) => {
    setExpandedId(expandedId === id ? null : id);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <svg
          className="animate-spin h-8 w-8 text-brand-primary"
          xmlns="http://www.w3.org/2000/svg"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path
            className="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
          />
        </svg>
      </div>
    );
  }

  return (
    <div>
      {/* Quick action buttons */}
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold text-gray-900">Communications</h3>
        <div className="flex gap-2">
          <button
            onClick={() => setShowEmailCompose(true)}
            className="inline-flex items-center px-3 py-1.5 bg-brand-primary text-white text-sm font-medium rounded-lg hover:opacity-90 transition-opacity"
          >
            <span className="mr-1.5"><svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></span>
            Send Email
          </button>
          <button
            onClick={() => setShowSmsCompose(true)}
            className="inline-flex items-center px-3 py-1.5 bg-white text-brand-primary text-sm font-medium rounded-lg border border-brand-primary hover:bg-gray-50 transition-colors"
          >
            <span className="mr-1.5"><svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg></span>
            Send SMS
          </button>
        </div>
      </div>

      {/* Empty state */}
      {entries.length === 0 ? (
        <div className="text-center py-12 bg-gray-50 rounded-xl border border-gray-200">
          <div className="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <span className="text-xl">📭</span>
          </div>
          <h4 className="text-base font-medium text-gray-900 mb-1">No communications sent to this contact yet</h4>
          <p className="text-sm text-gray-500">Use the buttons above to send an email or SMS</p>
        </div>
      ) : (
        /* Communication entries list */
        <div className="space-y-3">
          {entries.map((entry) => {
            const isExpanded = expandedId === entry.id;
            return (
              <div
                key={entry.id}
                className="bg-white border border-gray-200 rounded-xl overflow-hidden transition-shadow hover:shadow-sm"
              >
                {/* Entry header (clickable) */}
                <button
                  onClick={() => toggleExpand(entry.id)}
                  className="w-full text-left px-4 py-3 flex items-start gap-3"
                >
                  {/* Channel badge */}
                  <span
                    className={`mt-0.5 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 ${
                      entry.channel === 'email'
                        ? 'bg-blue-50 text-blue-700'
                        : 'bg-purple-50 text-purple-700'
                    }`}
                  >
                    {entry.channel === 'email' ? '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg> Email' : '<svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg> SMS'}
                  </span>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-sm font-medium text-gray-900 truncate">
                        {entry.channel === 'email'
                          ? entry.subject || '(no subject)'
                          : entry.body?.substring(0, 80) + (entry.body?.length > 80 ? '...' : '')}
                      </p>
                      <span className="text-xs text-gray-400 whitespace-nowrap flex-shrink-0">
                        {formatDateTime(entry.created_at)}
                      </span>
                    </div>
                    <div className="flex items-center gap-3 mt-1">
                      <span className="text-xs text-gray-500">
                        From: {entry.sender_first_name} {entry.sender_last_name}
                      </span>
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize ${
                          STATUS_COLORS[entry.status] || 'bg-gray-100 text-gray-700'
                        }`}
                      >
                        {entry.status}
                      </span>
                      {entry.channel === 'email' && (
                        <span className="text-xs text-gray-400">
                          {entry.open_count > 0 && `${entry.open_count} open${entry.open_count !== 1 ? 's' : ''}`}
                          {entry.open_count > 0 && entry.click_count > 0 && ' · '}
                          {entry.click_count > 0 && `${entry.click_count} click${entry.click_count !== 1 ? 's' : ''}`}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Expand indicator */}
                  <svg
                    className={`w-4 h-4 text-gray-400 flex-shrink-0 mt-1 transition-transform ${
                      isExpanded ? 'rotate-180' : ''
                    }`}
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                  </svg>
                </button>

                {/* Expanded body */}
                {isExpanded && (
                  <div className="border-t border-gray-100 px-4 py-4">
                    {/* Metadata */}
                    <div className="grid grid-cols-2 gap-4 mb-4 text-sm">
                      <div>
                        <span className="text-gray-500">Sent:</span>{' '}
                        <span className="text-gray-900">{entry.sent_at ? formatDateTime(entry.sent_at) : '—'}</span>
                      </div>
                      <div>
                        <span className="text-gray-500">Delivered:</span>{' '}
                        <span className="text-gray-900">
                          {entry.delivered_at ? formatDateTime(entry.delivered_at) : '—'}
                        </span>
                      </div>
                      {entry.channel === 'email' && (
                        <>
                          <div>
                            <span className="text-gray-500">Opens:</span>{' '}
                            <span className="text-gray-900">{entry.open_count}</span>
                          </div>
                          <div>
                            <span className="text-gray-500">Clicks:</span>{' '}
                            <span className="text-gray-900">{entry.click_count}</span>
                          </div>
                        </>
                      )}
                    </div>

                    {/* Body content */}
                    <div>
                      <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                        {entry.channel === 'email' ? 'Email Body' : 'Message'}
                      </label>
                      {entry.channel === 'email' ? (
                        <div className="border border-gray-200 rounded-lg overflow-hidden bg-white">
                          <iframe
                            title={`Email body ${entry.id}`}
                            srcDoc={entry.body}
                            className="w-full min-h-[200px]"
                            sandbox="allow-same-origin"
                            style={{ border: 'none' }}
                          />
                        </div>
                      ) : (
                        <div className="bg-gray-50 rounded-lg p-4 text-sm text-gray-800 whitespace-pre-wrap">
                          {entry.body}
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Email Compose Modal */}
      {showEmailCompose && EmailCompose && (
        <EmailCompose
          isOpen={showEmailCompose}
          onClose={() => {
            setShowEmailCompose(false);
            handleComposeSent();
          }}
          clubProfileId={clubProfileId}
          preselectedRecipients={[
            { id: contactId, type: contactType as 'athlete' | 'guardian' | 'coach', first_name: '', last_name: '', suppressed: false },
          ]}
        />
      )}

      {/* SMS Compose Modal */}
      {showSmsCompose && SmsCompose && (
        <SmsCompose
          isOpen={showSmsCompose}
          onClose={() => {
            setShowSmsCompose(false);
            handleComposeSent();
          }}
          clubProfileId={clubProfileId}
          preselectedRecipients={[
            { id: contactId, type: contactType as 'athlete' | 'guardian' | 'coach', first_name: '', last_name: '', suppressed: false },
          ]}
        />
      )}
    </div>
  );
};

export default CommunicationHistory;
