# Import Logs History Button - Visual Guide

## Button Location

```
┌─────────────────────────────────────────────────────────────────┐
│  + Import: Insert / Update Enrollees              [ 📄 History] │
└─────────────────────────────────────────────────────────────────┘
                                                         ↑
                                                    New Button
```

## When You Click "History"

```
┌──────────────────────────────────────────────────────────────────┐
│  📄 Import History                                          [×]  │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ Import Date │ Status │ Principals │ Dependents │ Action   │ │
│  ├────────────────────────────────────────────────────────────┤ │
│  │ Jul 15, 14:30 │ ✓ SUCCESS │ +2 / ↻1 │ +4 / ↻1 │ Download │ │
│  │ Jul 14, 09:15 │ ✓ SUCCESS │ +5      │ +8      │ Download │ │
│  │ Jul 13, 16:45 │ ✗ FAILED  │ --      │ --      │ Download │ │
│  │ Jul 12, 11:20 │ ⚠ PARTIAL │ +3 / ↻2 │ +6 / ↻2 │ Download │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│                                              [Close]            │
└──────────────────────────────────────────────────────────────────┘
```

## Status Badge Styles

```
✓ SUCCESS (Green Badge)    ╔══════════════════════════╗
                            ║ SUCCESS (green bg)       ║
                            ╚══════════════════════════╝

✗ FAILED (Red Badge)       ╔══════════════════════════╗
                            ║ FAILED (red bg)          ║
                            ╚══════════════════════════╝

⚠ PARTIAL (Yellow Badge)   ╔══════════════════════════╗
                            ║ PARTIAL (yellow bg)      ║
                            ╚══════════════════════════╝
```

## Statistics Display

```
Principals Created:  +15 (green, bold)
Principals Updated:  ↻2  (blue, only if > 0)
Combined:           "+15 / ↻2"

Dependents Created:  +8  (green, bold)
Dependents Updated:  ↻3  (blue, only if > 0)
Combined:           "+8 / ↻3"

If No Updates:      Just shows "+15" (no ↻ symbol)
```

## Empty State

```
┌──────────────────────────────────────────────────────────────────┐
│  📄 Import History                                          [×]  │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│                                                                  │
│                            📄 (faded)                           │
│                                                                  │
│                  No import logs found for                        │
│                     this enrollment                             │
│                                                                  │
│                                                                  │
│                                                                  │
│                                              [Close]            │
└──────────────────────────────────────────────────────────────────┘
```

## Loading State

```
┌──────────────────────────────────────────────────────────────────┐
│  📄 Import History                                          [×]  │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│                                                                  │
│                          ⟳⟳⟳                                   │
│                       Loading...                                │
│                                                                  │
│                                                                  │
│                                                                  │
│                                              [Close]            │
└──────────────────────────────────────────────────────────────────┘
```

## Download Button States

```
Normal State:        [⬇ Download]  (blue bg, white text)

Hover State:         [⬇ Download]  (darker blue)

Loading State:       [⟳ Downloading...]  (spinning icon)

Downloaded:          File appears in Downloads folder
```

## Date Format Example

```
Raw: "2026-07-15T14:30:45Z"
Display: "Jul 15, 2026 02:30 PM"

Components:
- "Jul"        = Month (short)
- "15"         = Day
- "2026"       = Year
- "02:30 PM"   = Time (12-hour format)
```

## Error Message Display

```
┌──────────────────────────────────────────────────────────────────┐
│  📄 Import History                                          [×]  │
├──────────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────────────────────────────────────┐ │
│  │  ✗ Failed to fetch import logs                            │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  (Table would appear below error, but disabled)                │
│                                                                  │
│                                              [Close]            │
└──────────────────────────────────────────────────────────────────┘
```

## Full Table Example

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Import Date         │ Status   │ Principals     │ Dependents    │ Action│
├─────────────────────────────────────────────────────────────────────────┤
│ Jul 15, 14:30      │ SUCCESS  │ +15 / ↻2      │ +20 / ↻5     │ [⬇]  │
├─────────────────────────────────────────────────────────────────────────┤
│ Jul 15, 10:45      │ SUCCESS  │ +8            │ +12          │ [⬇]  │
├─────────────────────────────────────────────────────────────────────────┤
│ Jul 14, 16:20      │ PARTIAL  │ +5 / ↻1      │ +7 / ↻2      │ [⬇]  │
├─────────────────────────────────────────────────────────────────────────┤
│ Jul 14, 09:00      │ FAILED   │ -             │ -            │ [⬇]  │
├─────────────────────────────────────────────────────────────────────────┤
│ Jul 13, 15:30      │ SUCCESS  │ +12 / ↻3     │ +18 / ↻4     │ [⬇]  │
└─────────────────────────────────────────────────────────────────────────┘
```

## Dark Mode Display

```
Light Mode:
┌─────────────────────────────────────┐
│ White background                    │
│ Dark gray text                      │
│ Light gray borders                  │
└─────────────────────────────────────┘

Dark Mode:
┌─────────────────────────────────────┐
│ Dark slate background               │
│ Light text                          │
│ Dark borders                        │
└─────────────────────────────────────┘

Both preserve status colors (green/red/yellow)
```

## Mobile View

```
On Small Screens:
┌──────────────────────────┐
│  📄 History        [×]   │
├──────────────────────────┤
│ Stack layout (collapsed) │
│                          │
│ [Jul 15, 14:30]         │
│ Status: ✓ SUCCESS        │
│ Principals: +15 / ↻2    │
│ Dependents: +20 / ↻5    │
│ [⬇ Download]            │
│                          │
│ [Jul 15, 10:45]         │
│ Status: ✓ SUCCESS        │
│ Principals: +8          │
│ Dependents: +12         │
│ [⬇ Download]            │
│                          │
│          [Close]        │
└──────────────────────────┘
```

## Color Reference

```
Status Success (Green):
  Background: #dcfce7 (light mode) / #1b3a1b (dark mode)
  Text: #166534 (light mode) / #86efac (dark mode)

Status Failed (Red):
  Background: #fee2e2 (light mode) / #3a1b1b (dark mode)
  Text: #991b1b (light mode) / #fca5a5 (dark mode)

Status Partial (Yellow):
  Background: #fef3c7 (light mode) / #3a3a1b (dark mode)
  Text: #b45309 (light mode) / #fcd34d (dark mode)

Created Count (Green):
  Text: #16a34a (green-600)

Updated Count (Blue):
  Text: #2563eb (blue-600)

Button Hover:
  Light: #d1d5db → #e5e7eb
  Dark: #374151 → #4b5563
```

## Download File Example

```
Downloaded Filename:
  import_log_enrollment-15_2026-07-15_143015.txt

File Content:
  ====================================================================================
  IMPORT LOG REPORT
  ====================================================================================
  
  IMPORT INFORMATION:
  -------------------------------------------------------------------------------------
  Import Log ID:          42
  Enrollment ID:          15
  Import Date:            2026-07-15 14:30:45
  Status:                 SUCCESS
  Date Format Detected:   DD/MM/YYYY
  Date Confidence:        95%
  
  IMPORT SUMMARY:
  -------------------------------------------------------------------------------------
  Total Principals:       15
  Principals Created:     15
  Principals Updated:     0
  Total Dependents:       20
  Dependents Created:     20
  Dependents Updated:     0
  
  PRINCIPALS IMPORTED:
  ====================================================================================
  
  PRINCIPAL #1
  -------------------------------------------------------------------------------------
  Employee ID:            EMP-2024-001
  [... detailed information ...]
```

---

**Visual guide complete! Ready for user interaction.**
