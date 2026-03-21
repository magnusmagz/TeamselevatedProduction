import React, { useState, useEffect, useMemo } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { RecipientSelector } from './RecipientSelector';

const API_URL = process.env.REACT_APP_API_URL || 'http://localhost:8889';

interface Recipient {
  id: number;
  type: 'athlete' | 'guardian' | 'coach';
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string;
  team_name?: string;
  athlete_name?: string;
  athlete_id?: number;
  suppressed: boolean;
  suppression_reason?: string;
}

interface TeamGroup {
  id: number;
  name: string;
  age_group?: string;
  athlete_count: number;
  guardian_count: number;
}

interface SmsComposeProps {
  isOpen: boolean;
  onClose: () => void;
  clubProfileId: number;
  preselectedRecipients?: Recipient[];
}

type SendStatus = 'idle' | 'sending' | 'success' | 'error';

const SMS_SEGMENT_LENGTH = 160;
const SMS_CONCAT_SEGMENT_LENGTH = 153; // concatenated SMS segments use 7 bytes for header

export const SmsCompose: React.FC<SmsComposeProps> = ({
  isOpen,
  onClose,
  clubProfileId,
  preselectedRecipients,
}) => {
  const { user } = useAuth();

  const [recipients, setRecipients] = useState<Recipient[]>(preselectedRecipients || []);
  const [message, setMessage] = useState('');
  const [sendStatus, setSendStatus] = useState<SendStatus>('idle');
  const [toastVisible, setToastVisible] = useState(false);
  const [toastMessage, setToastMessage] = useState('');
  const [toastType, setToastType] = useState<'success' | 'error'>('success');

  const token = localStorage.getItem('auth_token');
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };

  useEffect(() => {
    if (preselectedRecipients) setRecipients(preselectedRecipients);
  }, [preselectedRecipients]);

  // Character and segment counting
  const charCount = message.length;
  const segmentCount = useMemo(() => {
    if (charCount === 0) return 0;
    if (charCount <= SMS_SEGMENT_LENGTH) return 1;
    return Math.ceil(charCount / SMS_CONCAT_SEGMENT_LENGTH);
  }, [charCount]);

  // Recipient analysis
  const activeRecipients = useMemo(
    () => recipients.filter((r) => !r.suppressed),
    [recipients]
  );
  const recipientsWithPhone = useMemo(
    () => activeRecipients.filter((r) => r.phone),
    [activeRecipients]
  );
  const recipientsWithoutPhone = useMemo(
    () => activeRecipients.filter((r) => !r.phone),
    [activeRecipients]
  );
  const suppressedCount = recipients.filter((r) => r.suppressed).length;

  const showToast = (msg: string, type: 'success' | 'error') => {
    setToastMessage(msg);
    setToastType(type);
    setToastVisible(true);
    setTimeout(() => setToastVisible(false), 4000);
  };

  const handleSend = async () => {
    if (recipientsWithPhone.length === 0) {
      showToast('No recipients with valid phone numbers.', 'error');
      return;
    }
    if (!message.trim()) {
      showToast('Message body is required.', 'error');
      return;
    }

    setSendStatus('sending');

    const isBroadcast = recipientsWithPhone.length > 1;
    const action = isBroadcast ? 'send-broadcast' : 'send-sms';

    try {
      const res = await fetch(`${API_URL}/api/communications?action=${action}`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          channel: 'sms',
          recipients: recipientsWithPhone.map((r) => ({
            id: r.id,
            type: r.type,
            phone: r.phone,
            name: `${r.first_name} ${r.last_name}`,
            athlete_id: r.athlete_id || null,
          })),
          body: message,
        }),
      });

      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}));
        throw new Error(errorData.error || 'Failed to send SMS');
      }

      setSendStatus('success');
      showToast(
        `SMS queued for ${recipientsWithPhone.length} recipient${recipientsWithPhone.length > 1 ? 's' : ''}.`,
        'success'
      );

      setTimeout(() => {
        resetForm();
        onClose();
      }, 2000);
    } catch (err: any) {
      setSendStatus('error');
      showToast(err.message || 'Failed to send SMS. Please try again.', 'error');
    }
  };

  const resetForm = () => {
    setRecipients([]);
    setMessage('');
    setSendStatus('idle');
  };

  // Character count color
  const getCharCountColor = () => {
    if (charCount === 0) return 'text-gray-400';
    if (charCount <= SMS_SEGMENT_LENGTH) return 'text-gray-500';
    if (charCount <= SMS_SEGMENT_LENGTH * 2) return 'text-amber-600';
    return 'text-red-600';
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

      {/* Modal */}
      <div className="relative w-full max-w-2xl max-h-[90vh] mx-4 bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center">
              <svg className="w-4 h-4 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
              </svg>
            </div>
            <h2 className="text-lg font-semibold text-gray-900">Compose SMS</h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
            aria-label="Close"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
          {/* Recipient selector */}
          <RecipientSelector
            clubProfileId={clubProfileId}
            channel="sms"
            selectedRecipients={recipients}
            onRecipientsChange={setRecipients}
          />

          {/* Warning: recipients without phone */}
          {recipientsWithoutPhone.length > 0 && (
            <div className="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 flex items-start gap-2">
              <svg className="w-4 h-4 text-amber-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div className="text-sm text-amber-800">
                <p className="font-medium">
                  {recipientsWithoutPhone.length} recipient{recipientsWithoutPhone.length > 1 ? 's' : ''} missing phone number
                </p>
                <p className="text-xs text-amber-600 mt-0.5">
                  {recipientsWithoutPhone.map((r) => `${r.first_name} ${r.last_name}`).join(', ')}
                </p>
              </div>
            </div>
          )}

          {/* Message body */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Message</label>
            <textarea
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              placeholder="Type your message..."
              rows={5}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none resize-none"
            />

            {/* Character count and segment info */}
            <div className="flex items-center justify-between mt-1.5">
              <div className="flex items-center gap-3">
                <span className={`text-xs font-medium ${getCharCountColor()}`}>
                  {charCount} / {SMS_SEGMENT_LENGTH} characters
                </span>
                {segmentCount > 1 && (
                  <span className="text-xs text-amber-600 flex items-center gap-1">
                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {segmentCount} SMS segment{segmentCount > 1 ? 's' : ''} per recipient
                  </span>
                )}
              </div>
              {/* Remaining in current segment */}
              {charCount > 0 && (
                <span className="text-xs text-gray-400">
                  {segmentCount <= 1
                    ? `${SMS_SEGMENT_LENGTH - charCount} remaining`
                    : `${segmentCount * SMS_CONCAT_SEGMENT_LENGTH - charCount} remaining in segment`}
                </span>
              )}
            </div>

            {/* Segment cost warning */}
            {segmentCount > 2 && (
              <div className="mt-2 bg-red-50 border border-red-100 rounded-lg px-3 py-2 flex items-center gap-2">
                <svg className="w-4 h-4 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p className="text-xs text-red-700">
                  Long messages cost more. This message will be sent as {segmentCount} segments per recipient
                  ({recipientsWithPhone.length > 0 ? `${segmentCount * recipientsWithPhone.length} total segments` : ''}).
                </p>
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="px-6 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
          {/* Recipient count */}
          <div className="text-sm text-gray-600">
            {recipientsWithPhone.length > 0 ? (
              <span>
                Sending to{' '}
                <span className="font-semibold text-gray-900">{recipientsWithPhone.length}</span>{' '}
                recipient{recipientsWithPhone.length !== 1 ? 's' : ''}
                {suppressedCount > 0 && (
                  <span className="text-amber-600 ml-1">({suppressedCount} suppressed)</span>
                )}
                {recipientsWithoutPhone.length > 0 && (
                  <span className="text-amber-600 ml-1">
                    ({recipientsWithoutPhone.length} skipped — no phone)
                  </span>
                )}
              </span>
            ) : (
              <span className="text-gray-400">No recipients with phone numbers</span>
            )}
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleSend}
              disabled={
                sendStatus === 'sending' ||
                recipientsWithPhone.length === 0 ||
                !message.trim()
              }
              className="px-5 py-2 text-sm font-medium text-white bg-brand-primary rounded-lg hover:bg-brand-primary/90 transition-colors disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2"
            >
              {sendStatus === 'sending' ? (
                <>
                  <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  Sending...
                </>
              ) : (
                <>
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                  </svg>
                  {recipientsWithPhone.length > 1 ? 'Send to Group' : 'Send'}
                </>
              )}
            </button>
          </div>
        </div>

        {/* Toast notification */}
        {toastVisible && (
          <div
            className={`absolute bottom-16 left-1/2 -translate-x-1/2 px-4 py-2.5 rounded-lg shadow-lg flex items-center gap-2 text-sm font-medium transition-all animate-[fadeIn_0.2s_ease-out] ${
              toastType === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white'
            }`}
          >
            {toastType === 'success' ? (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
              </svg>
            ) : (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            )}
            {toastMessage}
          </div>
        )}
      </div>
    </div>
  );
};

export default SmsCompose;
