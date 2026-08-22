ALTER TABLE "ShowVendorCloseouts" ADD COLUMN IF NOT EXISTS "CloseoutStatus" VARCHAR(40) NULL DEFAULT 'OPEN';
ALTER TABLE "ShowFinancialReferences" ADD COLUMN IF NOT EXISTS "ReimbursedAmount" NUMERIC NULL;
ALTER TABLE "ShowFinancialReferences" ADD COLUMN IF NOT EXISTS "ReimbursedDate" DATE NULL;
ALTER TABLE "ShowDocuments" ADD COLUMN IF NOT EXISTS "ShowVendorProfileId" BIGINT NULL;
CREATE INDEX IF NOT EXISTS "IX_ShowDocuments_Edition_Vendor" ON "ShowDocuments" ("ShowEditionId","ShowVendorProfileId");
CREATE INDEX IF NOT EXISTS "IX_ShowFinancialReferences_Edition_Vendor_Kind" ON "ShowFinancialReferences" ("ShowEditionId","ShowVendorProfileId","Kind");
