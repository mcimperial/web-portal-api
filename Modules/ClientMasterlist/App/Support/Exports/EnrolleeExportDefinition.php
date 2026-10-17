<?php

namespace Modules\ClientMasterlist\App\Support\Exports;

class EnrolleeExportDefinition
{
    private const CONFIGS = [
        'DEFAULT' => [
            'columns' => [
                'enrollment_status', 'relation', 'premium', 'employee_id', 'first_name',
                'last_name', 'middle_name', 'suffix', 'birth_date', 'marital_status',
                'gender', 'email1', 'phone1', 'department', 'position', 'effective_date'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'effective_date' => 'Effective Date', 'required_document' => 'Required Document',
                'enrollment_status' => 'Enrollment Status', 'relation' => 'Relation',
                'is_newly_added' => 'Renewal Status',
                'employee_id' => 'Employee ID', 'first_name' => 'First Name',
                'last_name' => 'Last Name', 'middle_name' => 'Middle Name',
                'suffix' => 'Suffix', 'birth_date' => 'Birth Date', 'gender' => 'Gender',
                'marital_status' => 'Marital Status', 'email1' => 'Email 1', 'email2' => 'Email 2',
                'phone1' => 'Phone 1', 'phone2' => 'Phone 2', 'address' => 'Address',
                'department' => 'Department', 'position' => 'Position',
                'employment_start_date' => 'Employment Start Date', 'employment_end_date' => 'Employment End Date',
                'start_date' => 'Start Date', 'submission_date' => 'Submission Date', 'back_date' => 'Back Date',
                'notes' => 'Notes', 'status' => 'Status', 'plan' => 'Plan', 'premium' => 'Premium',
                'principal_mbl' => 'Principal MBL', 'principal_room_and_board' => 'Principal Room and Board',
                'dependent_mbl' => 'Dependent MBL', 'dependent_room_and_board' => 'Dependent Room and Board',
                'is_renewal' => 'Is Renewal', 'is_company_paid' => 'Is Company Paid',
                'coverage_start_date' => 'Coverage Start Date', 'coverage_end_date' => 'Coverage End Date',
                'certificate_number' => 'Certificate Number', 'certificate_date_issued' => 'Certificate Date Issued',
                'unmapped_columns' => 'Unmapped Columns'
            ],
        ],
        'MAXI-SCVP' => [
            'columns' => [
                'maxicare_account_code', 'maxicare_employee_id', 'maxicare_first_name',
                'maxicare_last_name', 'maxicare_middle_name', 'maxicare_extension',
                'maxicare_gender', 'maxicare_member_type', 'maxicare_relation',
                'maxicare_address_line_1', 'maxicare_city', 'maxicare_province',
                'maxicare_civil_status', 'maxicare_birth_date', 'maxicare_mobile_no',
                'maxicare_email', 'maxicare_effective_date', 'maxicare_philhealth',
                'maxicare_plan_code'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'maxicare_account_code' => 'Account Code', 'maxicare_employee_id' => 'Employee No',
                'maxicare_first_name' => 'First Name', 'maxicare_last_name' => 'Last Name',
                'maxicare_middle_name' => 'Middle Name', 'maxicare_extension' => 'Extension',
                'maxicare_gender' => 'Gender', 'maxicare_member_type' => 'Member Type',
                'maxicare_relation' => 'Relationship', 'maxicare_address_line_1' => 'Address Line 1',
                'maxicare_city' => 'City', 'maxicare_province' => 'Province',
                'maxicare_civil_status' => 'Civil Status', 'maxicare_birth_date' => 'Birth Date',
                'maxicare_mobile_no' => 'Mobile No', 'maxicare_email' => 'Email',
                'maxicare_effective_date' => 'Effective Date', 'maxicare_philhealth' => 'PhilHealth',
                'maxicare_plan_code' => 'Plan Code'
            ],
        ],
        'MAXI-SCVP-W-PLAN' => [
            'columns' => [
                'maxicare_account_code', 'maxicare_employee_id', 'maxicare_first_name',
                'maxicare_last_name', 'maxicare_middle_name', 'maxicare_extension',
                'maxicare_gender', 'maxicare_member_type', 'maxicare_relation',
                'maxicare_address_line_1', 'maxicare_city', 'maxicare_province',
                'maxicare_civil_status', 'maxicare_birth_date', 'maxicare_mobile_no',
                'maxicare_email', 'maxicare_effective_date', 'maxicare_philhealth',
                'maxicare_plan_code', 'maxicare_plan_description', 'maxicare_plan_type_d'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'maxicare_account_code' => 'Account Code', 'maxicare_employee_id' => 'Employee No',
                'maxicare_first_name' => 'First Name', 'maxicare_last_name' => 'Last Name',
                'maxicare_middle_name' => 'Middle Name', 'maxicare_extension' => 'Extension',
                'maxicare_gender' => 'Gender', 'maxicare_member_type' => 'Member Type',
                'maxicare_relation' => 'Relationship', 'maxicare_address_line_1' => 'Address Line 1',
                'maxicare_city' => 'City', 'maxicare_province' => 'Province',
                'maxicare_civil_status' => 'Civil Status', 'maxicare_birth_date' => 'Birth Date',
                'maxicare_mobile_no' => 'Mobile No', 'maxicare_email' => 'Email',
                'maxicare_effective_date' => 'Effective Date', 'maxicare_philhealth' => 'PhilHealth',
                'maxicare_plan_code' => 'Plan Code',
                'maxicare_plan_description' => 'Plan Description',
                'maxicare_plan_type_d' => 'Plan Type (Principal/Dependent)'
            ],
        ],
        'MAXI-ACVP' => [
            'columns' => [
                'maxicare_employee_id', 'maxicare_last_name', 'maxicare_first_name',
                'maxicare_middle_name', 'maxicare_extension', 'maxicare_gender',
                'maxicare_street', 'maxicare_city', 'maxicare_province', 'maxicare_postal_code',
                'maxicare_email', 'maxicare_mobile_no', 'maxicare_member_type',
                'maxicare_birth_date', 'relation', 'marital_status',
                'maxicare_effective_date', 'maxicare_date_hired', 'maxicare_date_regularization',
                'maxicare_is_philhealth_member', 'maxicare_philhealth_conditions',
                'maxicare_position', 'maxicare_plan_type', 'maxicare_branch_name',
                'maxicare_philhealth_no', 'maxicare_senior_citizen_id_no',
                'maxicare_client_remarks', 'maxicare_phic'
            ],
            'labels' => [
                'remarks' => 'Remarks', 'reason_for_skipping' => 'Reason for Skipping',
                'attachment_for_skip_hierarchy' => 'Attachment for Skip Hierarchy',
                'maxicare_employee_id' => 'EmpNo', 'maxicare_last_name' => 'LastName',
                'maxicare_first_name' => 'FirstName', 'maxicare_middle_name' => 'MiddleName',
                'maxicare_extension' => 'Extension', 'maxicare_gender' => 'Gender',
                'maxicare_street' => 'Street', 'maxicare_city' => 'City',
                'maxicare_province' => 'Province', 'maxicare_postal_code' => 'ZipCode',
                'maxicare_email' => 'Email', 'maxicare_mobile_no' => 'MobileNo',
                'maxicare_member_type' => 'MemberType', 'maxicare_birth_date' => 'BirthDate',
                'relation' => 'RelationshipID', 'marital_status' => 'CivilStat',
                'maxicare_effective_date' => 'EffectiveDate', 'maxicare_date_hired' => 'DateHired',
                'maxicare_date_regularization' => 'RegDate',
                'maxicare_is_philhealth_member' => 'If Enrollee is a Philhealth member',
                'maxicare_philhealth_conditions' => 'Philhealth conditions',
                'maxicare_position' => 'POSITION', 'maxicare_plan_type' => 'PLAN TYPE',
                'maxicare_branch_name' => 'BRANCH NAME', 'maxicare_philhealth_no' => 'PHILHEALTH NO.',
                'maxicare_senior_citizen_id_no' => 'SENIOR CITIZEN ID. NO.',
                'maxicare_client_remarks' => 'Client Remarks', 'maxicare_phic' => 'PHIC'
            ],
        ],
    ];

    private const CUSTOM_TYPES = [
        'MAXI-SCVP',
        'MAXI-SCVP-W-PLAN',
        'MAXI-ACVP',
    ];

    private const INSURANCE_FIELDS = [
        'plan', 'premium', 'principal_mbl', 'principal_room_and_board',
        'dependent_mbl', 'dependent_room_and_board', 'is_renewal', 'is_company_paid',
        'coverage_start_date', 'coverage_end_date', 'certificate_number', 'certificate_date_issued',
    ];

    private const RELATION_CODE_MAP = [
        'SPOUSE' => 'SP',
        'CHILD' => 'C',
        'PARENT' => 'PR',
        'SIBLING' => 'SL',
        'DOMESTIC PARTNER' => 'O',
        'COMMON-LAW PARTNER' => 'O',
    ];

    private const CIVIL_STATUS_CODE_MAP = [
        'SINGLE' => 'S',
        'SINGLE PARENT' => 'SP',
        'MARRIED' => 'M',
    ];

    public static function config(string $exportType): array
    {
        return self::CONFIGS[$exportType] ?? self::CONFIGS['DEFAULT'];
    }

    public static function defaultConfig(): array
    {
        return self::CONFIGS['DEFAULT'];
    }

    public static function isCustom(string $exportType): bool
    {
        return in_array($exportType, self::CUSTOM_TYPES, true);
    }

    public static function isInsuranceField(string $column): bool
    {
        return in_array($column, self::INSURANCE_FIELDS, true);
    }

    public static function relationCode(string $relation): string
    {
        return self::RELATION_CODE_MAP[strtoupper($relation)] ?? 'P';
    }

    public static function civilStatusCode(string $maritalStatus): string
    {
        return self::CIVIL_STATUS_CODE_MAP[strtoupper($maritalStatus)] ?? 'S';
    }
}
