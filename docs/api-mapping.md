# API Mapping Reference

This document maps WHMCS module functions to CloudPe CMP API endpoints.

## Authentication

| Aspect | Details |
|--------|---------|
| Method | API Key (Bearer Token) |
| Header | `Authorization: Bearer {api_key}` |
| Base URL | `https://{hostname}/api/v1` |
| Content-Type | `application/json` |
| API Docs | `https://{hostname}/api/docs` |

## Provisioning Functions

| WHMCS Function | HTTP Method | CMP API Endpoint | Notes |
|----------------|-------------|------------------|-------|
| `CreateAccount` | POST | `/instances` | Creates VM with flavor, image, region, project_id |
| `SuspendAccount` | POST | `/instances/{id}/actions` | `{"action": "suspend"}` |
| `UnsuspendAccount` | POST | `/instances/{id}/actions` | `{"action": "resume"}` |
| `TerminateAccount` | DELETE | `/instances/{id}` | Deletes the instance |
| `ChangePackage` | PATCH | `/instances/{id}` | Updates flavor; extends volume |
| `TestConnection` | GET | `/flavors` | Simple connectivity check |

## Instance Actions

| Action | HTTP Method | CMP API Endpoint | Request Body |
|--------|-------------|------------------|-------------|
| Start | POST | `/instances/{id}/actions` | `{"action": "start"}` |
| Stop | POST | `/instances/{id}/actions` | `{"action": "stop"}` |
| Reboot | POST | `/instances/{id}/actions` | `{"action": "reboot"}` |
| Hard Reboot | POST | `/instances/{id}/actions` | `{"action": "hard_reboot"}` |
| Suspend | POST | `/instances/{id}/actions` | `{"action": "suspend"}` |
| Resume | POST | `/instances/{id}/actions` | `{"action": "resume"}` |
| Shelve | POST | `/instances/{id}/actions` | `{"action": "shelve"}` |
| Unshelve | POST | `/instances/{id}/actions` | `{"action": "unshelve"}` |
| Rebuild | POST | `/instances/{id}/actions` | `{"action": "rebuild"}` |

## Instance Management

| Operation | HTTP Method | CMP API Endpoint | Notes |
|-----------|-------------|------------------|-------|
| Get Details | GET | `/instances/{id}` | Add `?sync=true` for fresh data |
| List | GET | `/instances` | Supports pagination and filters |
| Update | PATCH | `/instances/{id}` | Update name, tags, expiry |
| Change Password | POST | `/instances/{id}/password` | `{"password": "..."}` |

## Console

| Operation | HTTP Method | CMP API Endpoint |
|-----------|-------------|------------------|
| Get VNC URL | GET | `/instances/{id}/console` |
| Get Boot Log | GET | `/instances/{id}/console/output?length=100` |
| Create Share | POST | `/instances/{id}/console/share` |
| List Shares | GET | `/instances/{id}/console/shares` |
| Revoke Share | DELETE | `/instances/{id}/console/shares/{share_id}` |

## Resources

| Resource | HTTP Method | CMP API Endpoint | Query Parameters |
|----------|-------------|------------------|-----------------|
| Regions | GET | `/regions` | `?service=vm` |
| Flavors | GET | `/flavors` | `?region=`, `?include_gpu=` |
| Images | GET | `/images` | `?region=`, `?os_distro=` |
| Security Groups | GET | `/security-groups` | `?project_id=` (required), `?region=` |
| Volume Types | GET | `/volumes/types` | `?region=` |

## Volumes

| Operation | HTTP Method | CMP API Endpoint |
|-----------|-------------|------------------|
| List | GET | `/volumes?project_id=` | Required: project_id |
| Get Details | GET | `/volumes/{id}` |
| Extend | POST | `/volumes/{id}/extend` | `{"new_size_gb": 100}` |

## Billing

| Operation | HTTP Method | CMP API Endpoint |
|-----------|-------------|------------------|
| Cost Estimate | POST | `/billing/estimate` |

## Instance Create Request Example

```json
POST /api/v1/instances
Authorization: Bearer {api_key}

{
  "name": "vm-123-456",
  "flavor": "flavor-uuid-or-name",
  "image": "image-uuid-or-name",
  "region": "region-slug",
  "project_id": "project-uuid",
  "boot_volume_size_gb": 50,
  "volume_type": "General Purpose",
  "billing_period": "monthly"
}
```

## Instance Create Response Example

```json
{
  "id": "instance-uuid",
  "name": "vm-123-456",
  "status": "BUILD",
  "flavor": {"id": "...", "name": "...", "vcpu": 2, "memory_gb": 4},
  "image": {"id": "...", "name": "Ubuntu 22.04"},
  "ip_addresses": [],
  "created_at": "2026-04-06T12:00:00Z",
  "admin_password": "generated-password"
}
```

## Error Response Format

```json
{
  "detail": "Error message"
}
```

Or validation errors (FastAPI):

```json
{
  "detail": [
    {
      "loc": ["body", "flavor"],
      "msg": "field required",
      "type": "value_error.missing"
    }
  ]
}
```
