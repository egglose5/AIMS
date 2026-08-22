ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "MailboxAddress" varchar(320);
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "BrainSummary" text;
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "ActionSummary" text;
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "IsProtectedSender" boolean NOT NULL DEFAULT false;
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "UnsubscribeUrl" varchar(2000);
ALTER TABLE "ShowEmailIntakes" ADD COLUMN IF NOT EXISTS "UnsubscribeRecommended" boolean NOT NULL DEFAULT false;
