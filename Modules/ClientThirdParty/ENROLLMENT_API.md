# Third-Party Enrollment API

REST endpoints that allow third-party integrations to **read and write** enrollment data — including enrollments, principals (employees), dependents, and summaries.

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
> - `principal:list:read` for principals listing/search endpoint
> - `principal:read` for single principal read endpoint
> - `principal:create` for principal creation
> - `principal:update` for principal updates
> - `principal:resigned:update` required when updating resigned status via `employee_end_date`
> - `dependent:read` for dependent endpoints
> - `dependent:create` for dependent creation
> - `dependent:update` for dependent updates

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

Use each enrollment `title` for details, summary, and dependents routes.

---

### 2. List Principals (Categorized)

```
GET /api/v1/third-party/principals
```

Returns principals grouped by enrollment title, scoped by selected enrollment permissions.
Each principal record includes its `dependents` array.

Required permission: `principal:list:read`

#### Query Parameters

| Parameter           | Type    | Required | Description                                | Example    |
|---------------------|---------|----------|--------------------------------------------|------------|
| `enrollment_title`  | string  | No       | Restrict to one allowed enrollment title   | `TEST - MAXICARE` |
| `enrollment_status` | string  | No       | Filter by principal's enrollment status    | `ACTIVE`   |
| `employee_id`       | string  | No       | Exact employee ID filter                   | `EMP-0042` |
| `search`            | string  | No       | Search by employee ID, name, enrollment    | `john` |
| `per_page`          | integer | No       | Results per page — max `100`, default `20` | `20`       |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "filters": {
    "enrollment_title": null,
    "enrollment_status": null,
    "employee_id": null,
    "search": "EMP-0042"
  },
  "data": [
    {
      "enrollment_title": "TEST - MAXICARE",
      "total": 1,
      "principals": [
        {
          "id": 10,
          "employee_id": "EMP-0042",
          "first_name": "John",
          "last_name": "Doe",
          "enrollment_status": "ACTIVE",
          "dependents": [
            {
              "id": 20,
              "principal_id": 10,
              "first_name": "Jane",
              "last_name": "Doe"
            }
          ]
        }
      ]
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
GET /api/v1/third-party/enrollments/details
```

Returns the details of a single enrollment including insurance provider and company, resolved by `provider`.

Required permission: `enrollment:read`

#### Query Parameters

| Parameter | Type   | Required | Description                            | Example |
|-----------|--------|----------|----------------------------------------|---------|
| `provider` | string | Yes | Insurance provider title | `MAXICARE` |

#### Working cURL Example

```bash
curl -X GET "http://localhost:8000/api/v1/third-party/enrollments/details?provider=maxicare" \
  -H "X-API-Key: <your-api-key>"
```

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

### 4. Read Single Principal

```
GET /api/v1/third-party/principals/read
```

Returns a single principal record by params, scoped by selected enrollment permissions.
The returned principal always includes its `dependents` array.

Required permission: `principal:read`

#### Query Parameters

| Parameter          | Type   | Required | Description                                      | Example    |
|--------------------|--------|----------|--------------------------------------------------|------------|
| `employee_id`      | string | Yes      | Principal employee ID                            | `EMP-0042` |

#### Sample JSON Response `200`

```json
{
  "success": true,
  "enrollment": {
    "title": "TEST - MAXICARE"
  },
  "data": {
    "id": 10,
    "employee_id": "EMP-0042",
    "first_name": "John",
    "last_name": "Doe",
    "dependents": [
      {
        "id": 20,
        "principal_id": 10,
        "first_name": "Jane",
        "last_name": "Doe"
      }
    ]
  }
}
```

### 5. List Dependents

```
GET /api/v1/third-party/principals/{principalEmployeeId}/dependents
```

Returns all dependents registered under a specific principal, resolved by `provider`.

Required permission: `dependent:read`

#### Parameters

| Parameter     | Type    | Required | Description                 | Example |
|---------------|---------|----------|-----------------------------|---------|
| `principalEmployeeId` | string | Yes | Principal employee ID | `EMP-0042` |
| `provider` (query) | string | Yes | Insurance provider title | `MAXICARE` |

#### Working cURL Example

```bash
curl -X GET "http://localhost:8000/api/v1/third-party/principals/EMP-0042/dependents?provider=maxicare" \
  -H "X-API-Key: <your-api-key>"
```

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

### 6. Create Principal

```
POST /api/v1/third-party/principals
```

Creates a principal using `provider` in the request body. The backend resolves the enrollment automatically from the API key's allowed enrollment/provider scope.

Supports single payload or batch payload via `data: []`.

Required permission: `principal:create`

#### Allowed Fields (Create/Update Principal)

Only the following principal fields are accepted:

- `employee_id`
- `first_name`
- `last_name`
- `suffix` (optional)
- `birth_date`
- `gender` (optional)
- `marital_status` (optional)
- `email1`
- `email2` (optional)
- `phone1` (optional)
- `phone2` (optional)
- `employee_start_date`

For resigned transition updates only:

- `employee_end_date`

Additional request keys used by routing/context:

- `provider` (required)
- `principal_employee_id` (optional for no-path update resolution)

#### Single Payload Example (form-data or JSON)

```json
{
  "provider": "maxicare",
  "employee_id": "EMP-0101",
  "first_name": "JOHN",
  "last_name": "DOE",
  "suffix": "JR",
  "birth_date": "1990-01-01",
  "gender": "MALE",
  "marital_status": "SINGLE",
  "email1": "john.doe@example.com",
  "email2": "john.alt@example.com",
  "phone1": "09171234567",
  "phone2": "0281234567",
  "employee_start_date": "2022-05-01"
}
```

Example `form-data` fields (same values):

```text
provider=maxicare
employee_id=EMP-0101
first_name=JOHN
last_name=DOE
suffix=JR
birth_date=1990-01-01
gender=MALE
marital_status=SINGLE
email1=john.doe@example.com
email2=john.alt@example.com
phone1=09171234567
phone2=0281234567
employee_start_date=2022-05-01
```

Additional example:

```json
{
  "provider": "MAXICARE",
  "employee_id": "EMP-1001",
  "first_name": "JOHN",
  "last_name": "DOE",
  "birth_date": "1990-01-15",
  "email1": "john.doe@example.com",
  "employee_start_date": "2021-01-01"
}
```

#### Batch Payload Example

```json
{
  "data": [
    {
      "provider": "MAXICARE",
      "employee_id": "EMP-1001",
      "first_name": "JOHN",
      "last_name": "DOE",
      "birth_date": "1990-01-15",
      "email1": "john.doe@example.com",
      "employee_start_date": "2021-01-01"
    },
    {
      "provider": "PHILCARE",
      "employee_id": "EMP-1002",
      "first_name": "JANE",
      "last_name": "SMITH",
      "birth_date": "1992-03-22",
      "email1": "jane.smith@example.com",
      "employee_start_date": "2022-09-15"
    }
  ]
}
```

#### Resigned Update Example

If setting `employee_end_date`, credential must include both:

- `principal:update`
- `principal:resigned:update`

Payload (resigned update only):

```json
{
  "provider": "MAXICARE",
  "employee_id": "EMP-1001",
  "employee_end_date": "2026-11-18"
}
```

Behavior:

- Sets `status = INACTIVE`
- Sets `enrollment_status = RESIGNED`
- Sets `back_date = employee_end_date`

### 7. Update Principal

```
PUT /api/v1/third-party/principals/{principalEmployeeId}
PUT /api/v1/third-party/principals
```

Updates a principal using `provider` in body and `principalEmployeeId` in path (or `principal_employee_id` per item for batch/no-path usage).

Supports single payload or batch payload via `data: []`.

Required permission: `principal:update`

If payload includes `employee_end_date`, `principal:resigned:update` is also required.

#### Single Payload Example

Use this route when you already know the principal employee ID in path:

`PUT /api/v1/third-party/principals/EMP-1001`

```json
{
  "provider": "MAXICARE",
  "first_name": "JOHNATHAN",
  "phone1": "09179998888"
}
```

Use this payload if calling `PUT /api/v1/third-party/principals` (no path ID):

```json
{
  "provider": "MAXICARE",
  "employee_id": "EMP-1001",
  "first_name": "JOHNATHAN",
  "phone1": "09179998888"
}
```

Resigned no-path update sample:

```json
{
  "provider": "MAXICARE",
  "employee_id": "EMP-1001",
  "employee_end_date": "2026-11-18"
}
```

#### Batch Payload Example

```json
{
  "data": [
    {
      "provider": "MAXICARE",
      "employee_id": "EMP-1001",
      "first_name": "JOHNATHAN",
      "phone1": "09179998888"
    },
    {
      "provider": "PHILCARE",
      "employee_id": "EMP-1002",
      "email2": "jane.alt@example.com"
    }
  ]
}
```

### 8. Create Dependent

```
POST /api/v1/third-party/principals/{principalEmployeeId}/dependents
POST /api/v1/third-party/principals/dependents
```

Creates a dependent using `provider` in the request body. Enrollment is resolved automatically from provider scope.

Supports single payload or batch payload via `data: []`.

Required permission: `dependent:create`

#### Single Payload Example

Use this route with principal employee ID in path:

`POST /api/v1/third-party/principals/EMP-1001/dependents`

```json
{
  "provider": "MAXICARE",
  "first_name": "JANE",
  "last_name": "DOE",
  "relation": "SPOUSE"
}
```

Use this payload if calling `POST /api/v1/third-party/principals/dependents`:

```json
{
  "provider": "MAXICARE",
  "principal_employee_id": "EMP-1001",
  "first_name": "JANE",
  "last_name": "DOE",
  "relation": "SPOUSE"
}
```

#### Batch Payload Example

```json
{
  "data": [
    {
      "provider": "MAXICARE",
      "principal_employee_id": "EMP-1001",
      "first_name": "JANE",
      "last_name": "DOE",
      "relation": "SPOUSE"
    },
    {
      "provider": "PHILCARE",
      "principal_employee_id": "EMP-1002",
      "first_name": "JIM",
      "last_name": "SMITH",
      "relation": "CHILD"
    }
  ]
}
```

### 9. Update Dependent

```
PUT /api/v1/third-party/principals/{principalEmployeeId}/dependents/{dependentId}
PUT /api/v1/third-party/principals/{principalEmployeeId}/dependents
```

Updates a dependent using `provider` in body. For batch/no-path dependent id, pass `dependent_id` per item.

Supports single payload or batch payload via `data: []`.

Required permission: `dependent:update`

#### Single Payload Example

Use this route with both IDs in path:

`PUT /api/v1/third-party/principals/EMP-1001/dependents/2001`

```json
{
  "provider": "MAXICARE",
  "relation": "CHILD"
}
```

Use this payload if calling `PUT /api/v1/third-party/principals/EMP-1001/dependents`:

```json
{
  "provider": "MAXICARE",
  "dependent_id": 2001,
  "relation": "CHILD"
}
```

#### Batch Payload Example

```json
{
  "data": [
    {
      "provider": "MAXICARE",
      "principal_employee_id": "EMP-1001",
      "dependent_id": 2001,
      "relation": "SPOUSE"
    },
    {
      "provider": "PHILCARE",
      "principal_employee_id": "EMP-1002",
      "dependent_id": 2002,
      "relation": "CHILD"
    }
  ]
}
```

### Provider Validation Errors

If `provider` is missing or not allowed for the API key, the API returns `422` with an item-level error message and `allowed_providers` list.

#### Invalid Provider Response Example (`422`)

```json
{
  "success": false,
  "message": "Provider is not allowed for this API key.",
  "errors": [
    {
      "index": 0,
      "provider": "UNKNOWN_PROVIDER",
      "message": "Provider is not allowed for this API key.",
      "allowed_providers": ["MAXICARE", "PHILCARE"]
    }
  ]
}
```

#### Batch Partial Success Example (`207`)

```json
{
  "success": false,
  "message": "Principal batch created complete. 1 succeeded, 1 failed.",
  "summary": {
    "total": 2,
    "succeeded": 1,
    "failed": 1
  },
  "data": [
    {
      "provider": "MAXICARE",
      "enrollment": {
        "title": "TEST - MAXICARE"
      },
      "data": {
        "employee_id": "EMP-1001"
      }
    }
  ],
  "errors": [
    {
      "index": 1,
      "provider": "UNKNOWN_PROVIDER",
      "message": "Provider is not allowed for this API key.",
      "allowed_providers": ["MAXICARE", "PHILCARE"]
    }
  ]
}
```

### 10. Enrollment Summary

```
GET /api/v1/third-party/enrollments/summary
```

Returns quick statistics for an enrollment resolved by `provider`: total principals, total dependents, and a breakdown of principals grouped by status.

Required permission: `enrollment:read`

#### Query Parameters

| Parameter | Type   | Required | Description                            | Example |
|-----------|--------|----------|----------------------------------------|---------|
| `provider` | string | Yes | Insurance provider title | `MAXICARE` |

#### Working cURL Example

```bash
curl -X GET "http://localhost:8000/api/v1/third-party/enrollments/summary?provider=maxicare" \
  -H "X-API-Key: <your-api-key>"
```

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

Use `GET /principals` for categorized listing and `GET /principals/read` for single principal reads by params.

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
