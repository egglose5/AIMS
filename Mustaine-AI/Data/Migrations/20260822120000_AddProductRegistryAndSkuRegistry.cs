using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddProductRegistryAndSkuRegistry : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "BarcodeValue",
                table: "SellableProducts",
                type: "character varying(160)",
                maxLength: 160,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "CreatedSource",
                table: "SellableProducts",
                type: "character varying(64)",
                maxLength: 64,
                nullable: false,
                defaultValue: "LEGACY");

            migrationBuilder.AddColumn<string>(
                name: "LifecycleStatus",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: false,
                defaultValue: "ACTIVE");

            migrationBuilder.AddColumn<string>(
                name: "ProductFamily",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ProductionFamily",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "WooProductId",
                table: "SellableProducts",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "WooVariationId",
                table: "SellableProducts",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.CreateTable(
                name: "SkuRegistryEntries",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    Sku = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    Status = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    SellableProductId = table.Column<Guid>(type: "uuid", nullable: true),
                    ProductName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: true),
                    VariationName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: true),
                    SquareCatalogItemId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    SquareCatalogVariationId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    WooProductId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    WooVariationId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    BarcodeValue = table.Column<string>(type: "character varying(160)", maxLength: 160, nullable: true),
                    Source = table.Column<string>(type: "character varying(64)", maxLength: 64, nullable: false),
                    ConflictSummary = table.Column<string>(type: "character varying(2000)", maxLength: 2000, nullable: true),
                    ReservedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    AssignedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    RetiredAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    LastReconciledAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_SkuRegistryEntries", x => x.Id);
                    table.ForeignKey(
                        name: "FK_SkuRegistryEntries_SellableProducts_SellableProductId",
                        column: x => x.SellableProductId,
                        principalTable: "SellableProducts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                });

            migrationBuilder.Sql(
                """
                UPDATE "SellableProducts"
                SET "ProductFamily" = COALESCE(NULLIF("SquareCategoryName", ''), "ProductFamily"),
                    "ProductionFamily" = COALESCE("ProductionFamily", NULLIF("SquareCategoryName", '')),
                    "CreatedSource" = CASE
                        WHEN "SquareCatalogItemId" IS NOT NULL OR "SquareCatalogVariationId" IS NOT NULL THEN 'SQUARE_IMPORT'
                        ELSE 'LEGACY'
                    END,
                    "LifecycleStatus" = CASE
                        WHEN COALESCE("IsActive", FALSE) THEN 'ACTIVE'
                        ELSE 'DISCONTINUED'
                    END
                WHERE "ProductFamily" IS NULL
                   OR "ProductionFamily" IS NULL
                   OR "CreatedSource" = 'LEGACY'
                   OR "LifecycleStatus" = 'ACTIVE';
                """);

            migrationBuilder.Sql(
                """
                INSERT INTO "SkuRegistryEntries"
                    ("Id", "Sku", "Status", "SellableProductId", "ProductName", "SquareCatalogItemId", "SquareCatalogVariationId", "WooProductId", "WooVariationId", "BarcodeValue", "Source", "ReservedAt", "AssignedAt", "RetiredAt", "LastReconciledAt", "CreatedAt", "UpdatedAt")
                SELECT
                    gen_random_uuid(),
                    "SquareSku",
                    CASE
                        WHEN COALESCE("LifecycleStatus", 'ACTIVE') = 'DISCONTINUED' THEN 'RETIRED'
                        WHEN COALESCE("LifecycleStatus", 'ACTIVE') = 'DRAFT' THEN 'RESERVED'
                        ELSE 'ASSIGNED'
                    END,
                    "Id",
                    "Name",
                    "SquareCatalogItemId",
                    "SquareCatalogVariationId",
                    "WooProductId",
                    "WooVariationId",
                    "BarcodeValue",
                    COALESCE(NULLIF("CreatedSource", ''), 'LEGACY'),
                    CASE WHEN COALESCE("LifecycleStatus", 'ACTIVE') = 'DRAFT' THEN "CreatedAt" ELSE NULL END,
                    CASE WHEN COALESCE("LifecycleStatus", 'ACTIVE') = 'DRAFT' THEN NULL ELSE "UpdatedAt" END,
                    CASE WHEN COALESCE("LifecycleStatus", 'ACTIVE') = 'DISCONTINUED' THEN "UpdatedAt" ELSE NULL END,
                    NOW(),
                    "CreatedAt",
                    "UpdatedAt"
                FROM "SellableProducts"
                WHERE "SquareSku" IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM "SkuRegistryEntries" existing
                      WHERE existing."Sku" = "SellableProducts"."SquareSku"
                  );
                """);

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_LifecycleStatus",
                table: "SellableProducts",
                column: "LifecycleStatus");

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_SquareSku",
                table: "SellableProducts",
                column: "SquareSku",
                unique: true,
                filter: "\"SquareSku\" IS NOT NULL");

            migrationBuilder.CreateIndex(
                name: "IX_SkuRegistryEntries_SellableProductId",
                table: "SkuRegistryEntries",
                column: "SellableProductId");

            migrationBuilder.CreateIndex(
                name: "IX_SkuRegistryEntries_Sku",
                table: "SkuRegistryEntries",
                column: "Sku",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_SkuRegistryEntries_Status",
                table: "SkuRegistryEntries",
                column: "Status");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "SkuRegistryEntries");

            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_LifecycleStatus",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "BarcodeValue",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "CreatedSource",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "LifecycleStatus",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ProductFamily",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ProductionFamily",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "WooProductId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "WooVariationId",
                table: "SellableProducts");
        }
    }
}
