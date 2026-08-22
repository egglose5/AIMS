ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "ExternalMessageId" varchar(500);
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "ToAddress" varchar(1000);
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "Route" varchar(80) NOT NULL DEFAULT 'UNKNOWN';
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "AttachmentSummary" text;
CREATE UNIQUE INDEX IF NOT EXISTS "IX_ShowEmailIntakes_ExternalMessageId" ON "ShowEmailIntakes" ("ExternalMessageId") WHERE "ExternalMessageId" IS NOT NULL;
CREATE INDEX IF NOT EXISTS "IX_ShowEmailIntakes_Status_Route" ON "ShowEmailIntakes" ("Status", "Route");
