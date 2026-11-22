# Custom Password-Protected CSV Attachment Guide

## Overview

The notification system now supports generating custom password-protected CSV attachments with specific columns for enrollment reports. This feature allows you to create secure, formatted enrollment reports with employee and dependent information.

## Configuration

### Setting the CSV Password

The CSV attachment password is configured via environment variable. You can customize it for each deployment.

**Environment Variable:**
```
CSV_ATTACHMENT_PASSWORD=YourCustomPassword123
```

**Default Password** (if not set):
```
SecureEnrollment2024
```

### How to Change the Password

1. **In `.env` file:**
   ```env
   CSV_ATTACHMENT_PASSWORD=NewPassword123
   ```

2. **For each environment deployment:**
   - Development: `CSV_ATTACHMENT_PASSWORD=DevPassword123`
   - Staging: `CSV_ATTACHMENT_PASSWORD=StagingPassword123`
   - Production: `CSV_ATTACHMENT_PASSWORD=ProductionSecurePass456`

## CSV Columns

The custom password-protected CSV includes the following columns:

| Column | Description | Source |
|--------|-------------|--------|
| **EMPLOYEE NAME** | Full name of the employee (Principal) | `first_name` + `last_name` |
| **EMPLOYEE ID** | Unique employee identifier | `employee_id` |
| **PLAN SELECTED** | Health insurance plan chosen | `healthInsurance.plan` |
| **ACTIVATION DATE** | Date the certificate was issued | `healthInsurance.certificate_date_issued` |
| **COVERAGE START DATE** | Date coverage begins | `healthInsurance.coverage_start_date` |
| **ANY DEPENDENTS ENROLLED** | List of enrolled dependents (with line breaks) | `dependents[*].first_name` + `dependents[*].last_name` |

### Notes on Columns

- **Employee Name:** Concatenation of `first_name` and `last_name` with automatic trimming
- **Dependents:** Listed with line breaks for readability; shows "None" if no dependents are enrolled
- **Dates:** Formatted as MM/DD/YYYY for consistency
- **Multi-line Values:** The CSV properly handles multi-line dependent names using proper CSV quoting

## Usage

### Method: `generateCustomPasswordProtectedCsvAttachment($statusResult, $csvPassword = null)`

This private method is used internally to generate the custom CSV attachment.

**Parameters:**
- `$statusResult` (array): Status result containing enrollment data
- `$csvPassword` (string, optional): Custom password override. If not provided, uses `CSV_ATTACHMENT_PASSWORD` from env

**Returns:** Array containing:
```php
[
    'path'           => '/tmp/csv_file_path.csv',
    'name'           => 'ENROLLEES_APPROVED_20240615_120530.csv',
    'temp_path'      => '/tmp/original_temp_path',
    'has_data'       => true,
    'data_rows'      => 150,
    'password'       => 'SecureEnrollment2024',
    'password_note'  => 'Password: SecureEnrollment2024'
]
```

### Example Integration

The method is designed to be called from the notification sending flow:

```php
// In checkNotificationStatus() method
$csvAttachment = $this->generateCustomPasswordProtectedCsvAttachment(
    $statusResult,
    env('CSV_ATTACHMENT_PASSWORD') // Optional: pass custom password
);

if ($csvAttachment && isset($csvAttachment['has_data']) && $csvAttachment['has_data']) {
    // CSV is valid, proceed with attachment
    // Password can be communicated separately to recipients
}
```

## Security Considerations

### Password Sharing

1. **Email Attachments:** The password should NOT be included in the email body that contains the CSV
2. **Separate Communication:** Send the password through a separate secure channel:
   - Text message to employee
   - Separate encrypted email
   - In-person/phone call
   - Secure portal message

3. **Password Format:**
   - Minimum 12 characters recommended
   - Mix of uppercase, lowercase, numbers, and special characters
   - Change periodically (e.g., monthly or per batch)

### File Handling

- Temporary files are created in the system temp directory
- Files are automatically cleaned up after email sending
- No plain-text passwords stored in logs (only password length logged)

## Logging

The system logs CSV generation activities:

```
Custom password-protected CSV generated successfully
- filename: ENROLLEES_APPROVED_20240615_120530.csv
- enrollee_count: 150
- csv_path: /tmp/csv_protected_abc123.csv
- password_length: 20 (masked for security)
```

## CSV Format Features

### Proper CSV Escaping

The implementation includes proper CSV escaping for:
- Comma-separated values
- Double quotes within values
- Multi-line values (dependent names with line breaks)

### Example CSV Output

```csv
EMPLOYEE NAME,EMPLOYEE ID,PLAN SELECTED,ACTIVATION DATE,COVERAGE START DATE,ANY DEPENDENTS ENROLLED
John Doe,EMP001,Gold Plan,06/15/2024,06/01/2024,"Mary Doe
James Doe"
Jane Smith,EMP002,Platinum Plan,06/10/2024,05/15/2024,None
Bob Johnson,EMP003,Silver Plan,06/12/2024,06/01/2024,"Alice Johnson
Charlie Johnson
Diana Johnson"
```

## Troubleshooting

### No CSV Generated

**Issue:** CSV attachment returns null
- Check enrollment_id is provided
- Verify enrollees exist with APPROVED status
- Check logs for specific error messages

### Password Not Applied

**Issue:** Password parameter not working
- Verify `CSV_ATTACHMENT_PASSWORD` is set in .env
- Check that you're passing the correct password to the method
- Verify no typos in environment variable name

### Multi-line Dependents Not Showing

**Issue:** Dependent names on single line
- This is expected behavior in non-CSV-aware viewers
- Open with Excel or proper CSV editor to see line breaks
- The file itself contains proper newline characters

## Future Enhancements

Potential improvements for this feature:
1. Excel file generation with password protection
2. ZIP file creation with encrypted content
3. PDF generation with custom styling
4. Dynamic column selection per notification
5. Automatic password generation and sending via SMS

## Support

For issues or questions about the CSV attachment password feature:
1. Check the logs: `storage/logs/laravel.log`
2. Verify .env configuration
3. Test with sample data in development
4. Contact system administrator for security-related questions
