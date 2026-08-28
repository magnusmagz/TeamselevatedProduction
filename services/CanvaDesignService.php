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
     * The template's autofillable fields, as Canva reports them.
     *
     * ⚠️ The cached copy is a HINT, never authority. A designer editing the
     * template in Canva changes the real dataset without telling us, and autofill
     * rejects the whole request on a field name that no longer exists — so a
     * stale cache does not degrade, it fails the send. $refresh re-reads from
     * Canva and re-caches.
     */
    public function fields(array $template, bool $refresh = false): array
    {
        if (!$refresh && !empty($template['dataset'])) {
            $cached = json_decode($template['dataset'], true);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $response = $this->canva->getBrandTemplateDataset($template['canva_brand_template_id']);
        $fields   = $response['dataset'] ?? [];

        $this->pdo->prepare(
            'UPDATE canva_brand_templates
                SET dataset = ?, dataset_fetched_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        )->execute([json_encode($fields), $template['id']]);

        return $fields;
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
     * Load the record the graphic is about, scoped to the club.
     *
     * The club check is here rather than at the caller because this is the layer
     * that turns an id from a request body into data — passing a sponsor id from
     * another club must find nothing, not silently render someone else's sponsor
     * under this club's branding.
     */
    private function loadSubject(int $clubId, string $graphicType, int $subjectId): array
    {
        if ($graphicType === 'sponsor_thanks') {
            $stmt = $this->pdo->prepare(
                'SELECT id, name, website FROM sponsors
                  WHERE id = ? AND club_id = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$subjectId, $clubId]);
            $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sponsor) {
                throw new RuntimeException('Sponsor not found for this club.');
            }

            return [
                'label'  => $sponsor['name'],
                'values' => [
                    'sponsor_name'    => $sponsor['name'],
                    'sponsor_website' => $sponsor['website'] ?? '',
                ],
            ];
        }

        throw new RuntimeException("No subject loader for {$graphicType}");
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
        $data    = [];
        $missing = [];

        foreach ($fields as $name => $spec) {
            $type = $spec['type'] ?? '';

            if ($type === 'text') {
                $value = $subject['values'][$name] ?? '';
                if ($value === '' || $value === null) {
                    $missing[] = $name;
                    continue;
                }
                $data[$name] = ['type' => 'text', 'text' => (string) $value];
                continue;
            }

            // Image fields need an uploaded Canva asset. Not wired yet — the first
            // template is text-only. Reaching here means a template declared an
            // image field before the upload path was connected, which must fail
            // loudly rather than render a graphic with an empty frame.
            $missing[] = "{$name} ({$type})";
        }

        if ($missing) {
            throw new RuntimeException(
                'The template asks for fields we cannot fill: ' . implode(', ', $missing)
            );
        }

        return $data;
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
