const API_URL = process.env.REACT_APP_API_URL || '';

/**
 * Tell the server a notification brought this person back.
 *
 * Chat notifications deliberately bypass the bulk send path, which is where the
 * tracking pixel and link rewriting live — so they carry no open or click
 * tracking at all, and nothing in Email Reporting covers them. This is the only
 * signal, and it is a better one than a pixel: it measures a person acting
 * rather than a mail client loading an image, and it works for PUSH, which a
 * pixel could never see.
 *
 * Deliberately fire-and-forget. A metric must never delay opening the
 * conversation, and must never surface an error to someone who just wanted to
 * read a message — so nothing awaits this and every failure is swallowed.
 */
export function reportNotificationClick(conversationId: number, channel: string | null): void {
  // No `tec` parameter means they did not arrive from a notification — an
  // ordinary navigation, nothing to record.
  if (!channel) return;

  try {
    const token = localStorage.getItem('auth_token');

    fetch(`${API_URL}/api/notifications.php?action=record-click`, {
      method: 'POST',
      headers: token
        ? { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }
        : { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: conversationId, channel }),
      keepalive: true,
    }).catch(() => undefined);
  } catch {
    /* never let a metric break opening a conversation */
  }
}
