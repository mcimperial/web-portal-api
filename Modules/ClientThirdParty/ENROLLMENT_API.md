# Third-Party Enrollment API

REST endpoints that allow third-party integrations to **read** enrollment data — including enrollments, principals (employees), dependents, and summaries.

---

## Base URL

```
/api/v1/third-party
```

---

## Authentication

All endpoints require an API key in the `X-API-Key` header.

```http
X-API-Key: <your-api-key>
```

> API permissions are route-specific:
> - `enrollment:read` for enrollment endpoints
> - `principal:read` for principal endpoints
> - `dependent:read` for dependent endpoints

### Credential Params (Enrollment Restriction)

Admins can restrict a credential to one or more enrollment titles using `params.enrollment_titles`.

```json
{
  "name": "ACME HR Integration",
  "permissions": ["enrollment:read"],
  "params": {
    "enrollment_titles": [
      "Acme 2026 Enrollment",
      "Beta Group Enrollment"
    ]
  }
}
```

When configured, the API key can only access those enrollment titles.

---

## Endpoints

### 1. List Enrollments

```
GET /api/v1/third-party/enrollments
```

Returns a paginated list of enrollments with their associated insurance provider and company.

Required permission: `enrollment:read`

#### Query Parameters

| Parameter    | Type    | Required | Description                                        | Example  |
|--------------|---------|----------|----------------------------------------------------|----------|
| `company_id` | integer | No       | Filter by company ID                               | `5`      |
| `status`     | string  | No       | Filter by enrollment status (`active`, `inactive`) | `active` |
| `per_page`   | integer | No       | Results per page — max `100`, default `15`         | `15`     |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "TEST - MAXICARE",
      "company_id": 5,
      "status": "ACTIVE"
    }
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

Use each enrollment `title` as the next path parameter for principals, details, and summary routes.

---

### 2. Show Enrollment Principals (Landing Endpoint)

```
GET /api/v1/third-party/enrollments/{enrollmentTitle}
```

Returns principals (enrollees) for the selected enrollment title.

Required permission: `principal:read`

#### URL Parameters

| Parameter | Type   | Required | Description                            | Example |
|-----------|--------|----------|----------------------------------------|---------|
| `enrollmentTitle` | string | Yes | Enrollment title (URL-encoded) | `TEST%20-%20MAXICARE` |

#### Query Parameters

| Parameter           | Type    | Required | Description                                | Example    |
|---------------------|---------|----------|--------------------------------------------|------------|
| `enrollment_status` | string  | No       | Filter by principal's enrollment status    | `ACTIVE`   |
| `employee_id`       | string  | No       | Filter by employee ID                      | `EMP-0042` |
| `per_page`          | integer | No       | Results per page — max `100`, default `20` | `20`       |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "enrollment": {
    "title": "TEST - MAXICARE"
  },
  "data": [
    {
      "id": 10,
      "enrollment_id": 2,
      "employee_id": "EMP-0042",
      "first_name": "John",
      "last_name": "Doe",
      "enrollment_status": "ACTIVE"
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

---

### 3. Show Enrollment Details

```
GET /api/v1/third-party/enrollments/{enrollmentTitle}/details
```

Returns the details of a single enrollment including insurance provider and company.

Required permission: `enrollment:read`

#### URL Parameters

| Parameter | Type   | Required | Description                            | Example |
|-----------|--------|----------|----------------------------------------|---------|
| `enrollmentTitle` | string | Yes | Enrollment title (URL-encoded) | `TEST%20-%20MAXICARE` |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "data": {
    "title": "TEST - MAXICARE",
    "company_id": 5,
    "status": "ACTIVE"
  }
}
```

#### Error Response `404`

```json
{
  "message": "No query results for model [Enrollment] 999"
}
```

---

### 4. List Principals

```
GET /api/v1/third-party/enrollments/{enrollmentTitle}/principals
```

Returns a paginated list of enrolled principals (employees) belonging to the given enrollment.

Required permission: `principal:read`

#### URL Parameters

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `enrollmentTitle` | string | Yes | The enrollment title (URL-encoded) | `TEST%20-%20MAXICARE` |

#### Query Parameters

| Parameter           | Type    | Required | Description                                | Example    |
|---------------------|---------|----------|--------------------------------------------|------------|
| `enrollment_status` | string  | No       | Filter by principal's enrollment status    | `active`   |
| `employee_id`       | string  | No       | Filter by employee ID                      | `EMP-0042` |
| `per_page`          | integer | No       | Results per page — max `100`, default `20` | `20`       |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "enrollment": {
    "title": "TEST - MAXICARE"
  },
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

  "enrollment": {
    "title": "TEST - MAXICARE"
  },
### 5. List Dependents

```
GET /api/v1/third-party/enrollments/{enrollmentTitle}/principals/{principalEmployeeId}/dependents
      "employee_id": "EMP-0042",
```

Returns all dependents registered under a specific principal within the given enrollment.

Required permission: `dependent:read`

#### URL Parameters

| Parameter     | Type    | Required | Description                 | Example |
|---------------|---------|----------|-----------------------------|---------|
| `enrollmentTitle` | string | Yes    | The enrollment title (URL-encoded) | `Acme%202026%20Enrollment` |
| `principalEmployeeId` | string | Yes | Principal employee ID | `EMP-0042` |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "data": [
    {
      "id": 20,
      "principal_id": 10,
      "employee_id": "EMP-0042",
      "first_name": "Jane",
      "last_name": "Doe"
    }
  ],
  "total": 2
}
```

### 6. Enrollment Summary

```
GET /api/v1/third-party/enrollments/{enrollmentTitle}/summary
```

Returns quick statistics for an enrollment: total principals, total dependents, and a breakdown of principals grouped by their enrollment status.

Required permission: `enrollment:read`

#### URL Parameters

| Parameter | Type   | Required | Description                            | Example |
|-----------|--------|----------|----------------------------------------|---------|
| `enrollmentTitle` | string | Yes | Enrollment title (URL-encoded) | `TEST%20-%20MAXICARE` |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "data": {
    "enrollment": {
      "id": 1,
      "status": "active"
    },
    "total_principals": 35,
    "total_dependents": 12,
    "by_status": {
      "active": 30,
      "pending": 5
    }
  }
}
```

#### Error Response `404`

```json
{
  "message": "No query results for model [Enrollment] 999"
}
```

---

Use `GET /enrollments/{enrollmentTitle}` (landing route) or `GET /enrollments/{enrollmentTitle}/principals` with filters for enrollment-scoped principal lookups.

---

## Common Response Fields

### Pagination `meta` Object

Returned on all list/search endpoints.

| Field          | Type    | Description                      |
|----------------|---------|----------------------------------|
| `current_page` | integer | The current page number          |
| `last_page`    | integer | Total number of pages            |
| `per_page`     | integer | Number of items per page         |
| `total`        | integer | Total number of matching records |

---

## Error Reference

| HTTP Status | Meaning                                              |
|-------------|------------------------------------------------------|
| `401`       | Unauthenticated — missing or invalid `X-API-Key`     |
| `403`       | Forbidden — API key lacks required route permission or enrollment access |
| `404`       | Record not found                                     |
| `422`       | Validation failed — see `message` field for details  |
| `500`       | Internal server error                                |
