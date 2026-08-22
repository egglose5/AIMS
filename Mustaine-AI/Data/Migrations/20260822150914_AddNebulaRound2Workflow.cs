using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddNebulaRound2Workflow : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "NebulaCreationBatches",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    OperationKey = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    WorkflowType = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    Status = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    RequestedName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    ArtworkKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: true),
                    ArtworkName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: true),
                    PayloadJson = table.Column<string>(type: "text", nullable: true),
                    LastError = table.Column<string>(type: "character varying(2000)", maxLength: 2000, nullable: true),
                    CompletedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_NebulaCreationBatches", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ProductArtworks",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ArtworkKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    ArtworkName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    DesignAssetPath = table.Column<string>(type: "character varying(400)", maxLength: 400, nullable: true),
                    ProductImagePath = table.Column<string>(type: "character varying(400)", maxLength: 400, nullable: true),
                    Notes = table.Column<string>(type: "character varying(500)", maxLength: 500, nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ProductArtworks", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ProductFamilyTemplates",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    FamilyKey = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    FamilyName = table.Column<string>(type: "character varying(160)", maxLength: 160, nullable: false),
                    ProductTypeCode = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: true),
                    ProductionFamily = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    SquareCategoryName = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    SquareCategoryId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    WooCategoryName = table.Column<string>(type: "character varying(160)", maxLength: 160, nullable: true),
                    TaxBehavior = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    InventoryBehavior = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    FulfillmentModel = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    DefaultPriceCents = table.Column<long>(type: "bigint", nullable: false),
                    Currency = table.Column<string>(type: "character varying(4)", maxLength: 4, nullable: false),
                    ShippingLengthInches = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: true),
                    ShippingWidthInches = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: true),
                    ShippingHeightInches = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: true),
                    ShippingWeightOunces = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: true),
                    SellInPerson = table.Column<bool>(type: "boolean", nullable: false),
                    SellOnline = table.Column<bool>(type: "boolean", nullable: false),
                    TrackInventory = table.Column<bool>(type: "boolean", nullable: false),
                    DefaultDescription = table.Column<string>(type: "text", nullable: true),
                    DefaultNotes = table.Column<string>(type: "character varying(500)", maxLength: 500, nullable: true),
                    IsActive = table.Column<bool>(type: "boolean", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ProductFamilyTemplates", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "ProductFamilyVariantOptions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ProductFamilyTemplateId = table.Column<Guid>(type: "uuid", nullable: false),
                    DimensionKey = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    OptionCode = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    OptionName = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    IsDefaultSelected = table.Column<bool>(type: "boolean", nullable: false),
                    IsEnabled = table.Column<bool>(type: "boolean", nullable: false),
                    SortOrder = table.Column<int>(type: "integer", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ProductFamilyVariantOptions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_ProductFamilyVariantOptions_ProductFamilyTemplates_ProductFamilyTemplateId",
                        column: x => x.ProductFamilyTemplateId,
                        principalTable: "ProductFamilyTemplates",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "NebulaCreationBatchVariants",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    BatchId = table.Column<Guid>(type: "uuid", nullable: false),
                    ProductFamilyTemplateId = table.Column<Guid>(type: "uuid", nullable: true),
                    SellableProductId = table.Column<Guid>(type: "uuid", nullable: true),
                    ProductName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    ProductTypeCode = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: true),
                    LeatherCode = table.Column<string>(type: "character varying(8)", maxLength: 8, nullable: true),
                    Status = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    ReservedSquareSku = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    SquareCatalogItemId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    SquareCatalogVariationId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    WooProductId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    WooVariationId = table.Column<string>(type: "character varying(192)", maxLength: 192, nullable: true),
                    LastError = table.Column<string>(type: "character varying(2000)", maxLength: 2000, nullable: true),
                    AttemptCount = table.Column<int>(type: "integer", nullable: false),
                    RetryAllowed = table.Column<bool>(type: "boolean", nullable: false),
                    LastAttemptedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_NebulaCreationBatchVariants", x => x.Id);
                    table.ForeignKey(
                        name: "FK_NebulaCreationBatchVariants_NebulaCreationBatches_BatchId",
                        column: x => x.BatchId,
                        principalTable: "NebulaCreationBatches",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                    table.ForeignKey(
                        name: "FK_NebulaCreationBatchVariants_ProductFamilyTemplates_ProductFamilyTemplateId",
                        column: x => x.ProductFamilyTemplateId,
                        principalTable: "ProductFamilyTemplates",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                    table.ForeignKey(
                        name: "FK_NebulaCreationBatchVariants_SellableProducts_SellableProductId",
                        column: x => x.SellableProductId,
                        principalTable: "SellableProducts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                });

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatches_OperationKey",
                table: "NebulaCreationBatches",
                column: "OperationKey",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatches_Status",
                table: "NebulaCreationBatches",
                column: "Status");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatches_WorkflowType_CreatedAt",
                table: "NebulaCreationBatches",
                columns: new[] { "WorkflowType", "CreatedAt" });

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_BatchId",
                table: "NebulaCreationBatchVariants",
                column: "BatchId");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_BatchId_ProductTypeCode_LeatherCode",
                table: "NebulaCreationBatchVariants",
                columns: new[] { "BatchId", "ProductTypeCode", "LeatherCode" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_ProductFamilyTemplateId",
                table: "NebulaCreationBatchVariants",
                column: "ProductFamilyTemplateId");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_SellableProductId",
                table: "NebulaCreationBatchVariants",
                column: "SellableProductId");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_Status",
                table: "NebulaCreationBatchVariants",
                column: "Status");

            migrationBuilder.CreateIndex(
                name: "IX_ProductArtworks_ArtworkKey",
                table: "ProductArtworks",
                column: "ArtworkKey",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ProductFamilyTemplates_FamilyKey",
                table: "ProductFamilyTemplates",
                column: "FamilyKey",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ProductFamilyTemplates_FamilyName_ProductTypeCode",
                table: "ProductFamilyTemplates",
                columns: new[] { "FamilyName", "ProductTypeCode" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_ProductFamilyVariantOptions_ProductFamilyTemplateId",
                table: "ProductFamilyVariantOptions",
                column: "ProductFamilyTemplateId");

            migrationBuilder.CreateIndex(
                name: "IX_ProductFamilyVariantOptions_ProductFamilyTemplateId_DimensionKey_OptionCode",
                table: "ProductFamilyVariantOptions",
                columns: new[] { "ProductFamilyTemplateId", "DimensionKey", "OptionCode" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "NebulaCreationBatchVariants");

            migrationBuilder.DropTable(
                name: "ProductArtworks");

            migrationBuilder.DropTable(
                name: "ProductFamilyVariantOptions");

            migrationBuilder.DropTable(
                name: "NebulaCreationBatches");

            migrationBuilder.DropTable(
                name: "ProductFamilyTemplates");
        }
    }
}
