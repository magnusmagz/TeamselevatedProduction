<?php

class IcsParserService
{
    /**
     * Parse ICS content and return an array of event associative arrays.
     *
     * @param string $icsContent Raw .ics file content
     * @return array<array{uid:string, summary:string, description:?string, location:?string, dtstart:?string, dtend:?string, date:?string, start_time:?string, end_time:?string, all_day:bool, status:?string}>
     */
    public function parse(string $icsContent): array
    {
        $icsContent = $this->normalizeLineEndings($icsContent);
        $icsContent = $this->unfoldLines($icsContent);

        $events = [];
        $blocks = $this->extractVEvents($icsContent);

        foreach ($blocks as $block) {
            $props = $this->parseProperties($block);

            if (empty($props['UID'])) {
                continue;
            }

            if (isset($props['RRULE']) && !isset($props['DTSTART'])) {
                error_log("[IcsParser] Skipping RRULE-only event (no DTSTART): UID={$props['UID']}");
                continue;
            }

            $dtstart = $this->parseDatetime($props, 'DTSTART');
            $dtend = $this->parseDatetime($props, 'DTEND');
            $allDay = $this->isAllDay($props, 'DTSTART');

            $date = $dtstart ? $dtstart->format('Y-m-d') : null;
            $startTime = (!$allDay && $dtstart) ? $dtstart->format('H:i:s') : null;
            $endTime = (!$allDay && $dtend) ? $dtend->format('H:i:s') : null;

            $events[] = [
                'uid'         => $props['UID'],
                'summary'     => $this->unescapeText($props['SUMMARY'] ?? 'Untitled Event'),
                'description' => isset($props['DESCRIPTION']) ? $this->unescapeText($props['DESCRIPTION']) : null,
                'location'    => isset($props['LOCATION']) ? $this->unescapeText($props['LOCATION']) : null,
                'dtstart'     => $dtstart ? $dtstart->format('c') : null,
                'dtend'       => $dtend ? $dtend->format('c') : null,
                'date'        => $date,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'all_day'     => $allDay,
                'status'      => $this->mapStatus($props['STATUS'] ?? null),
            ];
        }

        return $events;
    }

    private function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function unfoldLines(string $content): string
    {
        return preg_replace('/\n[ \t]/', '', $content);
    }

    private function extractVEvents(string $content): array
    {
        $blocks = [];
        $pattern = '/BEGIN:VEVENT\n(.*?)END:VEVENT/s';
        if (preg_match_all($pattern, $content, $matches)) {
            $blocks = $matches[1];
        }
        return $blocks;
    }

    private function parseProperties(string $block): array
    {
        $props = [];
        $lines = explode("\n", trim($block));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $keyPart = substr($line, 0, $colonPos);
            $value = substr($line, $colonPos + 1);

            $semiPos = strpos($keyPart, ';');
            if ($semiPos !== false) {
                $key = substr($keyPart, 0, $semiPos);
                $paramStr = substr($keyPart, $semiPos + 1);
                $props[$key] = $value;
                $props["_{$key}_PARAMS"] = $paramStr;
            } else {
                $key = $keyPart;
                $props[$key] = $value;
            }
        }

        return $props;
    }

    private function parseDatetime(array $props, string $field): ?DateTime
    {
        $value = $props[$field] ?? null;
        if ($value === null) {
            return null;
        }

        $params = $props["_{$field}_PARAMS"] ?? '';

        if (stripos($params, 'VALUE=DATE') !== false && stripos($params, 'VALUE=DATE-TIME') === false) {
            return DateTime::createFromFormat('Ymd', $value, new DateTimeZone('UTC')) ?: null;
        }

        if (substr($value, -1) === 'Z') {
            $dt = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
            $tz = $m[1];
            try {
                $timezone = new DateTimeZone($tz);
                $dt = DateTime::createFromFormat('Ymd\THis', $value, $timezone);
                return $dt ?: null;
            } catch (Exception $e) {
                error_log("[IcsParser] Unknown timezone: {$tz}, falling back to UTC");
            }
        }

        $dt = DateTime::createFromFormat('Ymd\THis', $value, new DateTimeZone('UTC'));
        if ($dt) {
            return $dt;
        }

        $dt = DateTime::createFromFormat('Ymd', $value, new DateTimeZone('UTC'));
        return $dt ?: null;
    }

    private function isAllDay(array $props, string $field): bool
    {
        $params = $props["_{$field}_PARAMS"] ?? '';
        if (stripos($params, 'VALUE=DATE') !== false && stripos($params, 'VALUE=DATE-TIME') === false) {
            return true;
        }

        $value = $props[$field] ?? '';
        return (bool) preg_match('/^\d{8}$/', $value);
    }

    private function unescapeText(string $text): string
    {
        $text = str_replace(['\\n', '\\N'], "\n", $text);
        $text = str_replace('\\,', ',', $text);
        $text = str_replace('\\;', ';', $text);
        $text = str_replace('\\\\', '\\', $text);
        return $text;
    }

    private function mapStatus(?string $icsStatus): string
    {
        if ($icsStatus === null) {
            return 'scheduled';
        }
        $map = [
            'CONFIRMED'  => 'scheduled',
            'TENTATIVE'  => 'scheduled',
            'CANCELLED'  => 'cancelled',
        ];
        return $map[strtoupper($icsStatus)] ?? 'scheduled';
    }
}
