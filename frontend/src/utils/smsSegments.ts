/**
 * SMS length arithmetic, shared by SmsCompose and BroadcastCompose.
 *
 * Extracted so the two compose surfaces cannot disagree about what "2 segments"
 * means — Twilio bills per segment, so a broadcast that under-counts is a billing
 * surprise, not just a cosmetic one.
 */

/** A standalone GSM-7 message fits 160 characters. */
export const SMS_SEGMENT_LENGTH = 160;

/**
 * Once a message splits, each part carries a 7-byte UDH concatenation header,
 * leaving 153 characters of payload per segment.
 */
export const SMS_CONCAT_SEGMENT_LENGTH = 153;

/**
 * How many segments a message body costs, per recipient.
 *
 * Note this counts UTF-16 code units, not GSM-7 septets — an emoji or a curly
 * quote forces the whole message to UCS-2, where the real limits are 70/67. This
 * matches the pre-existing SmsCompose behavior and so under-counts for those
 * bodies; fixing it means detecting the encoding, which is its own change.
 */
export function countSmsSegments(message: string): number {
  const length = message.length;
  if (length === 0) return 0;
  if (length <= SMS_SEGMENT_LENGTH) return 1;
  return Math.ceil(length / SMS_CONCAT_SEGMENT_LENGTH);
}
