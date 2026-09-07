import React, { useState, useEffect, useMemo } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useOrg } from '../../contexts/OrgContext';
import { RecipientSelector } from './RecipientSelector';
import Button from '../ui/Button';
import {
  SMS_SEGMENT_LENGTH,
  SMS_CONCAT_SEGMENT_LENGTH,
  countSmsSegments,
} from '../../utils/smsSegments';

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


interface SmsComposeProps {
  isOpen: boolean;
  onClose: () => void;
  clubProfileId: number;
  preselectedRecipients?: Recipient[];
  /**
   * Open with this template's body already in the box — the "Send" button on the
   * SMS template library. Mirrors EmailCompose's `preselectedTemplate`.
   */
  preselectedTemplate?: { id: number; name: string; body_text: string };
}

type SendStatus = 'idle' | 'sending' | 'success' | 'error';

interface SmsTemplate {
  id: number;
  name: string;
  body_text: string;
  category: string;
}

export const SmsCompose: React.FC<SmsComposeProps> = ({
  isOpen,
  onClose,
  clubProfileId,
  preselectedRecipients,
  preselectedTemplate,
}) => {
  const { user } = useAuth();
  const { activeContext } = useOrg();

  const [recipients, setRecipients] = useState<Recipient[]>(preselectedRecipients || []);
  const [sendCopyToSelf, setSendCopyToSelf] = useState(false);
  const [message, setMessage] = useState('');
  const [sendStatus, setSendStatus] = useState<SendStatus>('idle');
  const [toastVisible, setToastVisible] = useState(false);
  const [toastMessage, setToastMessage] = useState('');
  const [toastType, setToastType] = useState<'success' | 'error'>('success');

  // Template picker state
  const [smsTemplates, setSmsTemplates] = useState<SmsTemplate[]>([]);
  const [templatesLoading, setTemplatesLoading] = useState(false);
  const [selectedTemplateId, setSelectedTemplateId] = useState<number | ''>('');
  const [useTemplate, setUseTemplate] = useState(false);

  const token = localStorage.getItem('auth_token');
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };

  useEffect(() => {
    if (preselectedRecipients) setRecipients(preselectedRecipients);
  }, [preselectedRecipients]);

  // Arriving from the template library's Send button. The body is set directly
  // rather than by selecting an id and waiting for the picker's fetch, so the
  // message is there the instant the modal opens. Turning the picker on as well
  // keeps the two consistent, and lets the user swap to a different template
  // without the box appearing to be free-form.
  useEffect(() => {
    if (!preselectedTemplate) return;
    setMessage(preselectedTemplate.body_text || '');
    setUseTemplate(true);
    setSelectedTemplateId(preselectedTemplate.id);
  }, [preselectedTemplate]);

  // Fetch SMS templates when "Use Template" is toggled on
  const orgClubId = activeContext?.scope_id || clubProfileId;
  useEffect(() => {
    if (!useTemplate || smsTemplates.length > 0) return;
    setTemplatesLoading(true);
    fetch(
      `${API_URL}/api/email-templates.php?action=list&club_profile_id=${orgClubId}&channel=sms`,
      { headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' } }
    )
      .then((res) => res.json())
      .then((data) => {
        if (data.success) setSmsTemplates(data.templates || []);
      })
      .catch(() => {})
      .finally(() => setTemplatesLoading(false));
  }, [useTemplate, orgClubId, token]);

  // When a template is selected, populate message
  useEffect(() => {
    if (selectedTemplateId === '') return;
    const tpl = smsTemplates.find((t) => t.id === selectedTemplateId);
    if (tpl) setMessage(tpl.body_text || '');
  }, [selectedTemplateId, smsTemplates]);

  // Character and segment counting
  const charCount = message.length;
  const segmentCount = useMemo(() => countSmsSegments(message), [message]);

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

    // CA-49: Always use the send-sms action for individually-resolved recipients,
    // regardless of count. send-sms accepts a `recipients` array; send-broadcast
    // expects team_ids + recipient_types and would reject this payload as malformed.

    try {
      const res = await fetch(`${API_URL}/api/communications?action=send-sms`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          channel: 'sms',
          recipients: [
            ...recipientsWithPhone.map((r) => ({
              id: r.id,
              type: r.type,
              phone: r.phone,
              name: `${r.first_name} ${r.last_name}`,
              athlete_id: r.athlete_id || null,
            })),
            ...(sendCopyToSelf && user?.phone
              ? [{
                  id: user.id,
                  type: 'coach' as const,
                  phone: user.phone,
                  name: user.name || '',
                  athlete_id: null,
                }]
              : []),
          ],
          body: message,
        }),
      });

      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}));
        throw new Error(errorData.error || 'Failed to send SMS');
      }

      // Surface the backend's queued/skipped counts so the user sees how many
      // messages actually went out.
      const data = await res.json().catch(() => ({} as any));
      const queued = data?.data?.queued ?? recipientsWithPhone.length;
      const skipped = data?.data?.skipped ?? 0;

      setSendStatus('success');
      showToast(
        `SMS queued for ${queued} recipient${queued !== 1 ? 's' : ''}` +
          (skipped > 0 ? ` (${skipped} skipped)` : '') +
          '.',
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
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </Button>
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

          <label className="flex items-center gap-2 mt-2 cursor-pointer">
            <input
              type="checkbox"
              checked={sendCopyToSelf}
              onChange={(e) => setSendCopyToSelf(e.target.checked)}
              className="w-4 h-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
            />
            <span className="text-sm text-gray-600">
              Send copy to myself
              {sendCopyToSelf && !user?.phone && (
                <span className="text-xs text-amber-600 ml-1">(add phone number in profile)</span>
              )}
            </span>
          </label>

          {/* Warning: recipients without phone */}
          {recipientsWithoutPhone.length > 0 && (
            <div className="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 flex items-start gap-2">
              <svg className="w-4 h-4 text-amber-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div className="text-sm text-amber-800">
                <p className="font-medium">
                  {recipientsWithoutPhone.length} recipient{recipientsWithoutPhone.length > 1 ? 's' : ''} missing a phone number
                  {' '}&mdash; they will be excluded from this SMS.
                </p>
                <p className="text-xs text-amber-600 mt-0.5">
                  {recipientsWithoutPhone.map((r) => `${r.first_name} ${r.last_name}`).join(', ')}
                </p>
              </div>
            </div>
          )}

          {/* Template picker */}
          <div>
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={useTemplate}
                onChange={(e) => {
                  setUseTemplate(e.target.checked);
                  if (!e.target.checked) setSelectedTemplateId('');
                }}
                className="w-4 h-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary"
              />
              <span className="text-sm font-medium text-gray-700">Use Template</span>
            </label>
            {useTemplate && (
              <div className="mt-2">
                {templatesLoading ? (
                  <p className="text-xs text-gray-400">Loading templates...</p>
                ) : smsTemplates.length === 0 ? (
                  <p className="text-xs text-gray-400">No SMS templates available.</p>
                ) : (
                  <>
                    <select
                      value={selectedTemplateId}
                      onChange={(e) => setSelectedTemplateId(e.target.value ? Number(e.target.value) : '')}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none bg-white"
                    >
                      <option value="">Select a template...</option>
                      {smsTemplates.map((t) => (
                        <option key={t.id} value={t.id}>{t.name}</option>
                      ))}
                    </select>
                    {selectedTemplateId !== '' && (() => {
                      const tpl = smsTemplates.find((t) => t.id === selectedTemplateId);
                      return tpl ? (
                        <p className="mt-1 text-xs text-gray-400 line-clamp-2">
                          Preview: {tpl.body_text?.substring(0, 120)}{(tpl.body_text?.length || 0) > 120 ? '...' : ''}
                        </p>
                      ) : null;
                    })()}
                  </>
                )}
              </div>
            )}
          </div>

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
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button
              onClick={handleSend}
              loading={sendStatus === 'sending'}
              disabled={recipientsWithPhone.length === 0 || !message.trim()}
              leadingIcon={
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
              }
            >
              {recipientsWithPhone.length > 1 ? 'Send to Group' : 'Send'}
            </Button>
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
