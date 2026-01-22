# Chamber Status Repair Script

**Purpose**: One-time script to retroactively populate the `chamber` column in the `bills_status` table.

**Related Issue**: [richmondsunlight.com#880](https://github.com/openva/richmondsunlight.com/issues/880)

## What This Script Does

1. **Adds the `chamber` column** to `bills_status` table if it doesn't exist
   - Column type: `ENUM('house','senate') DEFAULT NULL`
   - Positioned after the `status` column

2. **Populates chamber data** for all bills and joint resolutions (HB, SB, HJ, SJ)
   - Determines originating chamber from bill number prefix:
     - `hb*` and `hj*` → house
     - `sb*` and `sj*` → senate

3. **Handles chamber transitions** based on status translations:
   - All statuses start in the originating chamber
   - When a status contains "passed house" in the translation:
     - That status is tagged as 'house'
     - All subsequent statuses are tagged as 'senate' (until another passage)
   - When a status contains "passed senate" in the translation:
     - That status is tagged as 'senate'
     - All subsequent statuses are tagged as 'house' (until another passage)

4. **Handles multiple crossovers**: Bills that pass back and forth between chambers are tracked correctly

5. **Adds an index** on the chamber column for query performance

## Usage

### Dry Run (Preview Changes)
```bash
docker exec rs_machine php deploy/repair_chamber_status.php --dry-run
```

### Execute Changes
```bash
docker exec rs_machine php deploy/repair_chamber_status.php
```

### On Production Server
```bash
php deploy/repair_chamber_status.php
```

## Verification

After running the script, verify the results:

```sql
-- Count by chamber
SELECT chamber, COUNT(*) FROM bills_status GROUP BY chamber;

-- Sample bill history with chamber transitions
SELECT b.number, bs.chamber, DATE(bs.date) as date, bs.translation
FROM bills_status bs
JOIN bills b ON bs.bill_id = b.id
WHERE b.number = 'hb1'
ORDER BY bs.date;
```

Expected results:
- Most statuses should have a chamber value ('house' or 'senate')
- NULL values are expected for resolutions (hr, sr) since they don't cross chambers
- House bills (hb) should start in 'house', transition to 'senate' after "passed house", etc.
- Senate bills (sb) should start in 'senate', transition to 'house' after "passed senate", etc.

## Logic Details

The script processes each bill's status history chronologically and applies this logic:

1. Start with originating chamber (based on bill prefix)
2. For each status in chronological order:
   - Check the `translation` column for "passed house" or "passed senate"
   - If found: tag that status with the chamber where passage occurred, then switch to opposite chamber
   - If not found: tag the status with the current chamber
3. Continue until all statuses are processed

### Example: HB100 (House Bill)

| Date | Translation | Chamber | Reason |
|------|-------------|---------|---------|
| Jan 10 | Introduced | house | Originating chamber |
| Jan 15 | In committee | house | Still in originating chamber |
| Jan 20 | Passed house | house | Passage occurred in house |
| Jan 21 | In committee | senate | After house passage, moves to senate |
| Feb 05 | Passed senate | senate | Passage occurred in senate |
| Feb 06 | Enrolled | house | After senate passage, returns to house |

## Files Modified

- `bills_status` table: adds `chamber` column and index
- Tests: `deploy/tests/RepairChamberStatusTest.php` validates the logic

## Next Steps (Per Issue #880)

After running this script:

1. ✅ Add chamber column to bills_status table
2. ✅ Retroactively populate chamber data
3. ⬜ Update `cron/history.php` to record chamber for new statuses (already done in class.Import.php)
4. ⬜ Add NOT NULL constraint to chamber column (after confirming all new statuses capture chamber)

## Disposal

This is a **one-time repair script**. After:
1. Running it successfully in production
2. Verifying the data is correct
3. Updating the production database dump

You can delete:
- `deploy/repair_chamber_status.php`
- `deploy/repair_chamber_status_README.md` (this file)

The test file `deploy/tests/RepairChamberStatusTest.php` can remain as documentation of the chamber transition logic.
