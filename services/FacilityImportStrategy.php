<?php
require_once __DIR__ . '/ImportStrategy.php';

/**
 * FacilityImportStrategy — imports venues + fields from a family-row CSV.
 *
 * One CSV row = one field + inline venue info. If the venue (matched by
 * name, case-insensitive) doesn't exist, it's auto-created from the row's
 * venue_* columns. If it does exist, we reuse it and only create a new
 * field underneath.
 *
 * Venues are GLOBAL in this schema — they are not scoped to a club.
 * Any club admin who imports a venue creates a row all clubs can see.
 * This mirrors the existing venues table design (no club_id column).
 * The import_jobs row still records club_profile_id for audit purposes.
 */

class FacilityImportStrategy extends ImportStrategy {
    // surface_type CHECK constraint values from the fields table
    private static $ALLOWED_SURFACE_TYPES = ['Grass', 'Turf', 'Indoor', 'Sand', 'Court'];

    public function getEntityType(): string {
        return 'facilities';
    }

    public function getRequiredFields(): array {
        return [
            'venue_name',
            'field_name',
        ];
    }

    public function getOptionalFields(): array {
        return [
            // Venue metadata (only used when auto-creating a new venue)
            'venue_address',
            'venue_city',
            'venue_state',
            'venue_zip_code',
            'venue_phone',
            'venue_type',
            'venue_notes',
            'venue_has_lights',
            // Field metadata
            'field_type',
            'surface_type',
            'dimensions',
            'capacity',
            'field_has_lights',
            'location_notes',
        ];
    }

    public function getFieldLabels(): array {
        return [
            'venue_name'       => 'Venue Name',
            'venue_address'    => 'Venue Address',
            'venue_city'       => 'Venue City',
            'venue_state'      => 'Venue State',
            'venue_zip_code'   => 'Venue Zip Code',
            'venue_phone'      => 'Venue Phone',
            'venue_type'       => 'Venue Type (e.g., Park, Indoor, Complex)',
            'venue_notes'      => 'Venue Notes',
            'venue_has_lights' => 'Venue Has Lights',
            'field_name'       => 'Field / Court Name',
            'field_type'       => 'Field Type (e.g., Soccer, Baseball)',
            'surface_type'     => 'Surface (Grass / Turf / Indoor / Sand / Court)',
            'dimensions'       => 'Dimensions',
            'capacity'         => 'Capacity',
            'field_has_lights' => 'Field Has Lights',
            'location_notes'   => 'Location Notes',
        ];
    }

    public function getSynonyms(): array {
        return [
            'venue_name'       => ['venuename', 'venue', 'facility', 'facilityname', 'location', 'locationname', 'park', 'parkname'],
            'venue_address'    => ['venueaddress', 'address', 'streetaddress', 'addressline1'],
            'venue_city'       => ['venuecity', 'city'],
            'venue_state'      => ['venuestate', 'state'],
            'venue_zip_code'   => ['venuezipcode', 'venuezip', 'zip', 'zipcode', 'postalcode'],
            'venue_phone'      => ['venuephone', 'phone', 'venuecontactphone'],
            'venue_type'       => ['venuetype', 'facilitytype', 'locationtype'],
            'venue_notes'      => ['venuenotes', 'notes'],
            'venue_has_lights' => ['venuehaslights', 'lights', 'lighting', 'haslights'],
            'field_name'       => ['fieldname', 'field', 'court', 'courtname', 'pitch', 'pitchname'],
            'field_type'       => ['fieldtype', 'sport', 'courttype'],
            'surface_type'     => ['surfacetype', 'surface'],
            'dimensions'       => ['dimensions', 'size', 'fieldsize'],
            'capacity'         => ['capacity', 'maxcapacity'],
            'field_has_lights' => ['fieldhaslights', 'fieldlights'],
            'location_notes'   => ['locationnotes', 'fieldnotes'],
        ];
    }

    public function processRow(array $row, array $mapping, array $context): string {
        /** @var PDO $pdo */
        $pdo = $context['pdo'];

        $venueName = $this->field($row, $mapping, 'venue_name');
        $fieldName = $this->field($row, $mapping, 'field_name');

        if ($venueName === '' || $fieldName === '') {
            throw new RuntimeException('Missing venue_name or field_name');
        }

        $surfaceType = $this->field($row, $mapping, 'surface_type');
        if ($surfaceType !== '' && !in_array($surfaceType, self::$ALLOWED_SURFACE_TYPES, true)) {
            throw new RuntimeException("Invalid surface_type '{$surfaceType}' — must be one of: " . implode(', ', self::$ALLOWED_SURFACE_TYPES));
        }

        $pdo->beginTransaction();
        try {
            $venueId = $this->findOrCreateVenue($pdo, $venueName, $row, $mapping);
            $outcome = $this->findOrCreateField($pdo, $venueId, $fieldName, $surfaceType, $row, $mapping);
            $pdo->commit();
            return $outcome;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────

    private function findOrCreateVenue(PDO $pdo, string $venueName, array $row, array $mapping): int {
        // Case-insensitive match on venue name — venues are global, one per name.
        $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $venueName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return (int) $existing['id'];

        $insert = $pdo->prepare('
            INSERT INTO venues
                (name, address, city, state, zip_code, phone, venue_type, notes, has_lights, active)
            VALUES
                (:name, :address, :city, :state, :zip, :phone, :type, :notes, :lights, true)
            RETURNING id
        ');
        $insert->execute([
            'name'    => $venueName,
            'address' => $this->strOrNull($this->field($row, $mapping, 'venue_address')),
            'city'    => $this->strOrNull($this->field($row, $mapping, 'venue_city')),
            'state'   => $this->strOrNull($this->field($row, $mapping, 'venue_state')),
            'zip'     => $this->strOrNull($this->field($row, $mapping, 'venue_zip_code')),
            'phone'   => $this->strOrNull($this->field($row, $mapping, 'venue_phone')),
            'type'    => $this->strOrNull($this->field($row, $mapping, 'venue_type')),
            'notes'   => $this->strOrNull($this->field($row, $mapping, 'venue_notes')),
            'lights'  => $this->parseBool($this->field($row, $mapping, 'venue_has_lights')) ? 'true' : 'false',
        ]);
        return (int) $insert->fetchColumn();
    }

    private function findOrCreateField(PDO $pdo, int $venueId, string $fieldName, string $surfaceType, array $row, array $mapping): string {
        // Fields match on (venue_id, name) exactly — case-sensitive because a venue
        // legitimately can have "Field A" and "field a" (unlikely but not forbidden).
        $stmt = $pdo->prepare('SELECT id FROM fields WHERE venue_id = :venue AND name = :name LIMIT 1');
        $stmt->execute(['venue' => $venueId, 'name' => $fieldName]);
        if ($stmt->fetch()) return 'skipped';

        $params = [
            'venue'     => $venueId,
            'name'      => $fieldName,
            'type'      => $this->strOrNull($this->field($row, $mapping, 'field_type')),
            'dims'      => $this->strOrNull($this->field($row, $mapping, 'dimensions')),
            'cap'       => $this->intOrNull($this->field($row, $mapping, 'capacity')),
            'lights'    => $this->parseBool($this->field($row, $mapping, 'field_has_lights')) ? 'true' : 'false',
            'locnotes'  => $this->strOrNull($this->field($row, $mapping, 'location_notes')),
        ];

        if ($surfaceType !== '') {
            // Explicit surface_type — include the column.
            $params['surface'] = $surfaceType;
            $sql = '
                INSERT INTO fields
                    (venue_id, name, field_type, surface_type, dimensions, capacity, has_lights, location_notes, active)
                VALUES
                    (:venue, :name, :type, :surface, :dims, :cap, :lights, :locnotes, true)
            ';
        } else {
            // Omit surface_type so the column default ("Grass") applies.
            $sql = '
                INSERT INTO fields
                    (venue_id, name, field_type, dimensions, capacity, has_lights, location_notes, active)
                VALUES
                    (:venue, :name, :type, :dims, :cap, :lights, :locnotes, true)
            ';
        }

        $pdo->prepare($sql)->execute($params);
        return 'created';
    }
}
