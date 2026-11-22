# Import Logs Quick Reference Guide

## What Gets Logged

### Per Import Session
- **One ImportLog record per import** (per enrollment)
- **All principals** in that import with field mappings
- **All dependents** in that import with field mappings
- **Date format detection** and confidence level
- **Summary statistics** (created/updated counts)
- **Timestamp** for each record action

### Field Mappings Captured

#### Principals (Enrollees)
✓ Personal: first_name, last_name, middle_name, birth_date, gender, nationality, marital_status
✓ Employment: employee_id, employment_start_date, employment_end_date, department, position
✓ Contact: email1, phone1, address
✓ Status: enrollment_status

#### Dependents
✓ Personal: first_name, last_name, middle_name, relation, birth_date, gender, nationality, marital_status
✓ Status: enrollment_status

#### Health Insurance (Both)
✓ Certificate: certificate_number, certificate_date_issued
✓ Coverage: coverage_start_date, coverage_end_date, plan, premium
✓ Status: is_company_paid, is_renewal, is_skipping, reason_for_skipping

## How to Use

### Step 1: Import Data
```bash
POST /api/v1/import
Body: {
  "enrollment_id": 123,
  "enrollees": [...]
}

Response: {
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

### Step 2: Save the Import Log ID
Store `import_log_id: 456` for later retrieval

### Step 3: Download Report
```bash
GET /api/v1/import-logs/456/download

Returns: Text file download
Filename: import_log_enrollment-123_2026-07-15_143015.txt
```

### Step 4: Review or Archive
- Open the text file in any editor
- Review all imported records
- Archive for audit trail
- Share with stakeholders

## Super Admin Access

Super admin can:
1. Query all imports: `SELECT * FROM cm_import_logs ORDER BY import_date DESC`
2. Filter by enrollment: `SELECT * FROM cm_import_logs WHERE enrollment_id = ? ORDER BY import_date DESC`
3. Check status: `SELECT * FROM cm_import_logs WHERE status = 'success'`
4. Download any import report via API endpoint

## Format of Downloaded Report

### Header Section
```
Import Log ID: 456
Enrollment ID: 123
Import Date: 2026-07-15 14:30:00
Status: SUCCESS
Date Format Detected: DD/MM/YYYY
Date Confidence: 95%
```

### Summary Section
```
Total Principals: 25
Principals Created: 15
Principals Updated: 10
Total Dependents: 45
Dependents Created: 30
Dependents Updated: 15
```

### Detailed Section
For each principal/dependent:
- All field values imported
- Health insurance information
- Changes made (if updated)
- Timestamp of import

## Error Handling

If import fails:
- Status set to 'failed'
- Error message captured in `error_message` column
- Import log still saved for debugging
- Download report will show error details

## Database Queries

### View All Imports
```sql
SELECT id, enrollment_id, import_date, status, 
       principals_created, dependents_created 
FROM cm_import_logs 
ORDER BY import_date DESC;
```

### View Imports for Specific Enrollment
```sql
SELECT * FROM cm_import_logs 
WHERE enrollment_id = 123 
ORDER BY import_date DESC;
```

### View Failed Imports
```sql
SELECT * FROM cm_import_logs 
WHERE status = 'failed' 
ORDER BY import_date DESC;
```

### View Import Details (JSON)
```sql
SELECT id, import_date, 
       JSON_EXTRACT(import_details, '$.summary') as summary,
       JSON_EXTRACT(import_details, '$.principals') as principals
FROM cm_import_logs 
WHERE id = 456;
```

## Response Includes Import Log ID

Both import endpoints now return the `import_log_id`:
- `POST /api/v1/import`
- `POST /api/v1/import-with-company-and-provider`

Use this ID to download the detailed report immediately after import.

## Text Report Contents

Each downloadable report includes:

1. **Header**: Import metadata (ID, date, status)
2. **Summary**: Statistics (created/updated counts)
3. **Principals Section**:
   - Each principal numbered
   - All enrollee fields with values
   - All health insurance fields with values
   - Any changes made (if updating)
4. **Dependents Section**:
   - Each dependent numbered
   - All dependent fields with values
   - All health insurance fields with values
   - Any changes made (if updating)
5. **Footer**: End marker

## Per-Import Tracking

✓ One complete log per import session
✓ Covers all principals and their dependents
✓ Tracks all field mappings
✓ Records creation vs updates
✓ Captures any errors
✓ Provides downloadable audit trail
✓ Indexed by enrollment_id and import_date for easy retrieval

---

**All imports are now fully traceable with complete field-level visibility for super admin review.**
