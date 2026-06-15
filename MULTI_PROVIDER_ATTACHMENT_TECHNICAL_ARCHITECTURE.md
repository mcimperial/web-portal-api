# Multi-Provider Attachment - Technical Architecture

## Overview
This document describes the technical implementation of multi-provider CSV attachment generation for notifications.

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                  Notification System                         │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ↓
        ┌────────────────────────┐
        │  SendNotificationCtrl   │
        │   send() method         │
        └──────────┬─────────────┘
                   │
                   ↓
        ┌────────────────────────┐
        │  sendSingleEmail()      │
        │  (modified)             │
        └──────────┬─────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
        ↓                     ↓
    Single CSV        Multiple CSVs
    (Original)        (New Feature)
        │                     │
        │              ┌──────┴──────┐
        │              │             │
        ↓              ↓             ↓
     Attach       generateMultiProviderCsvAttachments()
                  │
                  ├─→ Enrollment 1 → CSV → ZIP
                  ├─→ Enrollment 2 → CSV → ZIP
                  └─→ Enrollment N → CSV → ZIP
                  │
                  ↓
            [All Attachments]
                  │
                  ↓
        ┌────────────────────────┐
        │   Email Provider       │
        │ (Infobip / Laravel)    │
        └────────────────────────┘
```

---

## Data Flow

### Step 1: Notification Trigger
```
POST /api/notification/send
{
    "notification_id": 1,
    "to": "admin@company.com"
}
```

### Step 2: Status Check
```php
$statusResult = $this->checkNotificationStatus($notificationType);
// Returns: 
{
    'type' => 'csv_generation',
    'enrollment_id' => 123,
    'enrollment_status' => 'APPROVED',
    'date_from' => '2026-06-14',
    'date_to' => '2026-06-15'
}
```

### Step 3: Multi-Provider Generation
```php
$csvAttachments = $this->generateMultiProviderCsvAttachments($statusResult);
// Returns array of attachments:
[
    [
        'path' => '/tmp/xyz.zip',
        'name' => 'ENROLLEES_MAXICARE_APPROVED_20260615.zip',
        'provider' => 'MAXICARE',
        'data_rows' => 10
    ],
    [
        'path' => '/tmp/abc.zip',
        'name' => 'ENROLLEES_PHILCARE_APPROVED_20260615.zip',
        'provider' => 'PHILCARE',
        'data_rows' => 8
    ]
]
```

### Step 4: Email Dispatch
```php
foreach ($csvAttachments as $csv) {
    $message->attach($csv['path'], [
        'as' => $csv['name'],
        'mime' => 'application/zip'
    ]);
}
```

### Step 5: Cleanup
```php
// Delete all temp files
foreach ($csvAttachments as $csv) {
    @unlink($csv['path']);
    @unlink($csv['temp_path']);
    @unlink($csv['temp_csv']);
    @unlink($csv['temp_zip']);
}
```

---

## Database Schema

### Involved Tables

```sql
-- Company table
companies (id, company_name, company_code, ...)

-- Enrollments (Company + Provider combination)
cm_enrollment (
    id,
    company_id (FK → companies),
    insurance_provider_id (FK → cm_insurance_provider),
    status ('ACTIVE' or 'INACTIVE'),
    ...
)

-- Insurance Providers
cm_insurance_provider (
    id,
    title ('MAXICARE', 'PHILCARE', etc),
    ...
)

-- Employees (Enrollees)
cm_principal (
    id,
    enrollment_id (FK → cm_enrollment),
    company_id (FK → companies),
    first_name,
    last_name,
    employee_id,
    enrollment_status ('APPROVED', 'PENDING', etc),
    ...
)

-- Employee Insurance Details
cm_health_insurance (
    id,
    principal_id (FK → cm_principal),
    plan,
    certificate_number,
    certificate_date_issued,
    coverage_start_date,
    ...
)

-- Dependents
cm_dependent (
    id,
    principal_id (FK → cm_principal),
    first_name,
    last_name,
    ...
)

-- Notifications
cm_notification (
    id,
    enrollment_id (FK → cm_enrollment),
    notification_type,
    schedule (cron format),
    to,
    cc,
    bcc,
    ...
)
```

### Query Flow

```
1. Get notification
   SELECT * FROM cm_notification WHERE id = ?

2. Get enrollment
   SELECT * FROM cm_enrollment WHERE id = notification.enrollment_id

3. Get company ID
   SELECT company_id FROM cm_enrollment

4. Get all enrollments for company with different providers
   SELECT * FROM cm_enrollment 
   WHERE company_id = ? AND status = 'ACTIVE'

5. For each enrollment, get enrollees
   SELECT e.* FROM cm_principal e
   WHERE enrollment_id = ? 
   AND enrollment_status = 'APPROVED'
   AND status = 'ACTIVE'

6. Get health insurance details
   SELECT * FROM cm_health_insurance 
   WHERE principal_id IN (...)

7. Get dependents
   SELECT * FROM cm_dependent 
   WHERE principal_id IN (...)
```

---

## Code Components

### Main Entry Point: sendSingleEmail()

**Location**: `SendNotificationController.php:260-500`

**Responsibilities**:
- Parse email recipients (to, cc, bcc)
- Get static attachments
- Call CSV generation if needed
- Dispatch via email provider
- Clean up temp files

**Modified Code**:
```php
$csvAttachments = []; // Array instead of single
$csvAttachments = $this->generateMultiProviderCsvAttachments($statusResult, $notification);

// Handle multiple attachments
if (!empty($csvAttachments) && !$placeholderMessage) {
    foreach ($csvAttachments as $csv) {
        // Attach each ZIP
    }
}
```

---

### Core Logic: generateMultiProviderCsvAttachments()

**Location**: `SendNotificationController.php:1600-1800`

**Algorithm**:
```
1. Validate input
   - Get enrollment_id from statusResult
   - Extract parameters (status, dates, etc)

2. Get current enrollment
   - Fetch enrollment record
   - Extract company_id

3. Query for all enrollments in company
   - WHERE company_id = ?
   - AND status = 'ACTIVE'

4. For each enrollment (provider):
   a. Get provider name
   b. Query enrollees for this enrollment only
   c. Apply status filter (APPROVED)
   d. Apply date range filter
   e. Build CSV data
   f. Create temporary CSV file
   g. Zip and password-protect
   h. Add to results array

5. Return array of all attachments
```

**Key Decision Points**:
```php
// Skip if no enrollees
if ($enrollees->count() === 0) continue;

// Skip if enrollment inactive
if ($enrollment->status === 'INACTIVE') continue;

// Fallback if ZIP encryption fails
if (ZIP creation fails) use plain CSV else use ZIP
```

---

## Email Provider Integration

### Infobip Provider

**File Attachment Logic**:
```php
foreach ($csvAttachments as $csv) {
    $infobipAttachments[] = [
        'path' => $csv['path'],
        'name' => $csv['name']
    ];
}

$emailService = new EmailSender(
    $to,
    $messageBody,
    $subjectBody,
    'default',
    $infobipAttachments,  // Multiple attachments
    $cc,
    $bcc,
    []
);

$result = $emailService->send();
```

### Laravel Mail Provider

**File Attachment Logic**:
```php
Mail::send([], [], function ($message) use ($csvAttachments) {
    $message->to($to)->subject($subject);
    
    foreach ($csvAttachments as $csvAttachment) {
        $message->attach($csvAttachment['path'], [
            'as' => $csvAttachment['name'],
            'mime' => 'application/zip'
        ]);
    }
});
```

---

## Data Structure

### CSV Attachment Array

```php
[
    [
        'path' => '/tmp/csv_provider_abc123.zip',
        'name' => 'ENROLLEES_MAXICARE_APPROVED_20260615_143022.zip',
        'temp_path' => '/tmp/csv_provider_abc123',
        'temp_zip' => '/tmp/csv_provider_abc123.zip',
        'temp_csv' => '/tmp/csv_provider_abc123.csv',
        'has_data' => true,
        'data_rows' => 10,
        'provider' => 'MAXICARE',
        'enrollment_id' => 123
    ],
    [
        'path' => '/tmp/csv_provider_def456.zip',
        'name' => 'ENROLLEES_PHILCARE_APPROVED_20260615_143022.zip',
        'temp_path' => '/tmp/csv_provider_def456',
        'temp_zip' => '/tmp/csv_provider_def456.zip',
        'temp_csv' => '/tmp/csv_provider_def456.csv',
        'has_data' => true,
        'data_rows' => 8,
        'provider' => 'PHILCARE',
        'enrollment_id' => 124
    ]
]
```

---

## CSV Format

### Header Row
```
EMPLOYEE NAME | EMPLOYEE ID | PLAN SELECTED | CERTIFICATE NUMBER | ACTIVATION DATE | COVERAGE START DATE | ANY DEPENDENTS ENROLLED
```

### Data Row
```
John Smith | EMP-001 | MAXICARE GOLD | MC123456 | 06/15/2026 | 07/01/2026 | "Jane Smith
Bob Smith"
```

### Escaping Rules
```php
// Quotes doubled
"John ""Johnny"" Smith" → John ""Johnny"" Smith

// Multi-line wrapped in quotes
"Dependent1
Dependent2" → Wrapped in quotes with \n preserved

// Commas and quotes trigger quote wrapping
"Smith, Jr." → Wrapped in quotes
```

---

## File System Operations

### Temporary File Management

**Creation**:
```
/tmp/csv_provider_[randomstring]
/tmp/csv_provider_[randomstring].csv    ← CSV content
/tmp/csv_provider_[randomstring].zip    ← Zipped file
```

**Cleanup**:
```php
@unlink($tempPath);        // Original temp
@unlink($tempCsvPath);     // CSV file
@unlink($tempZipPath);     // ZIP file
```

**Timing**: After email sent (Infobip) or queued (Laravel Mail)

---

## Error Handling

### Exception Hierarchy

```
Exception
├─ Missing enrollment_id
│  └─ Log error → Return []
├─ Enrollment not found
│  └─ Log error → Return []
├─ No enrollees for provider
│  └─ Log info → Skip provider
├─ ZIP creation failed
│  └─ Fall back to plain CSV
└─ General exception
   └─ Log error → Return []
```

### Recovery Strategies

| Scenario | Action |
|----------|--------|
| No enrollees | Skip provider (no empty ZIP) |
| Inactive enrollment | Skip enrollment |
| ZIP fail | Use plain CSV |
| File permission error | Log and continue |
| Missing provider | Use 'UNKNOWN' |

---

## Performance Considerations

### Time Complexity
- **Database Queries**: O(n) where n = providers
- **CSV Generation**: O(m) where m = enrollees per provider
- **Overall**: O(n*m) = O(providers * enrollees)

### Space Complexity
- **Memory**: O(1) for streaming
- **Disk**: O(total_enrollees * record_size)
- **Temp**: Cleaned immediately after sending

### Optimization Points
```php
// Use Eloquent with relations loaded
->with(['healthInsurance', 'dependents'])

// Single query per enrollment
->where('enrollment_id', $enrollment->id)

// Date range filtering at DB level
->whereBetween('certificate_date_issued', [$from, $to])

// Immediate temp file cleanup
@unlink() after sending
```

---

## Testing Strategy

### Unit Tests

```php
// Test 1: Single provider
testSingleProviderAttachment() {
    // Assert 1 attachment generated
}

// Test 2: Multiple providers
testMultipleProviderAttachments() {
    // Assert N attachments generated
    // Verify provider names in filenames
}

// Test 3: No data
testNoDataAttachments() {
    // Assert empty array
    // Assert placeholder message
}

// Test 4: Invalid enrollment
testInvalidEnrollment() {
    // Assert error handling
}
```

### Integration Tests

```php
// Test CSV content accuracy
testCsvContentAccuracy() {
    // Generate CSV
    // Parse ZIP
    // Verify columns and data
}

// Test email dispatch
testEmailDispatch() {
    // Mock email provider
    // Verify attachments included
    // Verify cleanup occurred
}
```

---

## Configuration

### Environment Variables

```env
# Required
CSV_ATTACHMENT_PASSWORD=%!@#deElDCSV@#%!

# Email Provider
EMAIL_PROVIDER_SETTING=infobip
MAIL_FROM_ADDRESS=noreply@company.com

# Logging
APP_LOG=single
APP_DEBUG=false
```

### PHP Configuration

```php
// Required modules
- ZipArchive (PHP 5.3+)
- PDO (database)
- OpenSSL (encryption)

// Recommended
- PHP >= 7.2 (for EM_AES_256 encryption)
```

---

## Monitoring

### Logs to Check

```bash
# Application logs
storage/logs/laravel-*.log

# Query logging (if enabled)
storage/logs/query-*.log

# Email provider logs
storage/logs/email-provider-*.log
```

### Key Log Entries

```
[2026-06-15 14:30:22] local.INFO: Multi-provider CSV attachment generated successfully
[2026-06-15 14:30:23] local.INFO: Notification sent successfully
[2026-06-15 14:30:24] local.INFO: Attachment temp files cleaned up
```

### Error Patterns

```
❌ "No enrollment_id provided" 
   → Check statusResult data

❌ "Enrollment not found"
   → Verify enrollment exists in DB

❌ "No enrollees found"
   → Check employee records and status

❌ "Failed to open ZIP"
   → Check file permissions
```

---

## Security Considerations

### Password Protection
```php
// ZIP encrypted with AES-256
ZipArchive::setEncryptionName(
    $filename,
    ZipArchive::EM_AES_256,
    $csvPassword
)
```

### Temp File Security
```php
// Files in system temp (restricted access)
tempnam(sys_get_temp_dir(), 'csv_provider_')

// Immediate cleanup after send
@unlink($tempPath)
```

### Email Transmission
```php
// Uses configured mail provider
// TLS/SSL encryption depends on provider
```

---

## Maintenance

### Regular Tasks
- [ ] Monitor temp directory size
- [ ] Review error logs weekly
- [ ] Test with new providers
- [ ] Verify ZIP encryption compatibility

### Troubleshooting Commands

```bash
# Check temp files
ls -lah /tmp/csv_provider_*

# Check logs
tail -f storage/logs/laravel-*.log

# Test envelope creation
php artisan tinker
```

---

**Document Version**: 1.0
**Last Updated**: June 15, 2026
**Author**: System
**Status**: Complete
