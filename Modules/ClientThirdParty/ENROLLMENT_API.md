# Third-Party Enrollment API

REST endpoints that allow third-party integrations to **read** enrollment data — including enrollments, principals (employees), dependents, summaries, and principal search.

---

## Base URL

```
/api/v1/third-party
```

---

## Authentication

All endpoints require a **Bearer token** in the `Authorization` header.

```http
Authorization: Bearer <your-token>
```

> The authenticated token must carry the `enrollment:read` permission.

---

## Endpoints

### 1. List Enrollments

```
GET /api/v1/third-party/enrollments
```

Returns a paginated list of enrollments with their associated insurance provider and company.

#### Query Parameters

| Parameter    | Type    | Required | Description                                        | Example  |
|--------------|---------|----------|----------------------------------------------------|----------|
| `company_id` | integer | No       | Filter by company ID                               | `5`      |
| `status`     | string  | No       | Filter by enrollment status (`active`, `inactive`) | `active` |
| `per_page`   | integer | No       | Results per page — max `100`, default `15`         | `15`     |

#### Success Response `200`

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "company_id": 5,
      "status": "active"
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

### 2. Show Enrollment

```
GET /api/v1/third-party/enrollments/{id}
```

Returns the details of a single enrollment including its insurance provider and company.

#### URL Parameters

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

#### Success Response `200`

```json
{
  "success": true,
  "data": {
    "id": 1,
    "company_id": 5,
    "status": "active"
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

### 3. List Principals

```
GET /api/v1/third-party/enrollments/{id}/principals
```

Returns a paginated list of enrolled principals (employees) belonging to the given enrollment.

#### URL Parameters

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

#### Query Parameters

| Parameter           | Type    | Required | Description                                | Example    |
|---------------------|---------|----------|--------------------------------------------|------------|
| `enrollment_status` | string  | No       | Filter by principal's enrollment status    | `active`   |
| `employee_id`       | string  | No       | Filter by employee ID                      | `EMP-0042` |
| `per_page`          | integer | No       | Results per page — max `100`, default `20` | `20`       |

#### Success Response `200`

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

#### URL Parameters

| Parameter     | Type    | Required | Description                 | Example |
|---------------|---------|----------|-----------------------------|---------|
| `id`          | integer | Yes      | The enrollment ID           | `1`     |
| `principalId` | integer | Yes      | The principal (enrollee) ID | `10`    |

#### Success Response `200`

```json
{
  "success": true,
  "data": [
    {
      "id": 20,
      "principal_id": 10,
      "first_name": "Jane",
      "last_name": "Doe"
    }
  ],
  "total": 2
}
```

---

### 5. Enrollment Summary

```
GET /api/v1/third-party/enrollments/{id}/summary
```

Returns quick statistics for an enrollment: total principals, total dependents, and a breakdown of principals grouped by their enrollment status.

#### URL Parameters

| Parameter | Type    | Required | Description       | Example |
|-----------|---------|----------|-------------------|---------|
| `id`      | integer | Yes      | The enrollment ID | `1`     |

#### Success Response `200`

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

### 6. Search Principals

```
GET /api/v1/third-party/principals/search
```

Searches principals (employees) across all enrollments. Results include each principal's dependents.

> **At least one** of `employee_id`, `name`, or `birth_date` must be provided.

#### Query Parameters

| Parameter       | Type    | Required               | Description                                                 | Example      |
|-----------------|---------|------------------------|-------------------------------------------------------------|--------------|
| `employee_id`   | string  | Conditional (see note) | Exact match on employee ID                                  | `EMP-0042`   |
| `name`          | string  | Conditional (see note) | Partial match on first, last, or middle name (min 2 chars)  | `John`       |
| `birth_date`    | string  | Conditional (see note) | Exact date match — format `YYYY-MM-DD`                      | `1990-05-14` |
| `enrollment_id` | integer | No                     | Narrow search to a specific enrollment                      | `1`          |
| `per_page`      | integer | No                     | Results per page — max `100`, default `20`                  | `20`         |

#### Success Response `200`

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

#### Error Response `422`

```json
{
  "success": false,
  "message": "Provide at least one search parameter: employee_id, name, or birth_date."
}
```

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
| `401`       | Unauthenticated — missing or invalid Bearer token    |
| `403`       | Forbidden — token lacks `enrollment:read` permission |
| `404`       | Record not found                                     |
| `422`       | Validation failed — see `message` field for details  |
| `500`       | Internal server error                                |
