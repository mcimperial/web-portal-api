# Quick Start: Import Logs Feature

## 30-Second Overview

Added comprehensive logging for all imports. Each import now:
- ✅ Logs all principals and their fields
- ✅ Logs all dependents and their fields  
- ✅ Tracks all health insurance information
- ✅ Records what was created/updated
- ✅ Can be downloaded as a text report by super admin

## What Changed

### 1. Import Response Now Includes Log ID
```json
{
  "message": "Import successful",
  "import_log_id": 456,
  "summary": {
    "total_principals": 25,
    "principals_created": 15,
    "principals_updated": 10,
    "total_dependents": 45,
    "dependents_created": 30,
    "dependents_updated": 15
  }
}
```

### 2. New Endpoint to Download Report
```
GET /api/v1/import-logs/{import_log_id}/download
```
Downloads: `import_log_enrollment-{enrollment_id}_{timestamp}.txt`

## Deploy Steps

1. **Run Migration** (Required!)
   ```bash
   php artisan migrate
   ```
   This creates the `cm_import_logs` table.

2. **Test It**
   ```bash
   # Import something
   POST /api/v1/import
   
   # Get the import_log_id from response
   
   # Download the report
   GET /api/v1/import-logs/{import_log_id}/download
   ```

## What Gets Logged

### Per Each Import Session:
✓ All principals imported
✓ All dependents imported
✓ All fields from each record
✓ All health insurance details
✓ What was created vs updated
✓ Changes made (if updating)
✓ Date format used
✓ Success/failure status

### Field Groups Captured:
- **Enrollee**: name, birth date, employment dates, contact info, etc.
- **Dependent**: name, relation, birth date, contact info, etc.
- **Insurance**: certificate, plan, premium, coverage dates, etc.

## Download Report Contents

```
IMPORT INFORMATION
  ID, Enrollment, Date, Status, Format

SUMMARY STATISTICS
  Created/Updated counts

PRINCIPALS SECTION
  For each principal:
    - All personal & employment fields
    - All insurance details
    - Changes made (if updated)

DEPENDENTS SECTION
  For each dependent:
    - All personal & health fields
    - All insurance details
    - Changes made (if updated)
```

## Use Cases

### Super Admin Reviews
```bash
# Get all imports for an enrollment
SELECT * FROM cm_import_logs WHERE enrollment_id = 123 ORDER BY import_date DESC

# Download any report
GET /api/v1/import-logs/{id}/download
```

### Audit Trail
- Every import is logged with complete details
- See exactly what was imported and when
- Track all changes to existing records

### Troubleshooting
- Check import status: `success` or `failed`
- View error messages if import failed
- See all field values that were imported

### Compliance
- Complete audit trail per import
- All changes documented
- Timestamps for all actions
- Archived as text files

## Database Schema

```sql
Table: cm_import_logs
- id (PK)
- enrollment_id (FK) - indexed
- import_date (timestamp) - indexed
- status (success/failed/partial)
- total_principals, principals_created, principals_updated
- total_dependents, dependents_created, dependents_updated
- import_details (JSON) - all the detailed data
- date_format_detected (string)
- date_format_confidence (string)
- error_message (text)
```

## Files Added/Changed

### New:
- Model: `ImportLog.php`
- Migration: `2026_07_15_000000_create_import_logs_table.php`

### Modified:
- Controller: `ImportEnrolleeController.php` (added logging)
- Routes: `routes/api.php` (added download endpoint)

## API Response Examples

### Import (with logging)
```bash
POST /api/v1/import
{
  "enrollment_id": 15,
  "enrollees": [...]
}

RESPONSE:
{
  "message": "Import successful",
  "import_log_id": 42,
  "summary": {
    "total_principals": 3,
    "principals_created": 2,
    "principals_updated": 1,
    "total_dependents": 5,
    "dependents_created": 4,
    "dependents_updated": 1
  }
}
```

### Download Report
```bash
GET /api/v1/import-logs/42/download

RESPONSE: (text file download)
Filename: import_log_enrollment-15_2026-07-15_143015.txt
```

## Key Features

✅ **Per-Import Tracking** - One log per import session
✅ **Complete Field Visibility** - See all data that was imported
✅ **Change Tracking** - Know what changed when updating
✅ **Downloadable Reports** - Text format for easy review
✅ **Error Capture** - Failed imports recorded with error details
✅ **Date Format Detection** - Shows what format was detected
✅ **Indexed Queries** - Fast lookup by enrollment or date
✅ **JSON Storage** - Preserves all nested data

## Quick Commands

```bash
# View all imports
SELECT * FROM cm_import_logs ORDER BY import_date DESC LIMIT 10;

# View imports for enrollment 15
SELECT * FROM cm_import_logs WHERE enrollment_id = 15 ORDER BY import_date DESC;

# View failed imports
SELECT * FROM cm_import_logs WHERE status = 'failed';

# Count imports per enrollment
SELECT enrollment_id, COUNT(*) as imports FROM cm_import_logs GROUP BY enrollment_id;
```

## Troubleshooting

### No import_log_id in response?
- Make sure you ran the migration: `php artisan migrate`
- Check that ImportLog model is imported in controller

### Download returns 404?
- Check import_log_id exists in database
- Verify enrollment relationship exists

### Report looks empty?
- Check that import actually imported records
- Review import_details JSON for data

## Next Steps

1. ✅ Run migration: `php artisan migrate`
2. ✅ Test import endpoint
3. ✅ Note the import_log_id
4. ✅ Download report: `GET /api/v1/import-logs/{id}/download`
5. ✅ Review the generated text file
6. ✅ Archive or share as needed

---

**That's it! Logging is now active on every import.**
