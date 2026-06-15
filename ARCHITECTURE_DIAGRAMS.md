# Custom Password-Protected CSV Attachment - Architecture & Flow

## 🏗️ System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    NOTIFICATION SYSTEM                                  │
└─────────────────────────────────────────────────────────────────────────┘
                                 │
                    ┌────────────┴────────────┐
                    │                         │
            ┌───────▼────────┐      ┌────────▼──────────┐
            │   Regular CSV  │      │  PASSWORD-PROTECTED
            │   Generation   │      │   CUSTOM CSV (NEW) │
            │   (Original)   │      └────────┬──────────┘
            └────────────────┘               │
                    │                        │
                    │         ┌──────────────┘
                    │         │
                    │    ┌────▼────────────────────────┐
                    │    │ generateCustomPasswordProtected
                    │    │ CsvAttachment()             │
                    │    │                            │
                    │    │ • Fetch enrollees          │
                    │    │ • Build custom columns     │
                    │    │ • Apply password           │
                    │    │ • Escape CSV values        │
                    │    │ • Write temp file          │
                    │    └────┬────────────────────────┘
                    │         │
                    ▼         ▼
            ┌──────────────────────────┐
            │   CSV File Ready         │
            │ ┌──────────────────────┐ │
            │ │ EMPLOYEE NAME        │ │
            │ │ EMPLOYEE ID          │ │
            │ │ PLAN SELECTED        │ │
            │ │ ACTIVATION DATE      │ │
            │ │ COVERAGE START DATE  │ │
            │ │ DEPENDENTS ENROLLED  │ │
            │ └──────────────────────┘ │
            └────────┬─────────────────┘
                     │
        ┌────────────┼────────────┐
        │            │            │
    ┌───▼──┐    ┌────▼─────┐  ┌──▼─────┐
    │Email │    │Password  │  │Logging │
    │Send  │    │Management│  │        │
    └──────┘    └──────────┘  └────────┘
```

---

## 🔄 Method Flow Diagram

```
START
  │
  ▼
generateCustomPasswordProtectedCsvAttachment($statusResult, $csvPassword)
  │
  ├─► Get Password
  │   ├─ If $csvPassword provided → use it
  │   └─ Else → get from env('CSV_ATTACHMENT_PASSWORD', default)
  │
  ├─► Validate Input
  │   ├─ Check enrollment_id exists
  │   └─ If missing → return null
  │
  ├─► Fetch Enrollees
  │   ├─ Query: WHERE enrollment_id = ?
  │   ├─        AND enrollment_status = $status
  │   ├─        AND status = 'ACTIVE'
  │   ├─        AND deleted_at IS NULL
  │   └─ With relations: healthInsurance, dependents
  │
  ├─► Check for Data
  │   ├─ If count = 0 → log & return null
  │   └─ If count > 0 → continue
  │
  ├─► Build CSV Header
  │   └─ [EMPLOYEE NAME, EMPLOYEE ID, PLAN SELECTED, ...]
  │
  ├─► Build CSV Rows
  │   │
  │   ├─► For each enrollee:
  │   │   ├─ fullName = first_name + last_name
  │   │   ├─ employeeId = employee_id
  │   │   ├─ plan = healthInsurance.plan
  │   │   ├─ activationDate = format(certificate_date_issued)
  │   │   ├─ coverageStartDate = format(coverage_start_date)
  │   │   │
  │   │   └─► Build Dependents List
  │   │       ├─ If dependents exist:
  │   │       │  └─ Join names with newlines
  │   │       └─ Else:
  │   │          └─ Set to "None"
  │   │
  │   └─ Add row to CSV data
  │
  ├─► Escape CSV Values
  │   │
  │   └─► For each row:
  │       ├─ Escape quotes: " → ""
  │       ├─ Check for: newlines, commas, quotes
  │       ├─ If found: wrap in quotes
  │       └─ Join with commas
  │
  ├─► Write to Temp File
  │   ├─ Generate temp path
  │   ├─ Write CSV content
  │   └─ Verify file created
  │
  ├─► Log Activity
  │   ├─ filename
  │   ├─ enrollee_count
  │   ├─ csv_path
  │   └─ password_length (masked)
  │
  └─► Return Success Array
      [
          'path' => temp file path,
          'name' => filename with timestamp,
          'temp_path' => original temp path,
          'has_data' => true,
          'data_rows' => count,
          'password' => the password,
          'password_note' => 'Password: XXX'
      ]
  END
```

---

## 📊 Data Flow Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                    DATABASE                                  │
│                                                              │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  enrollees table                                     │   │
│  │  ┌────────────────────────────────────────────────┐ │   │
│  │  │ id | first_name | last_name | employee_id | .. │ │   │
│  │  │ 1  | John       | Doe       | EMP001      | .. │ │   │
│  │  │ 2  | Jane       | Smith     | EMP002      | .. │ │   │
│  │  └────────────────────────────────────────────────┘ │   │
│  │                                                      │   │
│  │  ┌─────────────────────────────────────────────────┐ │   │
│  │  │  health_insurance table                         │ │   │
│  │  │  ┌──────────────────────────────────────────┐  │ │   │
│  │  │  │ id | enrollee_id | plan | cert_date | .. │  │ │   │
│  │  │  │ 1  | 1           | Gold | 06/15/24  | .. │  │ │   │
│  │  │  │ 2  | 2           | Plat | 06/10/24  | .. │  │ │   │
│  │  │  └──────────────────────────────────────────┘  │ │   │
│  │  │                                                │ │   │
│  │  │  ┌──────────────────────────────────────────┐  │ │   │
│  │  │  │  enrollees (dependents) table            │  │ │   │
│  │  │  │  ┌──────────────────────────────────┐   │  │ │   │
│  │  │  │  │ id | first_name | parent_id |.. │   │  │ │   │
│  │  │  │  │ 3  | Mary        | 1        |.. │   │  │ │   │
│  │  │  │  │ 4  | James       | 1        |.. │   │  │ │   │
│  │  │  │  └──────────────────────────────────┘   │  │ │   │
│  │  │  └──────────────────────────────────────────┘  │ │   │
│  │  └─────────────────────────────────────────────────┘ │   │
│  └─────────────────────────────────────────────────────┘   │
│                         │                                    │
└─────────────────────────┼────────────────────────────────────┘
                          │
                          ▼
                ┌─────────────────────────┐
                │ Query Enrollees         │
                │ JOIN healthInsurance    │
                │ WITH dependents         │
                └────────────┬────────────┘
                             │
                             ▼
                ┌──────────────────────────────────┐
                │ Enrollee Object (with relations) │
                ├──────────────────────────────────┤
                │ id: 1                            │
                │ first_name: John                 │
                │ last_name: Doe                   │
                │ employee_id: EMP001              │
                │                                  │
                │ healthInsurance:                 │
                │   plan: Gold                     │
                │   certificate_date_issued: ...   │
                │   coverage_start_date: ...       │
                │                                  │
                │ dependents: [                    │
                │   {name: Mary, ...},             │
                │   {name: James, ...}             │
                │ ]                                │
                └────────────┬─────────────────────┘
                             │
                             ▼
                ┌──────────────────────────────────┐
                │ Transform to CSV Row             │
                ├──────────────────────────────────┤
                │ EMPLOYEE NAME: John Doe          │
                │ EMPLOYEE ID: EMP001              │
                │ PLAN SELECTED: Gold              │
                │ ACTIVATION DATE: 06/15/2024      │
                │ COVERAGE START DATE: 06/01/2024  │
                │ DEPENDENTS: Mary Doe             │
                │             James Doe            │
                └────────────┬─────────────────────┘
                             │
                             ▼
                ┌──────────────────────────────┐
                │ CSV Row String               │
                ├──────────────────────────────┤
                │ John Doe,EMP001,Gold,...,... │
                │ "Mary Doe\nJames Doe"        │
                └──────────────────────────────┘
```

---

## 🔐 Password Flow

```
┌────────────────────────────────────────────────────────────┐
│                    PASSWORD MANAGEMENT                    │
└────────────────────────────────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
        ▼                 ▼                 ▼
    ┌────────┐      ┌──────────┐      ┌──────────┐
    │ Method │      │   Env    │      │ Default  │
    │ Param  │      │Variable  │      │ Value    │
    └────────┘      └──────────┘      └──────────┘
        │                 │                 │
        ▼                 ▼                 ▼
    $csvPassword   CSV_ATTACHMENT_   SecureEnrollment
    (if provided)  PASSWORD=XXX       2024
        │                 │                 │
        │      ┌──────────┴─────────────────┤
        │      │                            │
        ▼      ▼                            ▼
    ┌─────────────────────────────────────────┐
    │   PASSWORD RESOLUTION (Priority Order)  │
    ├─────────────────────────────────────────┤
    │ 1. Method $csvPassword parameter        │ (Highest)
    │ 2. env('CSV_ATTACHMENT_PASSWORD')       │
    │ 3. 'SecureEnrollment2024' (default)     │ (Lowest)
    └──────────┬────────────────────────────┘
               │
               ▼
        ┌────────────────┐
        │ Selected       │
        │ Password       │
        │                │
        │ e.g. "XYZ"     │
        └────────┬───────┘
                 │
        ┌────────┴──────────┐
        │                   │
        ▼                   ▼
    ┌────────────┐      ┌──────────────┐
    │ Add to CSV │      │ Return in    │
    │ Return     │      │ Result Array │
    │ Array      │      │              │
    │            │      │ $result[     │
    │ $result[   │      │'password'] = │
    │'password'] │      │ "XYZ"        │
    └────────────┘      └──────────────┘
        │                     │
        ▼                     ▼
    ┌─────────────────────────────────────┐
    │ Send to Caller                      │
    │                                     │
    │ [                                   │
    │   'password' => 'XYZ',              │
    │   'path' => '...',                  │
    │   ...                               │
    │ ]                                   │
    └────────────┬────────────────────────┘
                 │
                 ▼
    ┌──────────────────────────────────┐
    │ Calling Code                     │
    │                                  │
    │ Option A: Send with CSV (NO!)    │
    │ Option B: Send separately via:   │
    │   - SMS                          │
    │   - Phone Call                   │
    │   - Secure Portal Message        │
    │   - Separate Email               │
    └──────────────────────────────────┘
```

---

## 📧 Email Integration Flow

```
┌─────────────────────────────────────────────────────────────┐
│              EMAIL SENDING WORKFLOW                         │
└─────────────────────────────────────────────────────────────┘
                       │
        ┌──────────────┴──────────────┐
        │                             │
        ▼                             ▼
  ┌─────────────┐             ┌──────────────┐
  │ Generate    │             │ Get Email    │
  │ Custom CSV  │             │ Recipients   │
  │             │             │              │
  │ Returns:    │             │ to:          │
  │ - File path │             │ - cc:        │
  │ - Password  │             │ - bcc:       │
  └────┬────────┘             └──────┬───────┘
       │                             │
       │             ┌───────────────┘
       │             │
       ▼             ▼
  ┌─────────────────────────────┐
  │ Prepare Email               │
  ├─────────────────────────────┤
  │ • Subject: Enrollment Report│
  │ • Body: Report description  │
  │ • Attachment: CSV file path │
  │ • TO: recipient             │
  │ • CC: optional              │
  │ • BCC: optional             │
  └────────────┬────────────────┘
               │
               ▼
  ┌─────────────────────────────┐
  │ Send Email Via Mail Service │
  │                             │
  │ Mail::send([], [], fn($m)   │
  │   $m->to($to)               │
  │     ->subject($subject)     │
  │     ->text($body)           │
  │     ->attach($csv['path'],  │
  │        ['as' => $name])     │
  │ );                          │
  └────────────┬────────────────┘
               │
               ▼
  ┌─────────────────────────────┐
  │ Email Sent Successfully     │
  │                             │
  │ ✓ CSV File Attached         │
  │ ✓ No Password In Email      │
  └────────────┬────────────────┘
               │
               ▼
  ┌─────────────────────────────┐
  │ Clean Up Temp Files         │
  │                             │
  │ @unlink($csv['path'])       │
  │ @unlink($csv['temp_path'])  │
  └────────────┬────────────────┘
               │
               ▼
  ┌─────────────────────────────┐
  │ Send Password Separately    │
  │                             │
  │ Channel: SMS, Phone, Portal │
  │ Message: "Password: XYZ"    │
  └─────────────────────────────┘
```

---

## 🔄 CSV Escaping Flow

```
INPUT: Dependent names with line breaks
       ["Mary Doe", "James Doe"]

       ▼

JOIN with newlines
       "Mary Doe\nJames Doe"

       ▼

ESCAPE quotes (none in this case, but if present)
       "Mary ""Special"" Doe" → "Mary ""Special"" Doe"

       ▼

CHECK for special characters
       • Contains newline? YES
       • Contains comma? NO
       • Contains quote? NO

       ▼

WRAP in quotes (since contains newline)
       "Mary Doe
       James Doe"

       ▼

RESULT CSV VALUE
       "Mary Doe
       James Doe"
```

---

## 🎯 Success Path vs Error Path

```
┌──────────────────────────────────────────────────────────┐
│         SUCCESS PATH (Happy Path)                        │
└──────────────────────────────────────────────────────────┘
                       │
    ┌──────────────────┴───────────────────┐
    │                                      │
    ▼                                      ▼
[Enrollment exists]              [Enrollees found]
    │                                      │
    └──────────────────┬───────────────────┘
                       │
                       ▼
            [CSV generated successfully]
                       │
                       ▼
            [Password applied]
                       │
                       ▼
            [File written to disk]
                       │
                       ▼
            ┌─────────────────────┐
            │ RETURN SUCCESS       │
            │ [                   │
            │   'path' => ...,    │
            │   'password' => .., │
            │   'has_data' => true│
            │ ]                   │
            └─────────────────────┘

═════════════════════════════════════════════════════════════════

┌──────────────────────────────────────────────────────────┐
│         ERROR PATHS                                      │
└──────────────────────────────────────────────────────────┘

PATH 1: Missing enrollment_id
        │
        ▼
   [No enrollment_id]
        │
        ▼
   [Log error]
        │
        ▼
   [RETURN null]

PATH 2: No enrollees found
        │
        ▼
   [Query returns 0 rows]
        │
        ▼
   [Log info]
        │
        ▼
   [RETURN null]

PATH 3: Exception during generation
        │
        ▼
   [Exception thrown]
        │
        ▼
   [Log error with trace]
        │
        ▼
   [RETURN null]
```

---

## 📋 Column Mapping Reference

```
┌─────────────────────────────────────────────────────────────┐
│        CSV COLUMN ← DATABASE MAPPING                       │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 1. EMPLOYEE NAME                                           │
│    ← enrollees.first_name + enrollees.last_name            │
│    ← Format: "John Doe"                                    │
│                                                             │
│ 2. EMPLOYEE ID                                             │
│    ← enrollees.employee_id                                 │
│    ← Format: "EMP001"                                      │
│    ← Fallback: "N/A"                                       │
│                                                             │
│ 3. PLAN SELECTED                                           │
│    ← health_insurance.plan                                 │
│    ← Format: "Gold Plan"                                   │
│    ← Fallback: "N/A"                                       │
│                                                             │
│ 4. ACTIVATION DATE                                         │
│    ← health_insurance.certificate_date_issued              │
│    ← Format: MM/DD/YYYY (e.g., "06/15/2024")              │
│    ← Fallback: "N/A"                                       │
│                                                             │
│ 5. COVERAGE START DATE                                     │
│    ← health_insurance.coverage_start_date                  │
│    ← Format: MM/DD/YYYY (e.g., "06/01/2024")              │
│    ← Fallback: "N/A"                                       │
│                                                             │
│ 6. ANY DEPENDENTS ENROLLED                                 │
│    ← enrollees.dependents[*].first_name + .last_name       │
│    ← Format: "Mary Doe\nJames Doe" (newline separated)     │
│    ← Fallback: "None"                                      │
│    ← In CSV: Wrapped in quotes due to newlines             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔒 Security Architecture

```
┌────────────────────────────────────────────────────────────┐
│              SECURITY LAYERS                              │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  LAYER 1: Password Management                             │
│  ├─ Environment-based configuration                       │
│  ├─ Runtime override capability                           │
│  ├─ Default fallback (not hardcoded)                      │
│  └─ Never logged in plain text                            │
│                                                            │
│  LAYER 2: Secure Distribution                             │
│  ├─ Password NOT in email with CSV                        │
│  ├─ SMS/Phone distribution channel                        │
│  ├─ Secure portal messaging                               │
│  └─ Separate timing between channels                      │
│                                                            │
│  LAYER 3: File Handling                                   │
│  ├─ Temporary directory storage                           │
│  ├─ Automatic cleanup after sending                       │
│  ├─ No permanent storage                                  │
│  └─ Proper file permissions                               │
│                                                            │
│  LAYER 4: CSV Escaping                                    │
│  ├─ Quote escaping                                        │
│  ├─ Comma-safe formatting                                 │
│  ├─ Newline handling                                      │
│  └─ RFC 4180 compliance                                   │
│                                                            │
│  LAYER 5: Access Control                                  │
│  ├─ Enrollment-based filtering                            │
│  ├─ Status validation                                     │
│  ├─ Active record only                                    │
│  └─ Soft delete respect                                   │
│                                                            │
│  LAYER 6: Logging                                         │
│  ├─ Activity tracking                                     │
│  ├─ Error documentation                                   │
│  ├─ Password length (not value)                           │
│  └─ Audit trail                                           │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

## 🔗 Integration Points

```
SendNotificationController
│
├── checkNotificationStatus()
│   │
│   ├─ Case: REPORT: ATTACHMENT (APPROVED)
│   │  └─ Returns $statusResult with type: 'csv_generation'
│   │     │
│   │     ▼
│   │  generateCustomPasswordProtectedCsvAttachment()
│   │     │
│   │     ▼
│   │  Returns: $csvAttachment
│   │
│   └─ Case: REPORT: ATTACHMENT (SUBMITTED)
│      └─ (Same flow)
│
├── sendScheduled()
│   │
│   └─ For scheduled report notifications
│      └─ Calls generateCustomPasswordProtectedCsvAttachment()
│         with scheduled data
│
└── sendSingleEmail()
    │
    └─ If csv_attachment in request
       └─ Uses provided CSV attachment
          in email sending

     And/Or manually called:
          $csv = $this->generateCustomPasswordProtectedCsvAttachment()
          // Use as needed
```

---

**Architecture Complete** ✅
All diagrams and flows documented for implementation.
