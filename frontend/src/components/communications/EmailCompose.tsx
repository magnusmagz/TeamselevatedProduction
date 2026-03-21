import React, { useState, useEffect, useCallback } from 'react';
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

interface EmailTemplate {
  id: number;
  name: string;
  subject: string;
  body_html: string;
  variables: string[];
  requires_event: boolean;
}

interface EventOption {
  id: number;
  name: string;
  event_date: string;
  event_time?: string;
  location?: string;
  team_name?: string;
}

interface EmailComposeProps {
  isOpen: boolean;
  onClose: () => void;
  clubProfileId: number;
  preselectedRecipients?: Recipient[];
  preselectedTemplate?: EmailTemplate;
}

type ComposeMode = 'freeform' | 'template';
type SendStatus = 'idle' | 'sending' | 'success' | 'error';

export const EmailCompose: React.FC<EmailComposeProps> = ({
  isOpen,
  onClose,
  clubProfileId,
  preselectedRecipients,
  preselectedTemplate,
}) => {
  const { user } = useAuth();

  const [recipients, setRecipients] = useState<Recipient[]>(preselectedRecipients || []);
  const [subject, setSubject] = useState('');
  const [bodyHtml, setBodyHtml] = useState('');
  const [composeMode, setComposeMode] = useState<ComposeMode>(preselectedTemplate ? 'template' : 'freeform');

  // Template state
  const [templates, setTemplates] = useState<EmailTemplate[]>([]);
  const [loadingTemplates, setLoadingTemplates] = useState(false);
  const [selectedTemplate, setSelectedTemplate] = useState<EmailTemplate | null>(preselectedTemplate || null);

  // Event state (for template variables)
  const [events, setEvents] = useState<EventOption[]>([]);
  const [loadingEvents, setLoadingEvents] = useState(false);
  const [selectedEvent, setSelectedEvent] = useState<EventOption | null>(null);

  // Preview state
  const [showPreview, setShowPreview] = useState(false);
  const [previewHtml, setPreviewHtml] = useState('');
  const [loadingPreview, setLoadingPreview] = useState(false);

  // Send state
  const [sendStatus, setSendStatus] = useState<SendStatus>('idle');
  const [sendError, setSendError] = useState('');
  const [toastVisible, setToastVisible] = useState(false);
  const [toastMessage, setToastMessage] = useState('');
  const [toastType, setToastType] = useState<'success' | 'error'>('success');

  const token = localStorage.getItem('auth_token');
  const headers = {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
  };

  // Initialize preselected values
  useEffect(() => {
    if (preselectedRecipients) setRecipients(preselectedRecipients);
  }, [preselectedRecipients]);

  useEffect(() => {
    if (preselectedTemplate) {
      setSelectedTemplate(preselectedTemplate);
      setComposeMode('template');
      setSubject(preselectedTemplate.subject);
      setBodyHtml(preselectedTemplate.body_html);
    }
  }, [preselectedTemplate]);

  // Fetch templates when template mode is activated
  useEffect(() => {
    if (composeMode === 'template' && templates.length === 0 && isOpen) {
      fetchTemplates();
    }
  }, [composeMode, isOpen]);

  // Fetch events when a template with event variables is selected
  useEffect(() => {
    if (selectedTemplate?.requires_event && events.length === 0) {
      fetchEvents();
    }
  }, [selectedTemplate]);

  const fetchTemplates = async () => {
    setLoadingTemplates(true);
    try {
      const params = new URLSearchParams({
        action: 'list',
        club_profile_id: String(clubProfileId),
      });
      const res = await fetch(`${API_URL}/api/email-templates?${params}`, { headers });
      if (!res.ok) throw new Error('Failed to fetch templates');
      const data = await res.json();
      setTemplates(data.templates || []);
    } catch (err) {
      console.error('Failed to fetch templates:', err);
    } finally {
      setLoadingTemplates(false);
    }
  };

  const fetchEvents = async () => {
    setLoadingEvents(true);
    try {
      const params = new URLSearchParams({
        action: 'list',
        club_profile_id: String(clubProfileId),
      });
      const res = await fetch(`${API_URL}/api/events?${params}`, { headers });
      if (!res.ok) throw new Error('Failed to fetch events');
      const data = await res.json();
      setEvents(data.events || []);
    } catch (err) {
      console.error('Failed to fetch events:', err);
    } finally {
      setLoadingEvents(false);
    }
  };

  const handleTemplateChange = (templateId: string) => {
    const template = templates.find((t) => t.id === Number(templateId));
    setSelectedTemplate(template || null);
    if (template) {
      setSubject(template.subject);
      setBodyHtml(template.body_html);
      setSelectedEvent(null);
    }
  };

  const handleEventChange = (eventId: string) => {
    const event = events.find((e) => e.id === Number(eventId));
    setSelectedEvent(event || null);
  };

  const showToast = (message: string, type: 'success' | 'error') => {
    setToastMessage(message);
    setToastType(type);
    setToastVisible(true);
    setTimeout(() => setToastVisible(false), 4000);
  };

  // Preview
  const handlePreview = async () => {
    setLoadingPreview(true);
    setShowPreview(true);
    try {
      const res = await fetch(`${API_URL}/api/communications?action=preview-email`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          template_id: selectedTemplate?.id || null,
          event_id: selectedEvent?.id || null,
          subject,
          body_html: composeMode === 'freeform' ? bodyHtml : selectedTemplate?.body_html,
        }),
      });
      if (!res.ok) throw new Error('Failed to generate preview');
      const data = await res.json();
      setPreviewHtml(data.preview_html || bodyHtml);
    } catch (err) {
      // Fallback: show raw HTML with simple variable replacement
      let preview = composeMode === 'freeform' ? bodyHtml : selectedTemplate?.body_html || '';
      if (selectedEvent) {
        preview = preview
          .replace(/\{\{event_name\}\}/g, selectedEvent.name)
          .replace(/\{\{event_date\}\}/g, selectedEvent.event_date)
          .replace(/\{\{event_time\}\}/g, selectedEvent.event_time || '')
          .replace(/\{\{location\}\}/g, selectedEvent.location || '')
          .replace(/\{\{team_name\}\}/g, selectedEvent.team_name || '');
      }
      setPreviewHtml(preview);
    } finally {
      setLoadingPreview(false);
    }
  };

  // Send
  const handleSend = async () => {
    const activeRecipients = recipients.filter((r) => !r.suppressed);
    if (activeRecipients.length === 0) {
      showToast('No active recipients selected.', 'error');
      return;
    }
    if (!subject.trim()) {
      showToast('Subject line is required.', 'error');
      return;
    }
    const content = composeMode === 'freeform' ? bodyHtml : selectedTemplate?.body_html || '';
    if (!content.trim()) {
      showToast('Email body is required.', 'error');
      return;
    }

    setSendStatus('sending');
    setSendError('');

    const isBroadcast = activeRecipients.length > 1;
    const action = isBroadcast ? 'send-broadcast' : 'send-email';

    try {
      const res = await fetch(`${API_URL}/api/communications?action=${action}`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          club_profile_id: clubProfileId,
          channel: 'email',
          recipients: activeRecipients.map((r) => ({
            id: r.id,
            type: r.type,
          })),
          subject,
          body_html: content,
          template_id: selectedTemplate?.id || null,
          event_id: selectedEvent?.id || null,
        }),
      });

      if (!res.ok) {
        const errorData = await res.json().catch(() => ({}));
        throw new Error(errorData.error || 'Failed to send email');
      }

      setSendStatus('success');
      showToast(
        `Email${isBroadcast ? 's' : ''} queued for ${activeRecipients.length} recipient${activeRecipients.length > 1 ? 's' : ''}.`,
        'success'
      );

      // Auto-close after success
      setTimeout(() => {
        resetForm();
        onClose();
      }, 2000);
    } catch (err: any) {
      setSendStatus('error');
      setSendError(err.message || 'Failed to send email');
      showToast(err.message || 'Failed to send email. Please try again.', 'error');
    }
  };

  const resetForm = () => {
    setRecipients([]);
    setSubject('');
    setBodyHtml('');
    setSelectedTemplate(null);
    setSelectedEvent(null);
    setComposeMode('freeform');
    setShowPreview(false);
    setSendStatus('idle');
    setSendError('');
  };

  const activeRecipientCount = recipients.filter((r) => !r.suppressed).length;
  const suppressedCount = recipients.filter((r) => r.suppressed).length;

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

      {/* Modal */}
      <div className="relative w-full max-w-3xl max-h-[90vh] mx-4 bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gray-50">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-brand-primary/10 flex items-center justify-center">
              <svg className="w-4 h-4 text-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <h2 className="text-lg font-semibold text-gray-900">Compose Email</h2>
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

        {/* Body — scrollable */}
        <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
          {/* Recipient selector */}
          <RecipientSelector
            clubProfileId={clubProfileId}
            channel="email"
            selectedRecipients={recipients}
            onRecipientsChange={setRecipients}
          />

          {/* Subject line */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Subject</label>
            <input
              type="text"
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              placeholder="Enter subject line..."
              className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none"
            />
          </div>

          {/* Compose mode toggle */}
          <div className="flex items-center gap-1 p-1 bg-gray-100 rounded-lg w-fit">
            <button
              type="button"
              onClick={() => {
                setComposeMode('freeform');
                setShowPreview(false);
              }}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                composeMode === 'freeform'
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              Free-form
            </button>
            <button
              type="button"
              onClick={() => {
                setComposeMode('template');
                setShowPreview(false);
              }}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                composeMode === 'template'
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-600 hover:text-gray-900'
              }`}
            >
              Use Template
            </button>
          </div>

          {/* Template mode */}
          {composeMode === 'template' && (
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Template</label>
                {loadingTemplates ? (
                  <div className="flex items-center gap-2 text-sm text-gray-500 py-2">
                    <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Loading templates...
                  </div>
                ) : (
                  <select
                    value={selectedTemplate?.id || ''}
                    onChange={(e) => handleTemplateChange(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none bg-white"
                  >
                    <option value="">Select a template...</option>
                    {templates.map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.name}
                      </option>
                    ))}
                  </select>
                )}
              </div>

              {/* Event selector (if template requires it) */}
              {selectedTemplate?.requires_event && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Link Event
                    <span className="text-gray-400 font-normal ml-1">(for template variables)</span>
                  </label>
                  {loadingEvents ? (
                    <div className="flex items-center gap-2 text-sm text-gray-500 py-2">
                      <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Loading events...
                    </div>
                  ) : (
                    <select
                      value={selectedEvent?.id || ''}
                      onChange={(e) => handleEventChange(e.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none bg-white"
                    >
                      <option value="">Select an event...</option>
                      {events.map((ev) => (
                        <option key={ev.id} value={ev.id}>
                          {ev.name} — {ev.event_date}
                          {ev.team_name ? ` (${ev.team_name})` : ''}
                        </option>
                      ))}
                    </select>
                  )}
                </div>
              )}

              {/* Template variable hints */}
              {selectedTemplate && selectedTemplate.variables.length > 0 && (
                <div className="bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
                  <p className="text-xs font-medium text-blue-700 mb-1">Template Variables</p>
                  <div className="flex flex-wrap gap-1.5">
                    {selectedTemplate.variables.map((v) => (
                      <code
                        key={v}
                        className="text-xs bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded"
                      >
                        {`{{${v}}}`}
                      </code>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Free-form body */}
          {composeMode === 'freeform' && !showPreview && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Body</label>
              <textarea
                value={bodyHtml}
                onChange={(e) => setBodyHtml(e.target.value)}
                placeholder="Compose your email..."
                rows={10}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-primary/30 focus:border-brand-primary transition-colors outline-none resize-y"
              />
            </div>
          )}

          {/* Preview panel */}
          {showPreview && (
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="block text-sm font-medium text-gray-700">Preview</label>
                <button
                  type="button"
                  onClick={() => setShowPreview(false)}
                  className="text-sm text-brand-primary hover:underline"
                >
                  Back to editor
                </button>
              </div>
              <div className="border border-gray-200 rounded-lg overflow-hidden">
                {/* Subject preview */}
                <div className="px-4 py-2 bg-gray-50 border-b border-gray-200">
                  <p className="text-xs text-gray-500">Subject</p>
                  <p className="text-sm font-medium text-gray-900">{subject || '(no subject)'}</p>
                </div>
                {/* Body preview */}
                <div className="px-4 py-3">
                  {loadingPreview ? (
                    <div className="flex items-center justify-center py-8 gap-2 text-sm text-gray-500">
                      <svg className="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Generating preview...
                    </div>
                  ) : (
                    <div
                      className="prose prose-sm max-w-none"
                      dangerouslySetInnerHTML={{ __html: previewHtml }}
                    />
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-3 border-t border-gray-200 bg-gray-50 flex items-center justify-between">
          {/* Recipient count badge */}
          <div className="text-sm text-gray-600">
            {activeRecipientCount > 0 ? (
              <span>
                Sending to{' '}
                <span className="font-semibold text-gray-900">{activeRecipientCount}</span>{' '}
                recipient{activeRecipientCount !== 1 ? 's' : ''}
                {suppressedCount > 0 && (
                  <span className="text-amber-600 ml-1">
                    ({suppressedCount} suppressed)
                  </span>
                )}
              </span>
            ) : (
              <span className="text-gray-400">No recipients selected</span>
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

            {!showPreview && (
              <button
                type="button"
                onClick={handlePreview}
                disabled={!bodyHtml && !selectedTemplate}
                className="px-4 py-2 text-sm font-medium text-brand-primary bg-brand-primary/10 border border-brand-primary/20 rounded-lg hover:bg-brand-primary/20 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                Preview
              </button>
            )}

            <button
              type="button"
              onClick={handleSend}
              disabled={sendStatus === 'sending' || activeRecipientCount === 0 || !subject.trim()}
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
                  {activeRecipientCount > 1 ? 'Send to Group' : 'Send'}
                </>
              )}
            </button>
          </div>
        </div>

        {/* Toast notification */}
        {toastVisible && (
          <div
            className={`absolute bottom-16 left-1/2 -translate-x-1/2 px-4 py-2.5 rounded-lg shadow-lg flex items-center gap-2 text-sm font-medium transition-all animate-[fadeIn_0.2s_ease-out] ${
              toastType === 'success'
                ? 'bg-green-600 text-white'
                : 'bg-red-600 text-white'
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

export default EmailCompose;
