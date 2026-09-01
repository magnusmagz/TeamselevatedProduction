<?php
/**
 * Turn a Teams Elevated record into a branded PNG, via Canva.
 *
 * `lib/CanvaClient.php` is transport — OAuth and one method per endpoint. This is
 * the business layer: which brand template backs this club and graphic type, what
 * data goes into its fields, and what happens to the bytes that come back. Same
 * split as StripeGateway / the Stripe services.
 *
 * THE WHOLE THING IS ONE SYNCHRONOUS CALL, AND THAT IS A DECISION
 * Autofill and export are both async jobs on Canva's side, so this method polls
 * them. A generate takes roughly 5-15 seconds end to end, which is a long HTTP
 * request but a short wait for a person who just pressed a button and is watching
 * a spinner. The alternative — enqueue it and make them come back — is worse for
 * one graphic and only pays off in bulk. When bulk arrives (a schedule poster per
 * team, a thank-you per sponsor), move THIS method onto the Redis queue unchanged;
 * `club_media_assets.status` already models the async case.
 *
 * WHAT CANVA IS NEVER TOLD
 * Only the field values a template declares. No athlete data reaches this file at
 * all: the parked athlete-featuring templates need a media-release consent type
 * that does not exist yet (see migration 069's closing note and the scope doc).
 * Adding a `player_spotlight` graphic_type here without that consent is the one
 * change in this file that would be a compliance problem rather than a bug.
 */

require_once __DIR__ . '/../lib/CanvaClient.php';
require_once __DIR__ . '/../lib/bytea.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

class CanvaDesignService
{
    /**
     * Graphic types this service knows how to build data for.
     *
     * `canva_brand_templates.graphic_type` is deliberately an unconstrained
     * VARCHAR (migration 069) so the catalog can churn without a migration —
     * which means the validation has to live somewhere, and this is it. An
     * unknown type is refused rather than sent to Canva with an empty payload.
     */
    public const GRAPHIC_TYPES = [
        'sponsor_thanks',
        'game_day',
        'practice_cancelled',
        'team_event',
        'schedule_week',
        'tryout_announcement',
        'registration_open',
    ];

    /**
     * What each graphic type is ABOUT — the kind of row `subject_id` refers to.
     *
     * Several types share a subject and differ only in artwork: game_day,
     * practice_cancelled and team_event are all one calendar event. That is the
     * point of keeping the loader and the type separate — a new template for an
     * existing subject costs one line here and no PHP.
     */
    public const SUBJECT_KINDS = [
        'sponsor_thanks'     => 'sponsor',
        'game_day'           => 'event',
        'practice_cancelled' => 'event',
        'team_event'         => 'event',
        'schedule_week'      => 'team',
        'tryout_announcement' => 'program',
        'registration_open'   => 'program',
    ];

    /** How many event slots a schedule_week template may declare. */
    public const SCHEDULE_SLOTS = 6;

    /** Where a person goes to fill in missing data, per subject kind. */
    private const SUBJECT_NOUNS = [
        'sponsor' => 'sponsor',
        'event'   => 'event',
        'team'    => 'team',
        'program' => 'program',
    ];

    /**
     * What each field IS, in the words a club admin would use.
     *
     * The point of this map is that "we cannot fill event_venue_name" tells
     * someone nothing they can act on, whereas "this event has no venue or
     * location" tells them exactly what to go and type. Fields not listed fall
     * back to a readable form of their own name, so the map never has to be
     * exhaustive to be useful.
     */
    private const FIELD_LABELS = [
        'opponent'            => 'an opponent',
        'event_time'          => 'a start time',
        'event_venue_name'    => 'a venue or location',
        'event_location'      => 'a venue or location',
        'event_address'       => 'a venue with an address on it',
        'team_name'           => 'a team assigned to it',
        'event_type'          => 'a type',
        'event_status'        => 'a status',
        'sponsor_website'     => 'a website',
        'program_fee'         => 'a registration fee',
        'program_ages'        => 'an age range',
        'program_venue'       => 'a venue',
        'program_capacity'    => 'a capacity',
        'program_description' => 'a description',
        'program_season'      => 'a season',
        'program_start_date'  => 'a start date',
        'program_start_short' => 'a start date',
        'program_end_date'    => 'an end date',
        'registration_opens'  => 'a registration open date',
        'registration_closes' => 'a registration close date',
        'registration_close_short' => 'a registration close date',
        'club_logo'           => 'a logo uploaded',
        'sponsor_logo'        => 'a logo uploaded',
    ];

    /** @var PDO */
    private $pdo;

    /** @var CanvaClient */
    private $canva;

    public function __construct(PDO $pdo, ?CanvaClient $canva = null)
    {
        $this->pdo   = $pdo;
        $this->canva = $canva ?: new CanvaClient($pdo);
    }

    /**
     * Is this club able to generate this graphic type at all?
     *
     * Separate from generate() so a UI can hide a button it would only be told
     * "no template" by. Returns null when there is nothing configured.
     */
    public function activeTemplate(int $clubId, string $graphicType): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, canva_brand_template_id, title, dataset, dataset_fetched_at
               FROM canva_brand_templates
              WHERE club_profile_id = ? AND graphic_type = ? AND is_active
              LIMIT 1'
        );
        $stmt->execute([$clubId, $graphicType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Every configured template for a club whose subject is of one kind.
     *
     * The UI asks this rather than probing each graphic type in turn, so
     * registering a new template makes a button appear with no frontend change.
     * That is the point: the catalog is expected to churn weekly during the pilot
     * and a redeploy per template would guarantee it does not.
     */
    public function availableFor(int $clubId, string $subjectKind): array
    {
        $types = array_keys(array_filter(
            self::SUBJECT_KINDS,
            fn($kind) => $kind === $subjectKind
        ));
        if (!$types) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT graphic_type, title
               FROM canva_brand_templates
              WHERE club_profile_id = ? AND is_active
                AND graphic_type IN ({$placeholders})
              ORDER BY graphic_type"
        );
        $stmt->execute(array_merge([$clubId], $types));

        return array_map(fn($row) => [
            'graphic_type' => $row['graphic_type'],
            'title'        => $row['title'] ?: ucwords(str_replace('_', ' ', $row['graphic_type'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The template's autofillable fields, as Canva reports them.
     *
     * ⚠️ ALWAYS ASKS CANVA, and the cached copy is a fallback for when that call
     * fails — not a first choice. It used to be the other way round, and on
     * 2026-09-01 that shipped wrong artwork: a template gained club_logo and
     * club_name, the cache still said sponsor_name, so generate sent one field
     * and Canva left the other two at their template defaults. No error, no
     * warning, just a graphic missing its logo.
     *
     * That is the failure this whole service is built to avoid. An unfillable
     * field refuses loudly precisely so nothing wrong reaches social media, and a
     * stale field list walks straight around that guard. One extra API call
     * (~200ms) against a generate that already takes ten seconds is not a price
     * worth arguing over.
     *
     * A designer editing a template in Canva changes its dataset and nothing
     * tells us. There is no version, no webhook, no timestamp to compare — so
     * there is no way to know a cache is stale except by asking.
     */
    public function fields(array $template): array
    {
        try {
            $response = $this->canva->getBrandTemplateDataset($template['canva_brand_template_id']);
            $fields   = $response['dataset'] ?? [];

            $this->pdo->prepare(
                'UPDATE canva_brand_templates
                    SET dataset = ?, dataset_fetched_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            )->execute([json_encode($fields), $template['id']]);

            return $fields;
        } catch (Throwable $e) {
            // Canva unreachable. The cached list is better than refusing outright
            // — it was correct at some point — but it is the fallback, and if it
            // is empty too there is nothing to generate from.
            error_log('canva: dataset fetch failed, falling back to cache: ' . $e->getMessage());

            $cached = json_decode((string) ($template['dataset'] ?? ''), true);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }

            throw new RuntimeException('Could not read the template from Canva. Try again in a moment.');
        }
    }

    /**
     * Generate a graphic and store it.
     *
     * @param  int    $clubId
     * @param  string $graphicType  One of GRAPHIC_TYPES.
     * @param  int    $subjectId    The row the graphic is about (a sponsor id, today).
     * @param  int    $userId       Who asked. Recorded as created_by and in the audit row.
     * @return array  The club_media_assets row, minus the bytes.
     * @throws RuntimeException on anything that leaves no usable image.
     */
    public function generate(int $clubId, string $graphicType, int $subjectId, int $userId): array
    {
        if (!in_array($graphicType, self::GRAPHIC_TYPES, true)) {
            throw new RuntimeException("Unknown graphic type: {$graphicType}");
        }

        $template = $this->activeTemplate($clubId, $graphicType);
        if (!$template) {
            throw new RuntimeException('This club has no template for that graphic yet.');
        }

        $subject = $this->loadSubject($clubId, $graphicType, $subjectId);
        $fields  = $this->fields($template);
        if (!$fields) {
            throw new RuntimeException('That template has no autofill fields.');
        }

        $data = $this->buildPayload($fields, $subject);
        if (!$data) {
            throw new RuntimeException('Nothing in that template matches data we hold.');
        }

        // The row exists BEFORE the bytes do — Canva's work is async and a failure
        // partway leaves a record saying so, rather than nothing at all.
        $insert = $this->pdo->prepare(
            "INSERT INTO club_media_assets
                 (club_profile_id, created_by, source, graphic_type, status)
             VALUES (?, ?, 'canva', ?, 'rendering')
             RETURNING id"
        );
        $insert->execute([$clubId, $userId ?: null, $graphicType]);
        $assetId = (int) $insert->fetchColumn();

        try {
            $png = $this->render($template['canva_brand_template_id'], $data, $subject['label']);
            $this->store($assetId, $png);
        } catch (Throwable $e) {
            $this->pdo->prepare(
                "UPDATE club_media_assets
                    SET status = 'failed', error_message = ?, updated_at = CURRENT_TIMESTAMP
                  WHERE id = ?"
            )->execute([substr($e->getMessage(), 0, 1000), $assetId]);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        AuditLogger::log($this->pdo, $userId ?: null, 'canva_graphic_generated', 'club_media_assets', $assetId, [
            'club_id'      => $clubId,
            'graphic_type' => $graphicType,
            'subject_id'   => $subjectId,
            'design_id'    => $png['design_id'],
        ]);

        return $this->describe($assetId);
    }

    /** One asset's metadata. Never the bytes — see the note on store(). */
    public function describe(int $assetId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, club_profile_id, graphic_type, status, error_message,
                    mime_type, file_size, width, height, canva_design_id, created_at
               FROM club_media_assets
              WHERE id = ?'
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Recent graphics for a club.
     *
     * ⚠️ Columns are listed explicitly and image_data is not among them. Postgres
     * TOASTs the bytes out of the main heap, so a list that does not name the
     * column stays cheap — and `SELECT *` here would pull every PNG into memory
     * to render a list of filenames.
     */
    public function recent(int $clubId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $stmt = $this->pdo->prepare(
            "SELECT id, graphic_type, status, mime_type, file_size, width, height,
                    canva_design_id, created_by, created_at
               FROM club_media_assets
              WHERE club_profile_id = ? AND status = 'ready'
              ORDER BY created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$clubId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The stored PNG, or null. The only method that reads image_data. */
    public function imageBytes(int $assetId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT image_data, mime_type, file_size
               FROM club_media_assets
              WHERE id = ? AND status = 'ready'"
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['image_data'] === null) {
            return null;
        }

        // pdo_pgsql hands back a BYTEA as a stream resource.
        $data = is_resource($row['image_data'])
            ? stream_get_contents($row['image_data'])
            : $row['image_data'];

        return [
            'bytes'     => $data,
            'mime_type' => $row['mime_type'] ?: 'image/png',
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * The club's logo bytes, or null.
     *
     * Reads `club_profile.logo_png`, which is base64 of a real PNG (migration
     * 049) — the column that api/club-logo.php serves to email. `logo_url` is
     * deliberately not used as a fallback: on CKU it holds an AVIF data URI, and
     * while Canva accepts AVIF, the PNG is the copy that is known-good and
     * already maintained.
     */
    private function clubLogoBytes(int $clubId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT logo_png FROM club_profile WHERE id = ?');
        $stmt->execute([$clubId]);
        $b64 = $stmt->fetchColumn();
        if (!$b64) {
            return null;
        }
        $bin = base64_decode((string) $b64, true);

        return $bin === false || $bin === '' ? null : $bin;
    }

    /**
     * A sponsor's logo bytes, or null.
     *
     * `sponsors.logo_data` is a data URI and the stored format is whatever the
     * club uploaded — all 22 live sponsor logos are AVIF. **Canva accepts AVIF**
     * (verified 2026-08-28 against the real asset endpoint), so no conversion
     * happens anywhere in this path. That is worth stating because the obvious
     * assumption is the opposite: api/club-logo.php refuses AVIF, but only
     * because EMAIL clients cannot render it.
     */
    private function sponsorLogoBytes(int $sponsorId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT logo_data FROM sponsors WHERE id = ?');
        $stmt->execute([$sponsorId]);
        $stored = (string) ($stmt->fetchColumn() ?: '');
        if ($stored === '') {
            return null;
        }

        // Tolerate a bare base64 payload: the column is free text.
        $comma   = strpos($stored, ',');
        $payload = ($comma !== false && strncmp($stored, 'data:', 5) === 0)
            ? substr($stored, $comma + 1)
            : $stored;

        $bin = base64_decode($payload, true);

        return $bin === false || $bin === '' ? null : $bin;
    }

    /**
     * A Canva asset id for these exact bytes, uploading only if we have not
     * already.
     *
     * ⚠️ Canva rate limits asset upload to 30 requests per minute PER USER, and
     * the headless model gives every club the same user — so that ceiling is
     * platform-wide. Re-uploading an unchanged club logo on every generate spends
     * it on nothing, and eleven clubs in one minute would exhaust it between them.
     *
     * Keyed on the content hash rather than "club 51's logo": a club that changes
     * its logo must not keep receiving the old artwork, and one that changes it
     * back should not pay twice. The hash answers both with no invalidation logic
     * to get wrong.
     */
    private function assetIdFor(string $sourceKey, string $binary): string
    {
        $hash = hash('sha256', $binary);

        $stmt = $this->pdo->prepare('SELECT canva_asset_id FROM canva_assets WHERE content_hash = ?');
        $stmt->execute([$hash]);
        $cached = $stmt->fetchColumn();

        if ($cached) {
            $this->pdo->prepare(
                'UPDATE canva_assets SET last_used_at = CURRENT_TIMESTAMP WHERE content_hash = ?'
            )->execute([$hash]);

            return (string) $cached;
        }

        $job  = $this->canva->createAssetUpload($sourceKey . '-' . substr($hash, 0, 8), $binary);
        $done = $this->canva->pollJob(fn() => $this->canva->getAssetUploadJob($job['job']['id'] ?? ''));

        $assetId = $done['job']['asset']['id'] ?? ($done['asset']['id'] ?? null);
        if (!$assetId) {
            throw new RuntimeException('Canva accepted the image but returned no asset.');
        }

        // ON CONFLICT because two generates can race on the same logo, and losing
        // that race is not an error — the other one uploaded the identical bytes.
        $this->pdo->prepare(
            'INSERT INTO canva_assets (source_key, content_hash, canva_asset_id, byte_size)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (content_hash) DO UPDATE SET last_used_at = CURRENT_TIMESTAMP'
        )->execute([$sourceKey, $hash, $assetId, strlen($binary)]);

        return (string) $assetId;
    }

    /** The club's display name, for templates that put it on the artwork. */
    private function clubName(int $clubId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM club_profile WHERE id = ?');
        $stmt->execute([$clubId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Load the record the graphic is about, scoped to the club.
     *
     * The club check is here rather than at the caller because this is the layer
     * that turns an id from a request body into data — passing a sponsor id from
     * another club must find nothing, not silently render someone else's sponsor
     * under this club's branding.
     */
    private function loadSubject(int $clubId, string $graphicType, int $subjectId): array
    {
        $kind = self::SUBJECT_KINDS[$graphicType] ?? null;

        if ($kind === 'sponsor') {
            return $this->loadSponsorSubject($clubId, $subjectId);
        }
        if ($kind === 'event') {
            return $this->loadEventSubject($clubId, $subjectId);
        }
        if ($kind === 'team') {
            return $this->loadTeamWeekSubject($clubId, $subjectId);
        }
        if ($kind === 'program') {
            return $this->loadProgramSubject($clubId, $subjectId);
        }

        throw new RuntimeException("No subject loader for {$graphicType}");
    }

    /**
     * A program, as fields a brand template can ask for.
     *
     * ⚠️ PROGRAM DATA IS PATCHY, and the hard-error rule bites here more than
     * anywhere else. Measured on club 32's 28 programs (2026-08-28): start_date on
     * 17, registration_closes on 16, registration_fee on 12, venue on 1. So a
     * template that declares `program_fee` will simply refuse for the 16 programs
     * that have no fee recorded — which is correct (a flyer reading "$FEE" is
     * worse than no flyer) but means a template should stick to the fields that
     * are reliably present unless the club keeps its programs complete.
     *
     * ⚠️ AGE COLUMNS: `programs` carries BOTH age_min/age_max AND min_age/max_age.
     * They are not synonyms in practice — min_age is populated on 15 rows and
     * age_min on 5 — so both are read and whichever is present wins. Picking one
     * silently loses two thirds of the data.
     */
    private function loadProgramSubject(int $clubId, int $programId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.name, p.description, p.type, p.season_type, p.season_year,
                    p.start_date, p.end_date, p.registration_opens, p.registration_closes,
                    p.registration_fee, p.capacity, p.status,
                    p.age_min, p.age_max, p.min_age, p.max_age,
                    v.name AS venue_name
               FROM programs p
          LEFT JOIN venues v ON v.id = p.venue_id
              WHERE p.id = ? AND p.club_id = ?'
        );
        $stmt->execute([$programId, $clubId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$program) {
            throw new RuntimeException('Program not found for this club.');
        }

        $utc  = new DateTimeZone('UTC');
        $long = function ($raw) use ($utc): string {
            if (!$raw) {
                return '';
            }
            // These columns are timestamps in places and dates in others, so only
            // the date part is ever read — and it is read in a fixed zone, so the
            // day printed cannot drift from the day stored.
            $d = DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $raw, 0, 10), $utc);
            return $d ? $d->format('F j, Y') : '';
        };
        $short = function ($raw) use ($utc): string {
            if (!$raw) {
                return '';
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $raw, 0, 10), $utc);
            return $d ? $d->format('M j') : '';
        };

        $ageMin = $program['min_age'] ?? $program['age_min'];
        $ageMax = $program['max_age'] ?? $program['age_max'];
        $ages   = '';
        if ($ageMin !== null && $ageMax !== null) {
            $ages = "Ages {$ageMin}-{$ageMax}";
        } elseif ($ageMin !== null) {
            $ages = "Ages {$ageMin}+";
        } elseif ($ageMax !== null) {
            $ages = "Up to age {$ageMax}";
        }

        $fee = $program['registration_fee'] !== null && $program['registration_fee'] !== ''
            ? '$' . number_format((float) $program['registration_fee'], 2)
            : '';

        $season = trim(implode(' ', array_filter([
            $program['season_type'] ?: null,
            $program['season_year'] ?: null,
        ])));

        return [
            'kind'   => 'program',
            'label'  => $program['name'],
            'values' => [
                'program_name'        => (string) $program['name'],
                'program_type'        => ucwords(str_replace('_', ' ', (string) ($program['type'] ?? ''))),
                'program_description' => (string) ($program['description'] ?? ''),
                'program_season'      => $season,
                'program_start_date'  => $long($program['start_date']),
                'program_start_short' => $short($program['start_date']),
                'program_end_date'    => $long($program['end_date']),
                'registration_opens'  => $long($program['registration_opens']),
                'registration_closes' => $long($program['registration_closes']),
                'registration_close_short' => $short($program['registration_closes']),
                'program_fee'         => $fee,
                'program_ages'        => $ages,
                'program_capacity'    => $program['capacity'] !== null ? (string) $program['capacity'] : '',
                'program_venue'       => (string) ($program['venue_name'] ?? ''),
                'club_name'           => $this->clubName($clubId),
            ],
            'images' => [
                'club_logo' => fn() => $this->clubLogoBytes($clubId),
            ],
        ];
    }

    /** A sponsor, as fields a brand template can ask for. */
    private function loadSponsorSubject(int $clubId, int $sponsorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, website FROM sponsors
              WHERE id = ? AND club_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$sponsorId, $clubId]);
        $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sponsor) {
            throw new RuntimeException('Sponsor not found for this club.');
        }

        return [
            'kind'   => 'sponsor',
            'label'  => $sponsor['name'],
            'values' => [
                'sponsor_name'    => $sponsor['name'],
                'sponsor_website' => $sponsor['website'] ?? '',
                'club_name'       => $this->clubName($clubId),
            ],
            // Loaders, not bytes: a template that declares no image field must
            // not pay to read a logo out of the database, let alone upload it.
            'images' => [
                'sponsor_logo' => fn() => $this->sponsorLogoBytes((int) $sponsor['id']),
                'club_logo'    => fn() => $this->clubLogoBytes($clubId),
            ],
        ];
    }

    /**
     * A calendar event, as fields a brand template can ask for.
     *
     * FIELD NAMES MIRROR THE EMAIL MERGE TAGS ON PURPOSE. `event_name`,
     * `event_date`, `event_time`, `event_type`, `event_venue_name` and
     * `event_address` are exactly what MergeFieldService::loadEventData() supplies
     * to `{{event_*}}` in an email template, formatted the same way. One
     * vocabulary across both surfaces means a person who has written a club email
     * already knows what to call a field in Canva.
     *
     * The short forms are additions, not replacements: "Saturday, September 6,
     * 2026" is right in an email body and wrecks a 1080px layout, so a designer
     * can reach for `event_date_short` or `event_day` instead.
     *
     * ⚠️ DATE-ONLY VALUES ARE FORMATTED IN AN EXPLICIT UTC ZONE. `event_date` is a
     * date column with no time in it, and the rule in CLAUDE.md is that such a
     * value must be read and written in the SAME zone — mixing them is what
     * scheduled every practice one day late in August. Pinning the zone here means
     * the weekday this prints can never disagree with the stored date, whatever
     * the dyno is set to.
     */
    private function loadEventSubject(int $clubId, int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.name, e.event_date, e.start_time, e.type, e.opponent_name,
                    e.location, e.status,
                    v.name AS venue_name, v.address AS venue_address,
                    v.city AS venue_city, v.state AS venue_state,
                    c.name AS club_name
               FROM calendar_events e
          LEFT JOIN venues v ON v.id = e.venue_id
          LEFT JOIN club_profile c ON c.id = e.club_id
              WHERE e.id = ? AND e.club_id = ?'
        );
        $stmt->execute([$eventId, $clubId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            throw new RuntimeException('Event not found for this club.');
        }

        // An event can carry several teams; the lowest team_id wins, which is the
        // same deterministic tie-break MergeFieldService uses. Deterministic
        // matters more than clever here — the same event must not produce a
        // different graphic on two clicks.
        $teamStmt = $this->pdo->prepare(
            'SELECT t.name
               FROM calendar_event_teams cet
               JOIN teams t ON t.id = cet.team_id
              WHERE cet.event_id = ?
              ORDER BY t.id
              LIMIT 1'
        );
        $teamStmt->execute([$eventId]);
        $teamName = (string) ($teamStmt->fetchColumn() ?: '');

        $utc  = new DateTimeZone('UTC');
        $date = $event['event_date']
            ? DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $event['event_date'], 0, 10), $utc)
            : null;
        $time = $event['start_time']
            ? DateTimeImmutable::createFromFormat('H:i:s', substr((string) $event['start_time'], 0, 8), $utc)
            : null;

        // A venue record gives the richer string; `location` is the free-text
        // fallback for an event booked somewhere with no venue row.
        $addressParts = array_filter([
            $event['venue_address'] ?: null,
            trim(implode(' ', array_filter([$event['venue_city'] ?: null, $event['venue_state'] ?: null]))) ?: null,
        ]);
        $venueName = $event['venue_name'] ?: (string) ($event['location'] ?? '');

        return [
            'kind'   => 'event',
            'label'  => $event['name'],
            'values' => [
                // Same names and formats as the email merge tags.
                'event_name'       => (string) $event['name'],
                'event_date'       => $date ? $date->format('l, F j, Y') : '',
                'event_time'       => $time ? $time->format('g:i A') : '',
                'event_type'       => ucfirst((string) ($event['type'] ?? '')),
                'event_venue_name' => $venueName,
                'event_address'    => $addressParts ? implode(', ', $addressParts) : (string) ($event['location'] ?? ''),
                'event_location'   => $venueName,

                // Short forms, for layouts a full sentence would break.
                'event_date_short' => $date ? $date->format('D, M j') : '',
                'event_day'        => $date ? $date->format('l') : '',
                'event_month'      => $date ? strtoupper($date->format('M')) : '',
                'event_day_number' => $date ? $date->format('j') : '',

                'opponent'         => (string) ($event['opponent_name'] ?? ''),
                'team_name'        => $teamName,
                'club_name'        => (string) ($event['club_name'] ?? ''),
                'event_status'     => ucfirst((string) ($event['status'] ?? '')),
            ],
            'images' => [
                'club_logo' => fn() => $this->clubLogoBytes($clubId),
            ],
        ];
    }

    /**
     * A team's next week of events, as numbered slots.
     *
     * ⚠️ THE SLOT FIELDS ARE OPTIONAL, and they are the only optional fields in
     * this service. Everywhere else an unfillable field is a hard error, because
     * Canva leaves it at its template default and the graphic ships reading
     * "OPPONENT". A schedule is the case where that rule is wrong: a template with
     * six rows used by a team with three events must render three rows and blank
     * the rest, not refuse. Blanking is safe here precisely because the default
     * text ("Event 4") is what would otherwise show.
     *
     * Deliberately NOT date-shifted: the window is computed from CURRENT_DATE in
     * Postgres and the dates are formatted from their stored parts, so no
     * timezone conversion happens anywhere in this path.
     */
    private function loadTeamWeekSubject(int $clubId, int $teamId): array
    {
        $team = $this->pdo->prepare(
            'SELECT id, name FROM teams WHERE id = ? AND club_id = ? AND deleted_at IS NULL'
        );
        $team->execute([$teamId, $clubId]);
        $team = $team->fetch(PDO::FETCH_ASSOC);
        if (!$team) {
            throw new RuntimeException('Team not found for this club.');
        }

        $slots = self::SCHEDULE_SLOTS;
        $stmt  = $this->pdo->prepare(
            "SELECT e.name, e.event_date, e.start_time, e.type, e.opponent_name, e.location,
                    v.name AS venue_name
               FROM calendar_events e
               JOIN calendar_event_teams cet ON cet.event_id = e.id
          LEFT JOIN venues v ON v.id = e.venue_id
              WHERE cet.team_id = ?
                AND e.event_date >= CURRENT_DATE
                AND e.event_date < CURRENT_DATE + INTERVAL '7 days'
                AND e.status <> 'cancelled'
           ORDER BY e.event_date, e.start_time
              LIMIT {$slots}"
        );
        $stmt->execute([$teamId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$events) {
            // Better to say so than to render an empty schedule that looks like a
            // design fault to whoever posts it.
            throw new RuntimeException('That team has nothing scheduled in the next 7 days.');
        }

        $utc      = new DateTimeZone('UTC');
        $values   = [
            'team_name' => (string) $team['name'],
            'club_name' => $this->clubName($clubId),
        ];
        $optional = [];

        for ($i = 1; $i <= $slots; $i++) {
            $event = $events[$i - 1] ?? null;

            $date = $event && $event['event_date']
                ? DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $event['event_date'], 0, 10), $utc)
                : null;
            $time = $event && $event['start_time']
                ? DateTimeImmutable::createFromFormat('H:i:s', substr((string) $event['start_time'], 0, 8), $utc)
                : null;

            $values["event_{$i}_name"]     = $event ? (string) $event['name'] : '';
            $values["event_{$i}_date"]     = $date ? $date->format('D, M j') : '';
            $values["event_{$i}_day"]      = $date ? $date->format('l') : '';
            $values["event_{$i}_time"]     = $time ? $time->format('g:i A') : '';
            $values["event_{$i}_type"]     = $event ? ucfirst((string) $event['type']) : '';
            $values["event_{$i}_venue"]    = $event ? (string) ($event['venue_name'] ?: ($event['location'] ?? '')) : '';
            $values["event_{$i}_opponent"] = $event ? (string) ($event['opponent_name'] ?? '') : '';

            foreach (['name', 'date', 'day', 'time', 'type', 'venue', 'opponent'] as $part) {
                $optional[] = "event_{$i}_{$part}";
            }
        }

        $first = $events[0];
        $last  = $events[count($events) - 1];
        $fmt   = function ($raw) use ($utc) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $raw, 0, 10), $utc);
            return $d ? $d->format('M j') : '';
        };
        $values['week_range'] = $first['event_date'] === $last['event_date']
            ? $fmt($first['event_date'])
            : $fmt($first['event_date']) . ' – ' . $fmt($last['event_date']);

        return [
            'kind'     => 'team',
            'label'    => $team['name'] . ' — this week',
            'values'   => $values,
            'optional' => $optional,
            'images'   => [
                'club_logo' => fn() => $this->clubLogoBytes($clubId),
            ],
        ];
    }

    /**
     * Match our values to the template's declared fields.
     *
     * ⚠️ A template field we cannot fill is a HARD ERROR, not an omission. Canva
     * leaves an unsupplied field at its template default, so the graphic ships
     * saying "SPONSOR NAME" in 90pt across the middle. A missing graphic is
     * recoverable; one posted to Instagram with placeholder text is not.
     */
    private function buildPayload(array $fields, array $subject): array
    {
        $data        = [];
        $missing     = [];   // we know this field; the record has no value for it
        $unknown     = [];   // we have no source for this field name at all
        $unsupported = [];   // a type we cannot fill yet, e.g. an image

        foreach ($fields as $name => $spec) {
            $type = $spec['type'] ?? '';

            if ($type === 'text') {
                $known = array_key_exists($name, $subject['values']);
                $value = $known ? $subject['values'][$name] : '';

                // An empty value is only acceptable for a field the subject
                // declared optional — today that is the schedule slots, where a
                // blank row is the correct rendering for a team with fewer events
                // than the template has rows.
                if (($value === '' || $value === null)
                    && !in_array($name, $subject['optional'] ?? [], true)) {
                    // Known-but-empty and unknown-entirely are different problems
                    // with different fixes: one is data to type into Teams
                    // Elevated, the other is a field name to correct in Canva.
                    // Collapsing them into "cannot fill" sends people to the
                    // wrong place.
                    if ($known) {
                        $missing[] = $name;
                    } else {
                        $unknown[] = $name;
                    }
                    continue;
                }
                $data[$name] = ['type' => 'text', 'text' => (string) $value];
                continue;
            }

            if ($type === 'image') {
                $loader = $subject['images'][$name] ?? null;
                if (!$loader) {
                    $unknown[] = $name;
                    continue;
                }

                $binary = $loader();
                if ($binary === null || $binary === '') {
                    $missing[] = $name;
                    continue;
                }

                $data[$name] = ['type' => 'image', 'asset_id' => $this->assetIdFor($name, $binary)];
                continue;
            }

            // Any other Canva field type — charts today. Nothing here can fill one,
            // and an empty chart on a published graphic is worse than a refusal.
            $unsupported[] = $name;
        }

        if ($missing || $unknown || $unsupported) {
            throw new RuntimeException(
                $this->explainUnfillable($subject, $missing, $unknown, $unsupported)
            );
        }

        return $data;
    }

    /**
     * Turn unfillable fields into something a club admin can act on.
     *
     * Three different problems, three different fixes, and the person reading
     * this is looking at a modal in Teams Elevated with no idea what a "field"
     * is in Canva:
     *
     *   missing     — we know the field, the record is blank. They fix it here.
     *   unknown     — the template asks for a name we have no source for. That is
     *                 a template problem, usually a typo, and a nearest-match
     *                 suggestion turns "oponent is unknown" into "did you mean
     *                 opponent".
     *   unsupported — a field type we cannot fill yet (images).
     */
    private function explainUnfillable(array $subject, array $missing, array $unknown, array $unsupported): string
    {
        $noun  = self::SUBJECT_NOUNS[$subject['kind'] ?? ''] ?? 'record';
        $parts = [];

        if ($missing) {
            $labels = array_map(fn($f) => self::FIELD_LABELS[$f] ?? $this->readableField($f), $missing);
            $parts[] = 'This ' . $noun . ' has no ' . $this->joinList($labels) . '. '
                . 'Add ' . (count($labels) === 1 ? 'it' : 'them') . ' and try again.';
        }

        if ($unknown) {
            $known = array_keys($subject['values']);
            $notes = [];
            foreach ($unknown as $field) {
                $suggestion = $this->closestField($field, $known);
                $notes[] = $suggestion
                    ? "\"{$field}\" (did you mean \"{$suggestion}\"?)"
                    : "\"{$field}\"";
            }
            $parts[] = 'The Canva template asks for ' . $this->joinList($notes)
                . ', which is not something we can supply. Rename the data '
                . (count($notes) === 1 ? 'field' : 'fields') . ' in Canva and republish the template.';
        }

        if ($unsupported) {
            $parts[] = 'The template uses ' . $this->joinList(array_map(fn($f) => "\"{$f}\"", $unsupported))
                . ', and image fields are not supported yet.';
        }

        return implode(' ', $parts);
    }

    /** "event_venue_name" -> "an event venue name", for fields with no label. */
    private function readableField(string $field): string
    {
        $words  = str_replace('_', ' ', $field);
        $vowel  = in_array(strtolower($words[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true);

        return ($vowel ? 'an ' : 'a ') . $words;
    }

    /**
     * Nearest field we DO supply, or null when nothing is close.
     *
     * The threshold matters: suggesting "team_name" for "headline" would be
     * worse than saying nothing, because it sends someone to rename a field that
     * was never the problem.
     */
    private function closestField(string $field, array $known): ?string
    {
        $best     = null;
        $bestDist = PHP_INT_MAX;

        foreach ($known as $candidate) {
            $dist = levenshtein(strtolower($field), strtolower($candidate));
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $candidate;
            }
        }

        // Allow roughly a third of the name to differ — enough for a typo or a
        // singular/plural slip, not enough for an unrelated word.
        return $bestDist <= max(2, (int) floor(strlen($field) / 3)) ? $best : null;
    }

    /** "a, b and c" — Oxford-comma-free, because this lands in a UI sentence. */
    private function joinList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

    /** Autofill, export, download. Returns the bytes and what produced them. */
    private function render(string $brandTemplateId, array $data, string $label): array
    {
        $job    = $this->canva->createDesignAutofillJob($brandTemplateId, $data, "Teams Elevated — {$label}");
        $jobId  = $job['job']['id'] ?? null;
        $done   = $this->canva->pollJob(fn() => $this->canva->getDesignAutofillJob($jobId));

        $designId = $done['result']['design']['id'] ?? null;
        $editUrl  = $done['result']['design']['urls']['edit_url'] ?? null;
        if (!$designId) {
            throw new RuntimeException('Canva autofilled but returned no design.');
        }

        $export     = $this->canva->createDesignExportJob($designId, ['type' => 'png', 'quality' => 'pro']);
        $exportId   = $export['job']['id'] ?? null;
        $exportDone = $this->canva->pollJob(fn() => $this->canva->getDesignExportJob($exportId));

        $url = $exportDone['urls'][0] ?? ($exportDone['result']['urls'][0] ?? null);
        if (!$url) {
            throw new RuntimeException('Canva exported but returned no download URL.');
        }

        // ⚠️ Download NOW. Canva's export URLs expire, and the design behind them
        // may later be deleted — a media library holding only URLs is a library
        // of broken images tomorrow.
        $bytes = @file_get_contents($url);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Canva export URL returned no bytes.');
        }

        return [
            'bytes'     => $bytes,
            'design_id' => $designId,
            'edit_url'  => $editUrl,
        ];
    }

    /**
     * Persist the PNG.
     *
     * ⚠️ image_data is BYTEA and MUST go in as hex — see lib/bytea.php. Binding
     * raw binary makes execute() return false WITHOUT throwing, because emulated
     * prepares build the statement client-side and libpq cuts it at the PNG's
     * first NUL byte. Then the stored length is re-read, because the entire
     * failure mode is a write that reports success.
     */
    private function store(int $assetId, array $png): void
    {
        $bytes = $png['bytes'];
        $size  = strlen($bytes);
        $dims  = @getimagesizefromstring($bytes) ?: [null, null];

        $stmt = $this->pdo->prepare(
            "UPDATE club_media_assets
                SET canva_design_id = ?, canva_edit_url = ?,
                    image_data = " . TE_BYTEA_PARAM . ", mime_type = 'image/png',
                    file_size = ?, width = ?, height = ?, status = 'ready',
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $ok = $stmt->execute([
            $png['design_id'], $png['edit_url'], te_bytea_hex($bytes),
            $size, $dims[0], $dims[1], $assetId,
        ]);

        if (!$ok || $stmt->rowCount() !== 1) {
            throw new RuntimeException('The graphic was generated but could not be saved.');
        }

        $stored = te_bytea_stored_length($this->pdo, 'club_media_assets', 'image_data', 'id', $assetId);
        if ($stored !== $size) {
            throw new RuntimeException(
                'The graphic was generated but stored incompletely '
                . '(' . var_export($stored, true) . " of {$size} bytes)."
            );
        }
    }
}
