\set ON_ERROR_STOP on

-- Pass 9.35.1: seed/reconcile (Abby is stored as the full vendor profile name 'Abby Yount').
-- Originally Pass 9.34: seed the remaining 2026 committed show schedule from the
-- Ancient Innovations Event Operations Center. Idempotent: safe to run again.
DO $$
DECLARE
    r record;
    ev_id bigint;
    ed_id bigint;
    primary_vendor_id bigint;
    backer_vendor_id bigint;
    app_status text;
BEGIN
    CREATE TEMP TABLE seed_2026_schedule (
        show_name text,
        start_date date,
        end_date date,
        city text,
        state text,
        primary_vendor text,
        backer_vendor text,
        source_application_status text,
        source_note text
    ) ON COMMIT DROP;

    INSERT INTO seed_2026_schedule VALUES
      ('Michiana Ren Fair','2026-08-29','2026-08-30','South Bend','IN','Melissa',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Blueberry Festival','2026-08-30','2026-09-02','Plymouth','IN','Abby Yount',NULL,NULL,'Source date text: Aug 30–2, 2026; numeric end-date cell was malformed, so end date follows the source date text.'),
      ('Daniel Bonne Pioneer Festival','2026-09-05','2026-09-06','Winchester','KY','Melissa',NULL,'Accepted','Imported from 2026 Event Operations Center.'),
      ('Frankfort Fall Festival','2026-09-05','2026-09-07','Frankfort','IL','Sonya','Jaime',NULL,'Source assigned team: Sonya / Jaime; imported as Sonya primary + Jaime backer.'),
      ('Lawrenceburg Art In The Park','2026-09-07','2026-09-07','Lawrenceburg','IN','Blake',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Louisville Pride','2026-09-12','2026-09-12','Louisville','KY','Jaime',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Village Peddler Festival','2026-09-12','2026-09-13',NULL,'OH','Blake',NULL,'Accepted','Imported from 2026 Event Operations Center.'),
      ('Nappanee Apple Festival','2026-09-17','2026-09-20','Nappanee','IN','Jaime',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Mitchell Persimmon Festival','2026-09-19','2026-09-26','Mitchell','IN','Blake',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Uncle Pen fest','2026-09-24','2026-09-26','Brown County','IN','Abby Yount',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Warrens Cranberry Festival','2026-09-25','2026-09-27',NULL,'WI','Jaime',NULL,'Accepted','Imported from 2026 Event Operations Center.'),
      ('Atlanta IN art fair','2026-09-26','2026-09-27','Atlanta','IN','Sonya',NULL,NULL,'Source assigned team: Sonya / Matt. Matt is preserved here as source context because he is not assumed to be a vendor-profile login.'),
      ('Chautauqa','2026-09-26','2026-09-27','Madison','IN','Abby Yount',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Riley Days','2026-10-01','2026-10-04','Indianapolis','IN','Jaime',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Seymour Oktoberfest','2026-10-01','2026-10-03','Seymour','IN','Blake',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Lakota West','2026-10-02','2026-10-02',NULL,'OH','Melissa',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('Bardstown Art & Craft','2026-10-10','2026-10-11','Bardstown','KY','Melissa',NULL,'Accepted','Imported from 2026 Event Operations Center.'),
      ('Centerville Pumpkin Festival','2026-10-21','2026-10-24','Centerville','OH','Melissa',NULL,NULL,'Imported from 2026 Event Operations Center.'),
      ('odon harvest festival','2026-10-24','2026-10-24','Odon','IN','Sonya',NULL,'Accepted','Source assigned team: Sonya / Matt. Matt is preserved here as source context because he is not assumed to be a vendor-profile login.'),
      ('Irvington Halloween Market','2026-10-25','2026-10-25','Indianapolis','IN','Jaime',NULL,'Accepted','Imported from the newer 2026 Event Operations Center assignment (Jaime).'),
      ('Lakota East','2026-11-14','2026-11-15',NULL,'OH','Melissa',NULL,'Accepted','Imported from 2026 Event Operations Center.'),
      ('Ryle High School','2026-11-21','2026-11-22',NULL,'KY','Melissa',NULL,'Accepted','Imported from 2026 Event Operations Center.');

    FOR r IN SELECT * FROM seed_2026_schedule ORDER BY start_date, show_name LOOP
        SELECT "Id" INTO primary_vendor_id
          FROM "ShowVendorProfiles"
         WHERE lower("VendorName") = lower(r.primary_vendor)
         LIMIT 1;

        IF primary_vendor_id IS NULL THEN
            RAISE WARNING 'Skipping %: vendor profile % not found', r.show_name, r.primary_vendor;
            CONTINUE;
        END IF;

        -- Reuse the permanent show identity by name when possible. This lets 2026 and 2027
        -- editions live under the same show instead of creating duplicate show identities.
        SELECT "Id" INTO ev_id
          FROM "ShowEvents"
         WHERE lower("Name") = lower(r.show_name)
         ORDER BY "Id"
         LIMIT 1;

        IF ev_id IS NULL THEN
            INSERT INTO "ShowEvents" ("Name","City","State","EventType","IsHardExcluded","Notes","CreatedAt","UpdatedAt")
            VALUES (r.show_name, r.city, r.state, 'Festival', false, r.source_note, now(), now())
            RETURNING "Id" INTO ev_id;
        ELSE
            UPDATE "ShowEvents"
               SET "City" = COALESCE(NULLIF("City",''), r.city),
                   "State" = COALESCE(NULLIF("State",''), r.state),
                   "UpdatedAt" = now()
             WHERE "Id" = ev_id;
        END IF;

        SELECT "Id" INTO ed_id FROM "ShowEditions"
         WHERE "ShowEventId" = ev_id AND "Year" = 2026
         LIMIT 1;

        IF ed_id IS NULL THEN
            INSERT INTO "ShowEditions"
                ("ShowEventId","Year","StartDate","EndDate","Status","LeadSource","ResearchStatus","Recommendation","ResearchPriority","LeadNote","Notes","CreatedAt","UpdatedAt")
            VALUES
                (ev_id,2026,r.start_date,r.end_date,'COMMITTED','2026_OPERATIONS_IMPORT','RESEARCH_COMPLETE','APPROVE','NORMAL',r.source_note,r.source_note,now(),now())
            RETURNING "Id" INTO ed_id;
        ELSE
            UPDATE "ShowEditions"
               SET "StartDate" = r.start_date,
                   "EndDate" = r.end_date,
                   "Status" = 'COMMITTED',
                   "Recommendation" = 'APPROVE',
                   "ResearchStatus" = CASE WHEN "ResearchStatus" IS NULL OR "ResearchStatus" IN ('NEEDS_RESEARCH','RESEARCHING') THEN 'RESEARCH_COMPLETE' ELSE "ResearchStatus" END,
                   "LeadSource" = CASE WHEN "LeadSource" IS NULL OR "LeadSource" IN ('MANUAL','RESEARCHER','SHOW_FINDER') THEN '2026_OPERATIONS_IMPORT' ELSE "LeadSource" END,
                   "Notes" = CASE WHEN "Notes" IS NULL OR btrim("Notes")='' THEN r.source_note ELSE "Notes" END,
                   "UpdatedAt" = now()
             WHERE "Id" = ed_id;
        END IF;

        -- Primary vendor: committed because these are the actual remaining 2026 assignments,
        -- not candidate/approval-test rows.
        IF NOT EXISTS (
            SELECT 1 FROM "ShowAssignments"
             WHERE "ShowEditionId"=ed_id AND "ShowVendorProfileId"=primary_vendor_id
               AND "Status" <> 'BACKER_ASSIGNED'
        ) THEN
            INSERT INTO "ShowAssignments"
                ("ShowEditionId","ShowVendorProfileId","Status","OfferedAt","RespondedAt","CommittedAt")
            VALUES (ed_id,primary_vendor_id,'COMMITTED',now(),now(),now());
        ELSE
            UPDATE "ShowAssignments"
               SET "Status"='COMMITTED', "RespondedAt"=COALESCE("RespondedAt",now()), "CommittedAt"=COALESCE("CommittedAt",now())
             WHERE "ShowEditionId"=ed_id AND "ShowVendorProfileId"=primary_vendor_id
               AND "Status" <> 'BACKER_ASSIGNED';
        END IF;

        -- Preserve an explicitly recognized second person as a show-level backer.
        IF r.backer_vendor IS NOT NULL THEN
            SELECT "Id" INTO backer_vendor_id
              FROM "ShowVendorProfiles"
             WHERE lower("VendorName") = lower(r.backer_vendor)
             LIMIT 1;
            IF backer_vendor_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM "ShowAssignments"
                 WHERE "ShowEditionId"=ed_id AND "ShowVendorProfileId"=backer_vendor_id
                   AND "Status"='BACKER_ASSIGNED'
            ) THEN
                INSERT INTO "ShowAssignments"
                    ("ShowEditionId","ShowVendorProfileId","Status","OfferedAt","RespondedAt","CommittedAt","DeclineReason")
                VALUES (ed_id,backer_vendor_id,'BACKER_ASSIGNED',now(),now(),now(),
                        'BACKER_TERMS|PAID_FROM=UNKNOWN|PRIMARY_VENDOR_ID=' || primary_vendor_id::text || '|SOURCE=2026_OPERATIONS_IMPORT');
            END IF;
        END IF;

        -- Import only a known application decision. Unknown historical application state is
        -- intentionally left alone; vendor commitment is represented by ShowAssignments.
        app_status := CASE lower(COALESCE(r.source_application_status,''))
            WHEN 'accepted' THEN 'ACCEPTED'
            WHEN 'waitlisted' THEN 'WAITLISTED'
            WHEN 'rejected' THEN 'REJECTED'
            ELSE NULL END;

        IF app_status IS NOT NULL THEN
            IF NOT EXISTS (SELECT 1 FROM "ShowApplications" WHERE "ShowEditionId"=ed_id AND "ShowVendorProfileId"=primary_vendor_id) THEN
                INSERT INTO "ShowApplications"
                    ("ShowEditionId","ShowVendorProfileId","Status","DecisionAt","NextAction","Notes")
                VALUES (ed_id,primary_vendor_id,app_status,now(),
                        CASE WHEN app_status='ACCEPTED' THEN '2026 historical application accepted; show is committed.' ELSE 'Imported historical application status.' END,
                        r.source_note);
            END IF;
        END IF;
    END LOOP;
END $$;

SELECT e."StartDate", e."EndDate", s."Name", v."VendorName" AS "PrimaryVendor", a."Status"
FROM "ShowAssignments" a
JOIN "ShowEditions" e ON e."Id"=a."ShowEditionId"
JOIN "ShowEvents" s ON s."Id"=e."ShowEventId"
JOIN "ShowVendorProfiles" v ON v."Id"=a."ShowVendorProfileId"
WHERE e."Year"=2026 AND e."StartDate">='2026-08-18' AND a."Status"='COMMITTED'
ORDER BY e."StartDate", s."Name";
