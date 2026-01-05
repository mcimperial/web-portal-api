# Frontend Integration Guide

## Overview
The ClientThirdParty module provides a public API endpoint for member validation that the frontend can use without authentication.

## API Endpoint

**Public Endpoint (No Authentication Required)**

```
GET /api/v1/sync/member?member_id={id}
```

## Frontend Changes Made

### 1. Updated Hook (`hooks/client/member-validation/dataHook.ts`)
- Changed endpoint from `/api/third-party/member-validation` to `/api/v1/sync/member`
- Updated response handling to match new format: `{success: true, data: [...]}`

### 2. Updated Axios Config (`lib/axios/axios-member-validation.ts`)
- Changed to use main backend URL instead of separate validation server
- Now uses `NEXT_PUBLIC_BACKEND_URL_PROD` / `NEXT_PUBLIC_BACKEND_URL_DEV`

## Testing

### Test with cURL:
```bash
curl "http://localhost:8000/api/v1/sync/member?member_id=ADMUH00053"
```

### Expected Response:
```json
{
    "success": true,
    "data": [
        {
            "memberId": "ADMUH00053",
            "name": "MARY STEPHANIE M. DOFITAS",
            "company": "Asmph-student (BATCH 2022)",
            "roomAndBoard": "--",
            "roomAndBoard2": "",
            "roomAndBoard3": "",
            "roomAndBoardDependent": "",
            "ismbl": "Maximum Benefit Limit",
            "mbl": "",
            "preExisting": "--",
            "philHealth": "Not Required",
            "status": "Inactive",
            "layer": 0,
            "dateOfInquiry": "05-Jan-2026 12:09 PM"
        }
    ]
}
```

## Environment Variables

Make sure your frontend `.env` file has:

```env
NEXT_PUBLIC_BACKEND_URL_DEV=http://localhost:8000/
NEXT_PUBLIC_BACKEND_URL_PROD=https://web-portal-api.llibi.app/
```

## Component Usage

The existing `member-data.tsx` component will work without changes. It already handles:
- Null/undefined values with `??` operators
- Optional chaining with `?.`
- All the fields returned by the API

## Production Deployment

1. Ensure backend environment variables are set in production `.env`
2. The endpoint is public and doesn't require authentication
3. CORS is already configured to allow requests from `https://web-portal.llibi.app`

## Available Member Fields

- `memberId`: Member ID
- `name`: Full name (uppercase)
- `company`: Company affiliation
- `roomAndBoard`: Room & board benefit
- `roomAndBoard2`: Second room & board value
- `roomAndBoard3`: Third room & board value
- `roomAndBoardDependent`: Dependent room & board
- `ismbl`: Benefit limit type (Maximum/Annual)
- `mbl`: Maximum benefit limit amount
- `preExisting`: Pre-existing condition coverage
- `philHealth`: PhilHealth requirement
- `status`: Active/Inactive status
- `layer`: Layer information
- `dateOfInquiry`: Current date/time of query
