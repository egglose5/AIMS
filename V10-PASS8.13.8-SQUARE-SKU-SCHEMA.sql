ALTER TABLE "SellableProducts"
ADD COLUMN IF NOT EXISTS "SquareSku" character varying(120);

CREATE INDEX IF NOT EXISTS "IX_SellableProducts_SquareSku"
ON "SellableProducts" ("SquareSku");
