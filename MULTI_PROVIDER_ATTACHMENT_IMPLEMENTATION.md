# Multi-Provider Attachment Implementation

## Overview
This implementation enables the notification system to generate **separate CSV attachments for each insurance provider** within the same company, all attached to a single email.

## Use Case
When a company (e.g., "Deel") has multiple insurance providers (e.g., "Maxicare" and "Philcare"):
- **Before**: Single CSV attachment with mixed provider data
- **After**: Two separate ZIP attachments in the same email:
  - `ENROLLEES_MAXICARE_APPROVED_20260615_143022.zip`
  - `ENROLLEES_PHILCARE_APPROVED_20260615_143022.zip`

## Key Changes

### 1. Modified `sendSingleEmail()` Method
**Location**: `SendNotificationController.php` lines ~260-390

**Changes**:
- Changed `$csvAttachment` (single) to `$csvAttachments` (array)
- Updated to call new `generateMultiProviderCsvAttachments()` method
- Loops through all returned attachments for email attachment

### 2. New `generateMultiProviderCsvAttachments()` Method
**Location**: `SendNotificationController.php` lines ~1600-1790

**Functionality**:
- Gets all enrollments for the same company
- Iterates through each enrollment (provider) independently
- Generates a separate CSV for each provider with:
  - Employee Name, ID, Plan, Certificate #, Dates, Dependents
  - Password-protected ZIP files (if ZIP archive support available)
  - Provider name in filename for easy identification

**Process**:
```
1. Fetch current enrollment → Get company_id
2. Query all enrollments for that company_id
3. For each enrollment (provider):
   - Get enrollees for this specific enrollment
   - Build CSV with enrollees' data
   - Zip and password-protect
   - Add to array
4. Return array of all provider attachments
```

### 3. Updated Email Sending Logic
**Infobip Provider** (lines ~360-380):
```php
if (!empty($csvAttachments) && !$placeholderMessage) {
    foreach ($csvAttachments as $csv) {
        $tempFiles[] = $csv['path'];
        $infobipAttachments[] = [
            'path' => $csv['path'],
            'name' => $csv['name']
        ];
    }
}
```

**Laravel Mail** (lines ~405-415):
```php
if (!empty($csvAttachments) && !$placeholderMessage) {
    foreach ($csvAttachments as $csvAttachment) {
        $message->attach($csvAttachment['path'], [
            'as' => $csvAttachment['name'],
            'mime' => 'application/zip'
        ]);
    }
}
```

## Features

✅ **Automatic Multi-Provider Handling**
- No additional configuration needed
- Works for companies with 1, 2, or more providers

✅ **Separate Attachments Per Provider**
- Each provider gets its own CSV file
- Provider name included in filename
- Easy to identify which data belongs to which provider

✅ **Password Protection**
- Each ZIP file is password-protected (if available)
- Uses `CSV_ATTACHMENT_PASSWORD` from environment

✅ **Single Email**
- All attachments sent in one email
- No need to send multiple emails
- Cleaner user experience

✅ **Backwards Compatible**
- Works with existing notification types
- Handles single-provider companies seamlessly
- No breaking changes to current functionality

## Data Structure

### Returned CSV Attachment Array
```php
[
    'path' => '/tmp/xyz.zip',           // Full path to ZIP file
    'name' => 'ENROLLEES_MAXICARE_APPROVED_20260615_143022.zip',
    'temp_path' => '/tmp/xyz',          // Original temp path
    'temp_zip' => '/tmp/xyz.zip',       // ZIP path
    'temp_csv' => '/tmp/xyz.csv',       // CSV path (for cleanup)
    'has_data' => true,                 // Whether data exists
    'data_rows' => 45,                  // Number of employee records
    'provider' => 'MAXICARE',           // Provider name
    'enrollment_id' => 123              // Enrollment ID
]
```

## CSV Content
Each CSV contains:
- **EMPLOYEE NAME**: Full name of enrollee
- **EMPLOYEE ID**: Employee identifier
- **PLAN SELECTED**: Insurance plan name
- **CERTIFICATE NUMBER**: Certificate #
- **ACTIVATION DATE**: Certificate issue date
- **COVERAGE START DATE**: Coverage start date
- **ANY DEPENDENTS ENROLLED**: List of dependents

## Notification Types Supported
- ✅ `REPORT: ATTACHMENT (APPROVED)` - Approved enrollees
- ✅ `REPORT: ATTACHMENT (SUBMITTED)` - Submitted enrollees

## Error Handling
- Returns empty array if no enrollments found
- Skips inactive enrollments
- Handles missing provider data gracefully
- Comprehensive error logging

## Example Scenario

### Setup
- **Company**: Deel
- **Provider 1**: Maxicare (10 approved employees)
- **Provider 2**: Philcare (8 approved employees)
- **Notification**: REPORT: ATTACHMENT (APPROVED)

### Result
**Single email with 2 attachments**:
1. `ENROLLEES_MAXICARE_APPROVED_20260615_143022.zip` (10 rows)
2. `ENROLLEES_PHILCARE_APPROVED_20260615_143022.zip` (8 rows)

**Without this feature**: Would need 2 separate notifications or mixed data in single CSV

## Testing

### Test Case 1: Single Provider
- Create enrollment: Deel + Maxicare
- Send notification
- Should generate 1 attachment ✓

### Test Case 2: Multiple Providers
- Create enrollments:
  - Deel + Maxicare
  - Deel + Philcare
- Send notification
- Should generate 2 attachments ✓

### Test Case 3: Mixed Data
- Provider 1: 15 approved employees
- Provider 2: 8 approved employees
- Send notification
- Should generate 2 separate CSVs with correct employee counts ✓

## Configuration
Ensure these environment variables are set:

```env
CSV_ATTACHMENT_PASSWORD=%!@#deElDCSV@#%!
EMAIL_PROVIDER_SETTING=infobip  # or default for Laravel Mail
```

## File Cleanup
All temporary files are automatically cleaned up:
- CSV files
- ZIP files
- Temp paths

Cleanup occurs after email is sent (Infobip) or queued (Laravel Mail).

## Performance Considerations
- **Time Complexity**: O(n*m) where n = providers, m = enrollees per provider
- **Memory**: Temporary files stored in system temp directory
- **Database**: Single query per provider to fetch enrollees
- **Network**: Attachments sent in single email request

## Future Enhancements
- [ ] Custom column mapping per provider
- [ ] Provider-specific formatting rules
- [ ] Separate email threshold (if attachment size exceeds limit)
- [ ] Scheduled task to generate pre-packaged reports
- [ ] Webhook notifications per provider

## Troubleshooting

### No attachments generated
- Check if enrollees exist for the enrollment
- Verify `certificate_date_issued` is within date range
- Check enrollment status is 'ACTIVE'

### ZIP password not working
- Verify `ZipArchive::setEncryptionName` is available (PHP >= 7.2)
- Check `CSV_ATTACHMENT_PASSWORD` environment variable
- Falls back to plain CSV if encryption unavailable

### Missing provider in filename
- Verify `insurance_provider_id` is set on enrollment
- Check insurance provider record exists
- Look for provider title in error logs

---

**Implementation Date**: June 15, 2026
**Modified Files**: `SendNotificationController.php`
**Lines Added**: ~200
**Breaking Changes**: None
