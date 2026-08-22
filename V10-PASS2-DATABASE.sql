CREATE TABLE IF NOT EXISTS "ShowOrderFulfillments" (
    "SquareOrderId" varchar(192) NOT NULL,
    "LineItemUid" varchar(192) NOT NULL,
    "Status" varchar(40) NOT NULL DEFAULT 'NEEDS_PRODUCTION',
    "UpdatedAt" timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT "PK_ShowOrderFulfillments" PRIMARY KEY ("SquareOrderId", "LineItemUid")
);
