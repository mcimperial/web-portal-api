# Multi-Provider Attachment - Usage Guide

## How It Works

When you create a notification for a company with **multiple insurance providers**, the system automatically generates **separate attachments for each provider** in a **single email**.

## Example

### Scenario: Company "Deel" with 2 Providers

#### Setup:
```
Company: Deel
├── Provider 1: Maxicare (10 approved employees)
└── Provider 2: Philcare (8 approved employees)
```

#### Create Notification:
- Notification Type: `REPORT: ATTACHMENT (APPROVED)`
- Company: Deel
- Schedule: Daily at 9 AM

#### What Happens:
1. System checks Deel's enrollments
2. Finds 2 providers: Maxicare and Philcare
3. Generates 2 separate CSVs:
   - `ENROLLEES_MAXICARE_APPROVED_20260615_090000.zip` (10 rows)
   - `ENROLLEES_PHILCARE_APPROVED_20260615_090000.zip` (8 rows)
4. Sends **single email with both attachments**

---

## Benefits

| Aspect | Before | After |
|--------|--------|-------|
| **Attachments per Email** | 1 mixed CSV | Multiple CSVs (1 per provider) |
| **Data Organization** | All mixed together | Clearly separated by provider |
| **File Naming** | Generic filename | `ENROLLEES_[PROVIDER]_[STATUS]_[DATE].zip` |
| **Multiple Emails?** | Would need separate notifications | Single email covers all |
| **Password Protection** | If provided | ✓ Each ZIP password-protected |

---

## How to Use

### 1. Create Notification (No Changes!)
Use the existing notification creation flow. The system automatically detects multiple providers.

```
Notification Type: REPORT: ATTACHMENT (APPROVED)
Enrollment ID: 123  # Associated with company
To: compliance@company.com
CC: (optional)
Schedule: 0 9 * * *  (Daily at 9 AM)
```

### 2. System Automatically:
- ✓ Detects all providers for the company
- ✓ Generates separate CSV per provider
- ✓ Zips and password-protects each
- ✓ Attaches all to one email
- ✓ Cleans up temp files

### 3. Recipients Get:
A single email with attachments like:
- `ENROLLEES_MAXICARE_APPROVED_20260615_090000.zip`
- `ENROLLEES_PHILCARE_APPROVED_20260615_090000.zip`
- `ENROLLEES_AETNA_APPROVED_20260615_090000.zip` (if exists)

---

## API Integration

### Manual Notification Send
```bash
POST /api/notification/send
{
    "notification_id": 1,
    "to": "admin@company.com",
    "cc": "finance@company.com",
    "bcc": null
}
```

**Response**:
```json
{
    "success": true,
    "message": "Notification sent successfully",
    "details": {
        "attachments_generated": 2,
        "providers": ["MAXICARE", "PHILCARE"],
        "total_employees": 18
    }
}
```

### Scheduled Notification
Automatically runs based on cron schedule. No manual trigger needed.

---

## File Organization

### CSV Content Per Provider
Each CSV file contains only employees for that specific provider:

```csv
EMPLOYEE NAME,EMPLOYEE ID,PLAN SELECTED,CERTIFICATE NUMBER,ACTIVATION DATE,COVERAGE START DATE,ANY DEPENDENTS ENROLLED
John Smith,EMP-001,MAXICARE GOLD,MC123456,06/15/2026,07/01/2026,"Jane Smith
Bob Smith"
Mary Johnson,EMP-002,MAXICARE SILVER,MC123457,06/15/2026,07/01/2026,None
...
```

Another ZIP file contains only Philcare employees:
```csv
EMPLOYEE NAME,EMPLOYEE ID,PLAN SELECTED,CERTIFICATE NUMBER,ACTIVATION DATE,COVERAGE START DATE,ANY DEPENDENTS ENROLLED
Alice Chen,EMP-101,PHILCARE PLUS,PC654321,06/15/2026,07/01/2026,None
David Lee,EMP-102,PHILCARE PLUS,PC654322,06/15/2026,07/01/2026,"Lisa Lee"
...
```

---

## Key Features Explained

### 1. Automatic Detection
System automatically finds all enrollments for a company's ID, eliminating manual work.

```php
// Behind the scenes
$enrollmentsForCompany = Enrollment::where('company_id', $companyId)->get();
// Gets all providers associated with company
```

### 2. Separate Files Per Provider
Each provider gets its own ZIP with naming convention:
```
ENROLLEES_[PROVIDER_NAME]_[STATUS]_[TIMESTAMP].zip
```

### 3. Password Protection
Each ZIP is encrypted with password from `.env`:
```env
CSV_ATTACHMENT_PASSWORD=%!@#deElDCSV@#%!
```

Users receive password via separate secure channel.

### 4. Single Email
All attachments in one email = cleaner inbox, easier management.

---

## Configuration

### Environment Variables

```env
# Required
CSV_ATTACHMENT_PASSWORD=%!@#deElDCSV@#%!

# Email Provider (existing)
EMAIL_PROVIDER_SETTING=infobip  # or Laravel Mail
MAIL_FROM_ADDRESS=noreply@company.com
MAIL_FROM_NAME="Company Notifications"
```

### .env Setup
```bash
# Set a strong password for ZIP protection
CSV_ATTACHMENT_PASSWORD="YourStrongPassword123!@#"
```

---

## Common Scenarios

### Scenario 1: Single Provider Company
```
Company: Tech Corp
Provider: Maxicare (only one)

Result: Single attachment
- ENROLLEES_MAXICARE_APPROVED_20260615_090000.zip
```

### Scenario 2: Two Provider Company
```
Company: Global Inc
Providers: 
  - Maxicare (12 employees)
  - AIA (8 employees)

Result: Two attachments
- ENROLLEES_MAXICARE_APPROVED_20260615_090000.zip
- ENROLLEES_AIA_APPROVED_20260615_090000.zip
```

### Scenario 3: Three+ Provider Company
```
Company: Multinational Corp
Providers:
  - Maxicare (15 employees)
  - Philcare (10 employees)
  - Aetna (7 employees)

Result: Three attachments
- ENROLLEES_MAXICARE_APPROVED_20260615_090000.zip
- ENROLLEES_PHILCARE_APPROVED_20260615_090000.zip
- ENROLLEES_AETNA_APPROVED_20260615_090000.zip
```

---

## Troubleshooting

### Problem: No attachments generated
**Solution**:
- Verify enrollees exist for each provider
- Check employee `certificate_date_issued` is within report date range
- Ensure enrollment `status` is "ACTIVE" (not "INACTIVE")

### Problem: Only one attachment instead of multiple
**Solution**:
- Check that all providers are active
- Verify each provider has at least one approved employee
- Confirm no providers have `enrollment.status = 'INACTIVE'`

### Problem: Missing provider name in filename
**Solution**:
- Verify `insurance_provider_id` is set on enrollment record
- Check that insurance provider record exists in database
- Review server logs for specific errors

### Problem: ZIP files not password protected
**Solution**:
- Verify PHP version ≥ 7.2 (supports `ZipArchive::setEncryptionName`)
- Check `CSV_ATTACHMENT_PASSWORD` is set in `.env`
- Falls back to plain CSV if encryption unavailable

### Problem: Large file sizes
**Solution**:
- Multiple smaller ZIPs is normal (one per provider)
- Each ZIP only contains that provider's data
- Consider email provider attachment size limits

---

## Advanced Usage

### Custom Schedules per Provider

If you need different schedules for different providers, create separate notifications:

```
Notification 1:
- Type: REPORT: ATTACHMENT (APPROVED)
- Enrollment: Company A + Maxicare
- Schedule: 0 8 * * *  (8 AM daily)

Notification 2:
- Type: REPORT: ATTACHMENT (APPROVED)
- Enrollment: Company A + Philcare
- Schedule: 0 10 * * *  (10 AM daily)
```

Both use `company_id` filtering, so system handles it automatically.

### Filtering by Date Range

Date range is automatically calculated from notification schedule. No manual action needed.

---

## Verification Checklist

✓ Enrollment has `company_id` set
✓ Enrollment has `insurance_provider_id` set  
✓ Insurance provider record exists
✓ Enrollment `status` is "ACTIVE"
✓ Enrollees exist with `certificate_date_issued` in range
✓ At least one enrollee has `enrollment_status = 'APPROVED'`
✓ Email recipients configured (to/cc/bcc)
✓ `CSV_ATTACHMENT_PASSWORD` set in `.env`

---

## Supported Notification Types

| Type | Multi-Provider Support |
|------|----------------------|
| REPORT: ATTACHMENT (APPROVED) | ✓ YES |
| REPORT: ATTACHMENT (SUBMITTED) | ✓ YES |
| APPROVED BY HMO (WELCOME EMAIL) | No (direct recipients) |
| ENROLLMENT START (PENDING) | No (direct recipients) |

---

## Performance

- **Processing Time**: ~100-500ms depending on employee count
- **Memory Usage**: Minimal (streams to temp files)
- **Email Size**: Depends on number of attachments and employees
- **Cleanup**: Automatic temp file deletion after sending

---

## FAQ

**Q: Can I disable multi-provider for a notification?**
A: Not needed - system automatically handles both single and multiple providers.

**Q: Does order of attachments matter?**
A: No, but filename includes provider name for easy identification.

**Q: What if a provider has no approved employees?**
A: Skipped automatically (no empty ZIP generated).

**Q: Can I customize the CSV columns?**
A: Currently fixed columns. Customization can be added in future versions.

**Q: Are attachments always password protected?**
A: Yes, if PHP ≥ 7.2. Falls back to plain CSV if encryption unavailable.

**Q: What about very large companies with 100+ employees per provider?**
A: Works fine! May result in larger ZIP files, but handled automatically.

---

## Support

For issues or questions, check:
1. Server error logs
2. Application logs under `storage/logs`
3. Database enrollment records
4. PHP/ZipArchive version compatibility

---

**Last Updated**: June 15, 2026
