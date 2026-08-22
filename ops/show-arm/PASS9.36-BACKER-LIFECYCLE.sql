-- Pass 9.36 - Backer lifecycle cleanup
-- Backers are operational only after promoter acceptance. Legacy BACKER_ASSIGNED rows
-- were created before the primary-vendor-controlled approval flow existed, so future/current
-- legacy rows are cancelled rather than silently treated as confirmed.
UPDATE "ShowAssignments" b
SET "Status" = 'BACKER_CANCELLED',
    "RespondedAt" = COALESCE(b."RespondedAt", NOW())
FROM "ShowEditions" e
WHERE b."ShowEditionId" = e."Id"
  AND b."Status" = 'BACKER_ASSIGNED'
  AND COALESCE(e."EndDate", e."StartDate", make_date(e."Year", 12, 31)) >= DATE '2026-08-18';

-- Also cancel any future/present backer request/acceptance that somehow exists before
-- the show has an accepted application or a legacy COMMITTED primary assignment.
UPDATE "ShowAssignments" b
SET "Status" = 'BACKER_CANCELLED',
    "RespondedAt" = COALESCE(b."RespondedAt", NOW())
FROM "ShowEditions" e
WHERE b."ShowEditionId" = e."Id"
  AND b."Status" IN ('BACKER_OFFERED','BACKER_ACCEPTED')
  AND COALESCE(e."EndDate", e."StartDate", make_date(e."Year", 12, 31)) >= DATE '2026-08-18'
  AND NOT EXISTS (
      SELECT 1 FROM "ShowAssignments" p
      WHERE p."ShowEditionId" = b."ShowEditionId"
        AND p."Status" = 'COMMITTED'
        AND p."Status" NOT LIKE 'BACKER_%'
  )
  AND NOT EXISTS (
      SELECT 1 FROM "ShowApplications" a
      WHERE a."ShowEditionId" = b."ShowEditionId"
        AND UPPER(COALESCE(a."Status",'')) = 'ACCEPTED'
        AND COALESCE(a."Platform",'') <> 'SCOUT_WATCH'
  );
