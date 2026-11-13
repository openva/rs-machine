<?php

namespace RsMachine;

use PDO;

/**
 * Tags bills with the places that they name (in bills_places), and records that each bill has been
 * checked (bills.places_checked), whether or not it names any. A bill is checked again when its
 * text changes (cron/full_text.php clears places_checked).
 *
 * Counties and cities that a bill names plainly are found without asking GPT. Only bills that name
 * places in other ways are left for it, a few each run.
 */
class PlaceTagger
{
    private PDO $db;

    /** @var callable(array): ?string Gets the text of a bill to look for places in, or null. */
    private $fetchText;

    /** @var callable(string): ?array Asks a model for the place names in a text, or null. */
    private $askModel;

    /** @var callable(): bool Whether the model can be asked now. */
    private $modelAvailable;

    /** @var array<int, array{name: string, county: bool, latitude: float, longitude: float}>|null */
    private ?array $localities = null;

    /**
     * @param callable(array): ?string|null $fetchText Given a bill (its id, number, and year),
     *     returns the text to look for places in, or null if it couldn't be retrieved. By default,
     *     it's read from the database (see textFromDatabase()); this is for tests to replace.
     * @param callable(string): ?array $askModel Given a text, returns the names of the places that
     *     a model found in it (an empty list if there are none), or null if it couldn't be asked.
     * @param callable(): bool|null $modelAvailable Whether the model can be asked now (it can't
     *     while OpenAI's spend limit is reached, say). Bills that need it wait while it can't, rather
     *     than being counted as failures. It's asked again after a failed request, since the request
     *     may be what found the model unavailable. By default, it's always available.
     */
    public function __construct(PDO $db, ?callable $fetchText, callable $askModel, ?callable $modelAvailable = null)
    {
        $this->db = $db;
        $this->fetchText = $fetchText ?? fn (array $bill): ?string => $this->textFromDatabase($bill);
        $this->askModel = $askModel;
        $this->modelAvailable = $modelAvailable ?? fn (): bool => true;
    }

    /**
     * The text of a bill to look for places in, from the database: its summary, and the language
     * that its latest version changes (see PlaceNames::billText()).
     *
     * @param array{id: int} $bill
     * @return string|null The text, or null if there's no such bill.
     */
    public function textFromDatabase(array $bill): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT bills.summary, latest.text
            FROM bills
            LEFT JOIN bills_full_text AS latest
                ON latest.id = (
                    SELECT versions.id
                    FROM bills_full_text AS versions
                    WHERE
                        versions.bill_id = bills.id
                        AND versions.text IS NOT NULL
                        AND versions.text <> ""
                    ORDER BY versions.date_introduced DESC, versions.id DESC
                    LIMIT 1
                )
            WHERE bills.id = :bill_id'
        );
        $stmt->execute([':bill_id' => $bill['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return PlaceNames::billText((object) ['summary' => $row['summary'], 'full_text' => $row['text']]);
    }

    /**
     * Bills that haven't been checked for places, newest first: from FIRST_YEAR on, with text.
     *
     * @return array<int, array{id: int, number: string, year: int}>
     */
    public function candidates(int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT bills.id, bills.number, sessions.year
            FROM bills
            JOIN sessions
                ON sessions.id = bills.session_id
            WHERE
                sessions.year >= :first_year
                AND bills.places_checked IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM bills_full_text
                    WHERE
                        bills_full_text.bill_id = bills.id
                        AND bills_full_text.text IS NOT NULL
                        AND bills_full_text.text <> ""
                )
            ORDER BY sessions.year DESC, bills.id DESC
            LIMIT :limit'
        );
        $stmt->bindValue(':first_year', PlaceNames::FIRST_YEAR, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn ($row) => ['id' => (int) $row['id'], 'number' => $row['number'], 'year' => (int) $row['year']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * The counties and cities in the gazetteer, which exact matching can find. A county's
     * municipality is NULL, and an independent city's is its name followed by " (city)".
     *
     * @return array<int, array{name: string, county: bool, latitude: float, longitude: float}>
     */
    private function localities(): array
    {
        if ($this->localities === null) {
            $stmt = $this->db->query(
                'SELECT name, municipality IS NULL AS county, latitude, longitude
                FROM gazetteer
                WHERE municipality IS NULL OR municipality = CONCAT(name, " (city)")'
            );
            $this->localities = array_map(fn ($row) => [
                'name' => $row['name'],
                'county' => (bool) $row['county'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $this->localities;
    }

    /**
     * Find a place that a model named, in the gazetteer. A place isn't found if more than one place
     * has its name, since there's no way to choose between them.
     *
     * @return array{name: string, latitude: float, longitude: float}|null
     */
    private function find(string $place): ?array
    {
        $lookup = PlaceNames::lookup($place);
        if ($lookup === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT name, latitude, longitude
            FROM gazetteer
            WHERE name = :name AND municipality IS ' . ($lookup['county'] ? '' : 'NOT ') . 'NULL'
        );
        $stmt->execute([':name' => $lookup['name']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || empty($rows[0]['latitude']) || empty($rows[0]['longitude'])) {
            return null;
        }
        return [
            'name' => $rows[0]['name'],
            'latitude' => (float) $rows[0]['latitude'],
            'longitude' => (float) $rows[0]['longitude'],
        ];
    }

    /**
     * Replace the places that a bill is tagged with, and record that it's been checked. The old
     * tags go, because they're from text that may have changed.
     *
     * @param array<int, array{name: string, latitude: float, longitude: float}> $places
     */
    public function store(int $bill_id, array $places): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('DELETE FROM bills_places WHERE bill_id = :bill_id');
            $stmt->execute([':bill_id' => $bill_id]);

            $stmt = $this->db->prepare(
                'INSERT INTO bills_places (bill_id, placename, latitude, longitude, coordinates)
                VALUES (:bill_id, :name, :latitude, :longitude, Point(:longitude2, :latitude2))
                ON DUPLICATE KEY UPDATE date_modified = NOW()'
            );
            foreach ($places as $place) {
                $stmt->execute([
                    ':bill_id' => $bill_id,
                    ':name' => $place['name'],
                    ':latitude' => $place['latitude'],
                    ':longitude' => $place['longitude'],
                    ':latitude2' => $place['latitude'],
                    ':longitude2' => $place['longitude'],
                ]);
            }

            // The bill's date_modified is left alone, since it hasn't changed.
            $stmt = $this->db->prepare(
                'UPDATE bills SET places_checked = NOW(), date_modified = date_modified WHERE id = :bill_id'
            );
            $stmt->execute([':bill_id' => $bill_id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Check bills for places.
     *
     * @param int $max_bills The most bills to check this time.
     * @param int $max_model_calls The most bills to ask a model about this time. Bills that need it
     *     beyond that are left for next time.
     * @param int|null $stop_at A time, as a timestamp, after which no more bills are started.
     * @return array{checked: int, exact: int, model: int, tagged: int, unretrieved: int, waiting: int,
     *     checked_ids: int[]}
     *     How many bills were checked (and so, how many of those were settled without a model, and
     *     how many with one), how many places they were tagged with, how many couldn't be checked,
     *     how many are waiting for a model (because this run's allocation is used up, or the model
     *     can't be asked now), and which bills were checked.
     */
    public function run(int $max_bills, int $max_model_calls, ?int $stop_at = null): array
    {
        $stats = ['checked' => 0, 'exact' => 0, 'model' => 0, 'tagged' => 0, 'unretrieved' => 0,
            'waiting' => 0, 'checked_ids' => []];

        foreach ($this->candidates($max_bills) as $bill) {
            if ($stop_at !== null && time() >= $stop_at) {
                break;
            }

            $text = ($this->fetchText)($bill);
            if ($text === null || strlen($text) < 8) {
                $stats['unretrieved']++;
                continue;
            }

            $found = PlaceNames::fromText($text, $this->localities());
            $by_name = array_column($this->localities(), null, 'name');
            $places = [];
            foreach ($found['places'] as $name) {
                $places[$name] = $by_name[$name];
            }

            $used_model = false;
            if (!$found['settled']) {
                if ($max_model_calls <= 0 || !($this->modelAvailable)()) {
                    $stats['waiting']++;
                    continue;
                }
                $answer = ($this->askModel)(PlaceNames::forModel($text));
                if ($answer === null) {
                    $stats[($this->modelAvailable)() ? 'unretrieved' : 'waiting']++;
                    continue;
                }
                $max_model_calls--;
                $used_model = true;
                foreach ($answer as $place) {
                    $match = $this->find($place);
                    if ($match !== null) {
                        $places[$match['name']] = $match;
                    }
                }
            }

            $this->store($bill['id'], array_values($places));
            $stats['checked']++;
            $stats[$used_model ? 'model' : 'exact']++;
            $stats['tagged'] += count($places);
            $stats['checked_ids'][] = $bill['id'];
        }

        return $stats;
    }
}
