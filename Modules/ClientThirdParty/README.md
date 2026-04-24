# ClientThirdParty Module

This module provides functionality to sync data between the main application database and a third-party sync database, and exposes read-only enrollment data to third-party integrations.

## Features

- Test database connection
- Browse available tables in sync database
- Query data from sync tables
- Sync data from third-party database to main database
- Get member information from sync database
- **[Enrollment API](#enrollment-api)** — read enrollments, principals, dependents, and summaries

---

## Table of Contents

- [Configuration](#configuration)
- [Sync API Endpoints](#api-endpoints)
  - [1. Test Connection](#1-test-connection)
  - [2. Get Tables](#2-get-tables)
  - [3. Get Table Data](#3-get-table-data)
  - [4. Sync Table Data](#4-sync-table-data)
  - [5. Full Sync](#5-full-sync)
  - [6. Get Sync Status](#6-get-sync-status)
  - [7. Get Member Data](#7-get-member-data)
- [Enrollment API](#enrollment-api)
  - [1. List Enrollments](#1-list-enrollments)
  - [2. Show Enrollment](#2-show-enrollment)
  - [3. List Principals](#3-list-principals)
  - [4. List Dependents](#4-list-dependents)
  - [5. Enrollment Summary](#5-enrollment-summary)
  - [6. Search Principals](#6-search-principals)
- [Error Handling](#error-handling)
- [Security](#security)

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

---

## Enrollment API

Read-only endpoints for third-party integrations to access enrollment data.

**Base URL:** `/api/v1/third-party`  
**Authentication:** Bearer token with `enrollment:read` permission required on all routes.

```http
Authorization: Bearer <your-token>
```

---

### 1. List Enrollments

```
GET /api/v1/third-party/enrollments
```

Returns a paginated list of enrollments with their associated insurance provider and company.

**Query Parameters**

| Parameter    | Type    | Required | Description                                        | Example  |
|--------------|---------|----------|----------------------------------------------------|----------|
| `company_id` | integer | No       | Filter by company ID                               | `5`      |
| `status`     | string  | No       | Filter by enrollment status (`active`, `inactive`) | `active` |
| `per_page`   | integer | No       | Results per page — max `100`, default `15`         | `15`     |

**Success Response `200`**

```json
{
  "success": true,
  "data": [
    { "id": 1, "company_id": 5, "status": "active" }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

### 2. Show Enrollment

```
GET /api/v1/third-party/enrollments/{id}
```

Returns the details of a single enrollment including its insurance provider and company relationships.

**URL Parameters**

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

**Success Response `200`**

```json
{
  "success": true,
  "data": { "id": 1, "company_id": 5, "status": "active" }
}
```

**Error Response `404`**

```json
{ "message": "No query results for model [Enrollment] 999" }
```

---

### 3. List Principals

```
GET /api/v1/third-party/enrollments/{id}/principals
```

Returns a paginated list of enrolled principals (employees) belonging to the given enrollment.

**URL Parameters**

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

**Query Parameters**

| Parameter           | Type    | Required | Description                                | Example    |
|---------------------|---------|----------|--------------------------------------------|------------|
| `enrollment_status` | string  | No       | Filter by principal's enrollment status    | `active`   |
| `employee_id`       | string  | No       | Filter by employee ID                      | `EMP-0042` |
| `per_page`          | integer | No       | Results per page — max `100`, default `20` | `20`       |

**Success Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "enrollment_id": 1,
      "employee_id": "EMP-0042",
      "first_name": "John",
      "last_name": "Doe"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 20,
    "total": 35
  }
}
```

---

### 4. List Dependents

```
GET /api/v1/third-party/enrollments/{id}/principals/{principalId}/dependents
```

Returns all dependents registered under a specific principal within the given enrollment.

**URL Parameters**

| Parameter     | Type    | Required | Description                 | Example |
|---------------|---------|----------|-----------------------------|---------|
| `id`          | integer | Yes      | The enrollment ID           | `1`     |
| `principalId` | integer | Yes      | The principal (enrollee) ID | `10`    |

**Success Response `200`**

```json
{
  "success": true,
  "data": [
    { "id": 20, "principal_id": 10, "first_name": "Jane", "last_name": "Doe" }
  ],
  "total": 2
}
```

---

### 5. Enrollment Summary

```
GET /api/v1/third-party/enrollments/{id}/summary
```

Returns quick statistics for an enrollment: total principals, total dependents, and a breakdown of principals grouped by `enrollment_status`.

**URL Parameters**

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

**Success Response `200`**

```json
{
  "success": true,
  "data": {
    "enrollment": { "id": 1, "status": "active" },
    "total_principals": 35,
    "total_dependents": 12,
    "by_status": {
      "active": 30,
      "pending": 5
    }
  }
}
```

**Error Response `404`**

```json
{ "message": "No query results for model [Enrollment] 999" }
```

---

### 6. Search Principals

```
GET /api/v1/third-party/principals/search
```

Searches principals (employees) across all enrollments. Results include each principal's dependents.

> **At least one** of `employee_id`, `name`, or `birth_date` must be provided.

**Query Parameters**

| Parameter       | Type    | Required               | Description                                                | Example      |
|-----------------|---------|------------------------|------------------------------------------------------------|--------------|
| `employee_id`   | string  | Conditional (see note) | Exact match on employee ID                                 | `EMP-0042`   |
| `name`          | string  | Conditional (see note) | Partial match on first, last, or middle name (min 2 chars) | `John`       |
| `birth_date`    | string  | Conditional (see note) | Exact date match — format `YYYY-MM-DD`                     | `1990-05-14` |
| `enrollment_id` | integer | No                     | Narrow search to a specific enrollment                     | `1`          |
| `per_page`      | integer | No                     | Results per page — max `100`, default `20`                 | `20`         |

**Success Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "employee_id": "EMP-0042",
      "first_name": "John",
      "last_name": "Doe",
      "dependents": []
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

**Error Response `422`**

```json
{
  "success": false,
  "message": "Provide at least one search parameter: employee_id, name, or birth_date."
}
```

---

### Enrollment API — Error Reference

| HTTP Status | Meaning                                              |
|-------------|------------------------------------------------------|
| `401`       | Unauthenticated — missing or invalid Bearer token    |
| `403`       | Forbidden — token lacks `enrollment:read` permission |
| `404`       | Record not found                                     |
| `422`       | Validation failed — see `message` for details        |
| `500`       | Internal server error                                |

