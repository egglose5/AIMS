CREATE TABLE IF NOT EXISTS "FulfillmentOrderLines" (
  "Id" uuid NOT NULL,
  "SourceChannel" varchar(40) NOT NULL,
  "SourceOrderId" varchar(192) NOT NULL,
  "SourceLineItemId" varchar(192) NOT NULL,
  "SourceOrderNumber" varchar(120),
  "SourceCustomerId" varchar(192),
  "CustomerName" varchar(220),
  "CustomerEmail" varchar(256),
  "CustomerPhone" varchar(40),
  "ShipToName" varchar(220),
  "ShipAddress1" varchar(220),
  "ShipAddress2" varchar(220),
  "ShipCity" varchar(120),
  "ShipState" varchar(80),
  "ShipPostalCode" varchar(32),
  "ShipCountry" varchar(80),
  "ProductName" varchar(220) NOT NULL DEFAULT '',
  "VariationName" varchar(220),
  "Sku" varchar(120),
  "Quantity" numeric(18,4) NOT NULL DEFAULT 1,
  "UnitPriceCents" bigint NOT NULL DEFAULT 0,
  "Currency" varchar(4) NOT NULL DEFAULT 'USD',
  "OrderNotes" varchar(2000),
  "SelectionJson" text,
  "ProductionStatus" varchar(40) NOT NULL DEFAULT 'UNASSESSED',
  "FulfillmentStatus" varchar(40) NOT NULL DEFAULT 'OPEN',
  "Carrier" varchar(80),
  "TrackingNumber" varchar(192),
  "ShippedAt" timestamptz,
  "OrderCreatedAt" timestamptz NOT NULL DEFAULT NOW(),
  "CreatedAt" timestamptz NOT NULL DEFAULT NOW(),
  "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(),
  CONSTRAINT "PK_FulfillmentOrderLines" PRIMARY KEY ("Id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "IX_FulfillmentOrderLines_Source" ON "FulfillmentOrderLines" ("SourceChannel", "SourceOrderId", "SourceLineItemId");
CREATE INDEX IF NOT EXISTS "IX_FulfillmentOrderLines_FulfillmentStatus" ON "FulfillmentOrderLines" ("FulfillmentStatus");
CREATE INDEX IF NOT EXISTS "IX_FulfillmentOrderLines_ProductionStatus" ON "FulfillmentOrderLines" ("ProductionStatus");
CREATE INDEX IF NOT EXISTS "IX_FulfillmentOrderLines_OrderCreatedAt" ON "FulfillmentOrderLines" ("OrderCreatedAt");
INSERT INTO "FulfillmentOrderLines" ("Id", "SourceChannel", "SourceOrderId", "SourceLineItemId", "ProductionStatus", "FulfillmentStatus", "OrderCreatedAt", "CreatedAt", "UpdatedAt")
SELECT gen_random_uuid(), 'SQUARE_SHOW_ORDER', s."SquareOrderId", s."LineItemUid",
  CASE WHEN s."Status" = 'NEEDS_PRODUCTION' THEN 'NEEDS_PRODUCTION' ELSE 'UNASSESSED' END,
  CASE WHEN s."Status" = 'READY_TO_SHIP' THEN 'READY_TO_SHIP' WHEN s."Status" = 'SHIPPED' THEN 'SHIPPED' WHEN s."Status" = 'COMPLETE' THEN 'COMPLETE' ELSE 'OPEN' END,
  s."UpdatedAt", NOW(), s."UpdatedAt"
FROM "ShowOrderFulfillments" s
ON CONFLICT ("SourceChannel", "SourceOrderId", "SourceLineItemId") DO NOTHING;
