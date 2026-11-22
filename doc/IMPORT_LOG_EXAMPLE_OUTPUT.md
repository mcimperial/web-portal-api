# Import Log - Example Output

This is an example of what the downloaded text report looks like.

```
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
---------------------------------------------------------------------------
Total Principals:       3
Principals Created:     2
Principals Updated:     1
Total Dependents:       5
Dependents Created:     4
Dependents Updated:     1

PRINCIPALS IMPORTED:
====================================================================================

PRINCIPAL #1
---------------------------------------------------------------------------
Employee ID:            EMP-2024-001
Action:                 CREATED
Timestamp:              2026-07-15 14:30:45

  ENROLLEE FIELDS:
    First Name                   : JOHN
    Last Name                    : DOE
    Middle Name                  : MICHAEL
    Birth Date                   : 1985-06-15
    Gender                       : MALE
    Employment Start Date        : 2020-01-15
    Employment End Date          : 
    Email 1                      : john.doe@company.com
    Phone 1                      : +63-912-345-6789
    Address                      : 123 Main St, Metro Manila
    Department                   : IT
    Position                     : Software Engineer
    Marital Status               : MARRIED
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0001
    Plan                         : GOLD
    Premium                      : 15000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Renewal                   : 0
    Is Skipping                  : 0
    Reason For Skipping          : 

PRINCIPAL #2
---------------------------------------------------------------------------
Employee ID:            EMP-2024-002
Action:                 CREATED
Timestamp:              2026-07-15 14:30:46

  ENROLLEE FIELDS:
    First Name                   : JANE
    Last Name                    : SMITH
    Middle Name                  : MARIE
    Birth Date                   : 1987-03-20
    Gender                       : FEMALE
    Employment Start Date        : 2021-06-01
    Employment End Date          : 
    Email 1                      : jane.smith@company.com
    Phone 1                      : +63-912-345-6790
    Address                      : 456 Oak Ave, Makati City
    Department                   : HR
    Position                     : HR Manager
    Marital Status               : SINGLE
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0002
    Plan                         : PLATINUM
    Premium                      : 18000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Renewal                   : 0
    Is Skipping                  : 0
    Reason For Skipping          : 

PRINCIPAL #3
---------------------------------------------------------------------------
Employee ID:            EMP-2024-003
Action:                 UPDATED
Timestamp:              2026-07-15 14:30:47

  ENROLLEE FIELDS:
    First Name                   : ROBERT
    Last Name                    : JOHNSON
    Middle Name                  : JAMES
    Birth Date                   : 1980-11-10
    Gender                       : MALE
    Employment Start Date        : 2019-03-15
    Employment End Date          : 2026-12-31
    Email 1                      : robert.johnson@company.com
    Phone 1                      : +63-912-345-6791
    Address                      : 789 Pine Rd, Cebu City
    Department                   : FINANCE
    Position                     : Finance Director
    Marital Status               : DIVORCED
    Nationality                  : AMERICAN
    Enrollment Status            : RESIGNED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0003
    Plan                         : SILVER
    Premium                      : 12000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 2026-12-31
    Is Company Paid              : 1
    Is Renewal                   : 0
    Is Skipping                  : 0
    Reason For Skipping          : 

  CHANGES MADE:
    - Employment End Date: '' → '2026-12-31'
    - Enrollment Status: 'APPROVED' → 'RESIGNED'
    - Phone 1: '+63-912-345-0791' → '+63-912-345-6791'

DEPENDENTS IMPORTED:
====================================================================================

DEPENDENT #1
---------------------------------------------------------------------------
Principal Employee ID:  EMP-2024-001
Principal ID:           101
Action:                 CREATED
Timestamp:              2026-07-15 14:30:48

  DEPENDENT FIELDS:
    First Name                   : MARIA
    Last Name                    : DOE
    Middle Name                  : GRACE
    Relation                     : SPOUSE
    Birth Date                   : 1986-08-22
    Gender                       : FEMALE
    Marital Status               : MARRIED
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0004
    Plan                         : GOLD
    Premium                      : 15000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Skipping                  : 0

DEPENDENT #2
---------------------------------------------------------------------------
Principal Employee ID:  EMP-2024-001
Principal ID:           101
Action:                 CREATED
Timestamp:              2026-07-15 14:30:49

  DEPENDENT FIELDS:
    First Name                   : DAVID
    Last Name                    : DOE
    Middle Name                  : JAMES
    Relation                     : CHILD
    Birth Date                   : 2012-05-10
    Gender                       : MALE
    Marital Status               : SINGLE
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0005
    Plan                         : GOLD
    Premium                      : 8000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Skipping                  : 0

DEPENDENT #3
---------------------------------------------------------------------------
Principal Employee ID:  EMP-2024-002
Principal ID:           102
Action:                 CREATED
Timestamp:              2026-07-15 14:30:50

  DEPENDENT FIELDS:
    First Name                   : EMILY
    Last Name                    : SMITH
    Middle Name                  : LOUISE
    Relation                     : CHILD
    Birth Date                   : 2015-03-15
    Gender                       : FEMALE
    Marital Status               : SINGLE
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0006
    Plan                         : PLATINUM
    Premium                      : 8500.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Skipping                  : 0

DEPENDENT #4
---------------------------------------------------------------------------
Principal Employee ID:  EMP-2024-002
Principal ID:           102
Action:                 CREATED
Timestamp:              2026-07-15 14:30:51

  DEPENDENT FIELDS:
    First Name                   : MICHAEL
    Last Name                    : SMITH
    Middle Name                  : JOHN
    Relation                     : CHILD
    Birth Date                   : 2018-07-20
    Gender                       : MALE
    Marital Status               : SINGLE
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0007
    Plan                         : PLATINUM
    Premium                      : 8500.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Skipping                  : 0

DEPENDENT #5
---------------------------------------------------------------------------
Principal Employee ID:  EMP-2024-001
Principal ID:           101
Action:                 UPDATED
Timestamp:              2026-07-15 14:30:52

  DEPENDENT FIELDS:
    First Name                   : SOPHIA
    Last Name                    : DOE
    Middle Name                  : ANNE
    Relation                     : CHILD
    Birth Date                   : 2014-11-08
    Gender                       : FEMALE
    Marital Status               : SINGLE
    Nationality                  : FILIPINO
    Enrollment Status            : APPROVED

  HEALTH INSURANCE FIELDS:
    Certificate Number           : HIC-2026-0008
    Plan                         : GOLD
    Premium                      : 8000.00
    Coverage Start Date          : 2026-08-01
    Coverage End Date            : 
    Is Company Paid              : 1
    Is Skipping                  : 0

  CHANGES MADE:
    - Gender: 'MALE' → 'FEMALE'
    - Birth Date: '2014-11-07' → '2014-11-08'

====================================================================================
END OF IMPORT LOG REPORT
====================================================================================
```

## Key Features of This Report

1. **Header Information**: Shows import ID, enrollment, date, and overall status
2. **Summary Statistics**: Quick overview of created/updated counts
3. **Principals Section**: 
   - Each principal with all enrollee fields
   - Health insurance details
   - Changes made (if updated)
4. **Dependents Section**: 
   - Each dependent with all fields
   - Health insurance details
   - Changes made (if updated)
5. **Timestamps**: Each record action timestamped
6. **Clear Formatting**: Easy to read and review

## Usage

- Save this file for audit trail
- Share with stakeholders
- Review for data verification
- Archive in compliance system
- Use for troubleshooting imports

The report provides complete visibility into what was imported and how the data was processed.
