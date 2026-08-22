CREATE TABLE IF NOT EXISTS "ShowVendorCloseouts" (
 "Id" BIGSERIAL PRIMARY KEY, "ShowEditionId" BIGINT NOT NULL, "ShowVendorProfileId" BIGINT NOT NULL,
 "VendorTrackedSales" NUMERIC NULL, "SystemSquareSales" NUMERIC NULL, "CommissionRate" NUMERIC NULL,
 "CommissionEarned" NUMERIC NULL, "CommissionPaid" NUMERIC NULL, "CommissionPaidDate" DATE NULL,
 "MileageReported" NUMERIC NULL, "VendorNotes" TEXT NULL, "ClosedAt" TIMESTAMPTZ NULL, "UpdatedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS "IX_ShowVendorCloseouts_Edition_Vendor" ON "ShowVendorCloseouts" ("ShowEditionId","ShowVendorProfileId");
CREATE TABLE IF NOT EXISTS "ShowFinancialReferences" (
 "Id" BIGSERIAL PRIMARY KEY, "ShowEditionId" BIGINT NOT NULL, "ShowVendorProfileId" BIGINT NULL, "Kind" VARCHAR(40) NOT NULL DEFAULT 'EXPENSE',
 "Category" VARCHAR(80) NOT NULL DEFAULT 'OTHER', "Amount" NUMERIC NOT NULL, "Reimbursable" BOOLEAN NOT NULL DEFAULT FALSE,
 "Description" TEXT NULL, "ReceiptPath" TEXT NULL, "TaxArmExternalKey" VARCHAR(200) NULL, "RecordedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS "ShowDocuments" (
 "Id" BIGSERIAL PRIMARY KEY, "ShowEditionId" BIGINT NULL, "DocumentType" VARCHAR(80) NOT NULL DEFAULT 'OTHER', "Title" VARCHAR(500) NOT NULL,
 "StoredPath" TEXT NULL, "SourceUrl" TEXT NULL, "AppliesToYear" INT NULL, "Notes" TEXT NULL, "CreatedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS "ShowEmailIntakes" (
 "Id" BIGSERIAL PRIMARY KEY, "ShowEditionId" BIGINT NULL, "FromAddress" VARCHAR(500) NULL, "Subject" VARCHAR(1000) NULL,
 "BodyText" TEXT NULL, "MessageDate" TIMESTAMPTZ NULL, "Status" VARCHAR(40) NOT NULL DEFAULT 'NEEDS_REVIEW', "MatchNotes" TEXT NULL,
 "ReceivedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
