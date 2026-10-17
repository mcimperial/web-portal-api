<?php

return [
    'modules' => [
        'users',
        'roles',
        'enrollments',
        'applications',
        'reports',
        'settings',
        'imports',
        'communications',
        'action_logs',
        'third_party_api',
        'dashboard',
    ],

    'actions' => [
        'view',
        'export',
        'create',
        'update',
        'delete',
    ],

    'export_requires_view' => true,

    'super_admin_roles' => [
        'admin',
        'admin-se',
    ],

    'presets' => [
        'read_only' => ['view'],
        'export_only' => ['export'],
        'editor' => ['view', 'create', 'update'],
        'full_access' => ['view', 'export', 'create', 'update', 'delete'],
    ],

    'permission_dependencies' => [
        'page.dashboard' => ['dashboard.view'],
        'page.settings.account' => ['settings.view'],
        'page.self-enrollment.admin-access' => [
            'users.view',
            'roles.view',
            'enrollments.view',
        ],
        'page.self-enrollment.manage' => [
            'enrollments.view',
        ],
        'page.self-enrollment.billing' => [
            'enrollments.view',
        ],
        'page.self-enrollment.storage' => [
            'enrollments.view',
        ],
        'page.self-enrollment.deleted-enrollees' => [
            'enrollments.view',
        ],

        'self-enrollment.enrollees.add' => ['applications.create', 'applications.view'],
        'self-enrollment.enrollees.update' => ['applications.update', 'applications.view'],
        'self-enrollment.enrollees.delete' => ['applications.delete', 'applications.view'],
        'self-enrollment.enrollees.import' => ['imports.create', 'imports.view', 'applications.create', 'settings.update'],
        'self-enrollment.enrollees.compare-temp-import' => ['imports.view'],
        'self-enrollment.enrollees.import-company-provider' => ['imports.create', 'imports.view', 'applications.create', 'settings.update'],
        'self-enrollment.enrollees.manage-notifications' => ['communications.view'],
        'self-enrollment.enrollees.card-delivery' => ['applications.update', 'applications.view'],
        'self-enrollment.enrollees.export-legacy' => ['applications.export', 'applications.view'],
        'self-enrollment.enrollees.export-viewer' => ['applications.export', 'applications.view'],
        'self-enrollment.enrollees.table-legacy' => ['applications.view'],
        'self-enrollment.enrollees.table-viewer' => ['applications.view'],
    ],
];
