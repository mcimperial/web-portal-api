# ClientThirdParty Module

This module provides functionality to sync data between the main application database and a third-party sync database.

## Features

- Test database connection
- Browse available tables in sync database
- Query data from sync tables
- Sync data from third-party database to main database
- Get member information from sync database

## Configuration

The sync database credentials are configured in `.env`:

```env
DB_CONNECTION_SYNC=mysql
DB_HOST_SYNC=139.59.105.57
DB_PORT_SYNC=3306
DB_DATABASE_SYNC=llibiapp_sync
DB_USERNAME_SYNC=llibiapp_cyruss
DB_PASSWORD_SYNC=!Lacson0ne
```

The database connection is configured in `config/database.php` as `mysql_sync`.

## API Endpoints

All endpoints require authentication (`auth:sanctum` middleware) and are prefixed with `/api/v1/sync/`.

### 1. Test Connection
**GET** `/api/v1/sync/test-connection`

Tests the connection to the sync database.

**Response:**
```json
{
    "success": true,
    "message": "Successfully connected to sync database",
    "database": "llibiapp_sync"
}
```

### 2. Get Tables
**GET** `/api/v1/sync/tables`

Retrieves all tables from the sync database.

**Response:**
```json
{
    "success": true,
    "tables": ["table1", "table2", ...],
    "count": 19
}
```

### 3. Get Table Data
**GET** `/api/v1/sync/tables/{table}?per_page=15&page=1`

Retrieves paginated data from a specific table.

**Parameters:**
- `table` (required): Table name
- `per_page` (optional): Items per page (default: 15)
- `page` (optional): Page number (default: 1)

**Response:**
```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [...],
        "per_page": 15,
        "total": 100
    }
}
```

### 4. Sync Table Data
**POST** `/api/v1/sync/tables/{table}`

Syncs data from a specific table in the sync database to the main database.

**Response:**
```json
{
    "success": true,
    "message": "Synced data from table: masterlist",
    "synced": 100,
    "failed": 0,
    "errors": []
}
```

### 5. Full Sync
**POST** `/api/v1/sync/full-sync`

Performs a full sync of all tables or specified tables.

**Body (optional):**
```json
{
    "tables": ["masterlist", "companies"]
}
```

**Response:**
```json
{
    "success": true,
    "message": "Full sync completed",
    "results": {
        "masterlist": {
            "success": true,
            "synced": 100
        },
        "companies": {
            "success": true,
            "synced": 50
        }
    }
}
```

### 6. Get Sync Status
**GET** `/api/v1/sync/status`

Gets the current sync status and database information.

**Response:**
```json
{
    "success": true,
    "status": {
        "main_database": "llibi_web_portal",
        "sync_database": "llibiapp_sync",
        "connection_active": true,
        "last_check": "2026-01-05 10:30:00"
    }
}
```

### 7. Get Member Data
**GET** `/api/v1/sync/member?member_id=ABC123`

Retrieves detailed member information from the sync database.

**Parameters:**
- `member_id` (required): Member ID to search for

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "memberId": "ABC123",
            "name": "JOHN DOE",
            "company": "Sample Company",
            "roomAndBoard": "₱ 10,000.00",
            "roomAndBoard2": "5000",
            "roomAndBoard3": "2500",
            "roomAndBoardDependent": "Dependent: 5000",
            "ismbl": "Maximum Benefit Limit",
            "mbl": "₱ 100,000.00",
            "preExisting": "Covered",
            "philHealth": "Required",
            "status": "Active",
            "layer": "1",
            "dateOfInquiry": "05-Jan-2026 10:30 AM"
        }
    ]
}
```

## Usage Example

```php
// Test connection
$response = Http::withToken($token)
    ->get('http://localhost:8000/api/v1/sync/test-connection');

// Get member data
$response = Http::withToken($token)
    ->get('http://localhost:8000/api/v1/sync/member?member_id=ABC123');

// Sync a specific table
$response = Http::withToken($token)
    ->post('http://localhost:8000/api/v1/sync/tables/masterlist');
```

## Available Tables in Sync Database

- `_loa_files_status`
- `check_account_status`
- `companies`
- `companies_v2`
- `doctor_anywhere`
- `doctor_anywhere_amount`
- `doctors`
- `doctors_clinics`
- `hospitals`
- `jobs`
- `masterlist` ⭐ (Main member data)
- `masterlist_20240101`
- `partnership`
- `user_corporate`
- `web_access`

## Error Handling

All endpoints return error responses in the following format:

```json
{
    "success": false,
    "message": "Error description",
    "error": "Detailed error message"
}
```

## Security

- All routes are protected with `auth:sanctum` middleware
- Table names are validated to prevent SQL injection
- All database operations are wrapped in try-catch blocks
- Errors are logged for debugging
