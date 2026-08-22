# Ancient Innovations Control App — Hybrid Local + Ops Architecture

## Purpose

This file documents the cost-first architecture decision made before the Brain/Hermes build.
The design goal is to keep the always-on VPS small and inexpensive while using the computer
Ancient Innovations already owns for the expensive intelligence and local operational work.

## Deployment rule

**Local by default. Move only the portion that has a concrete remote-access or 24/7 requirement.**

### Local computer

- Production
- Inventory
- Fulfillment
- Tax & Financial Records
- Ancient Innovations Brain / Hermes
- Scout reasoning/research workers
- Heavy analysis and learning
- Large production/process files

### Ops VPS

- Show Arm
- Vendor portal and vendor authentication
- Show schedule, applications, assignments, maps and vendor-facing documents
- Future Marketing Arm remote-facing workflow
- Brain/Scout results that must be visible remotely
- Narrow Brain Gateway endpoints

## What Pass 9.35 changes

1. **ShowArmDbContext** is now the database boundary for Show Arm UI/services.
   It defaults to the existing local database, so installing this pass does not move data or
   require a VPS. When `ConnectionStrings__ShowArmConnection` is later set, only Show Arm
   consumers can be pointed at the Ops PostgreSQL database.

2. **ShowArmModelConfiguration** is shared between the old ApplicationDbContext and the new
   ShowArmDbContext. This preserves the existing migration history while preventing EF mapping
   rules from drifting between the local and Ops contexts.

3. **ShowFileStorageService** owns Show map storage. It still defaults to
   `wwwroot/uploads/show-maps`, but the storage root and public base URL can be configured for
   an Ops deployment without changing the Show UI.

4. **ShowArmGatewayService** creates the narrow cross-arm write boundary. The first contracts
   are Show sales and expense references. Local Square/Tax data remains local; only the number
   or reference needed by the Show Arm is sent.

5. Existing Show pages, Vendor Shows, Show Inbox, Email Hub, Scout integration, Show Finder,
   Show research, placement, database import and Brain email intake now use ShowArmDbContext.

6. Pass 9.33 vendor-eligibility protection and Pass 9.34 remaining 2026 live schedule are folded
   into this pass so there is one install/test package after the missed download.

## Configuration — current local test

No new environment variables are required. `ShowArmDbContext` falls back to the existing
DefaultConnection. This is intentional: test the architectural seam without changing where the
data lives yet.

## Configuration — later Ops deployment

- `ConnectionStrings__ShowArmConnection`
  PostgreSQL connection string for the Ops Show Arm database.
- `SHOW_ARM_STORAGE_ROOT`
  Persistent directory on the Ops host for Show-facing files.
- `SHOW_ARM_PUBLIC_BASE_URL`
  Optional public base URL if files are served from another host.
- `SHOW_ARM_GATEWAY_KEY`
  Shared secret required by `/api/show-arm/gateway/*` write endpoints. If no key is configured,
  the gateway write endpoints reject requests.

Do not put these secrets in source control. Use environment variables / deployment secrets.

## Gateway contract

The gateway is deliberately narrow. It is not a remote database tunnel.

- `POST /api/show-arm/gateway/sales`
  Receives ShowEditionId, ShowVendorProfileId and GrossSquareSales. It updates the Show result
  and the vendor closeout system-sales value.
- `POST /api/show-arm/gateway/expense`
  Receives only an expense reference/value needed by the Show Arm. The canonical expense still
  belongs to the local Tax & Financial Records Arm. ExternalKey makes retries idempotent.

Required header when the gateway is enabled:

`X-AI-Brain-Key: <SHOW_ARM_GATEWAY_KEY>`

## Important non-goals in this pass

- This pass does **not** move the Show database to the VPS yet.
- This pass does **not** expose the local computer to inbound Internet traffic.
- This pass does **not** move Production/Inventory/Fulfillment/Tax to Ops.
- This pass does **not** move heavy Brain/Hermes/Scout compute to the VPS.
- Brain-email attachment mirroring to Ops will use the Gateway/file-transfer boundary when Ops
  is deployed; existing local email attachments remain unchanged during this compatibility pass.

## Why the fallback matters

The first test after installing Pass 9.35 should look boring: the current app should still work.
Only after the Show Arm passes regression testing do we configure a second database. That keeps
architecture work from destroying a working production system.
