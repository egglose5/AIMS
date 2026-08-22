using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddNebulaRound3CatalogManagement : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.Sql("""DROP INDEX IF EXISTS "IX_NebulaCreationBatchVariants_BatchId_ProductTypeCode_LeatherCode";""");
            migrationBuilder.Sql("""DROP INDEX IF EXISTS "IX_NebulaCreationBatchVariants_BatchId_ProductTypeCode_Leather~";""");

            migrationBuilder.AddColumn<Guid>(
                name: "MergedIntoProductId",
                table: "SellableProducts",
                type: "uuid",
                nullable: true);

            migrationBuilder.AddColumn<Guid>(
                name: "ReplacedByProductId",
                table: "SellableProducts",
                type: "uuid",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "SellInPerson",
                table: "SellableProducts",
                type: "boolean",
                nullable: false,
                defaultValue: true);

            migrationBuilder.AddColumn<bool>(
                name: "SellOnline",
                table: "SellableProducts",
                type: "boolean",
                nullable: false,
                defaultValue: true);

            migrationBuilder.AddColumn<bool>(
                name: "TrackInventory",
                table: "SellableProducts",
                type: "boolean",
                nullable: false,
                defaultValue: true);

            migrationBuilder.AddColumn<string>(
                name: "ArtworkKey",
                table: "NebulaCreationBatchVariants",
                type: "character varying(260)",
                maxLength: 260,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ArtworkName",
                table: "NebulaCreationBatchVariants",
                type: "character varying(220)",
                maxLength: 220,
                nullable: true);

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_MergedIntoProductId",
                table: "SellableProducts",
                column: "MergedIntoProductId");

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_ReplacedByProductId",
                table: "SellableProducts",
                column: "ReplacedByProductId");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_BatchId_ArtworkKey_ProductTypeC~",
                table: "NebulaCreationBatchVariants",
                columns: new[] { "BatchId", "ArtworkKey", "ProductTypeCode", "LeatherCode" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_MergedIntoProductId",
                table: "SellableProducts");

            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_ReplacedByProductId",
                table: "SellableProducts");

            migrationBuilder.DropIndex(
                name: "IX_NebulaCreationBatchVariants_BatchId_ArtworkKey_ProductTypeC~",
                table: "NebulaCreationBatchVariants");

            migrationBuilder.DropColumn(
                name: "MergedIntoProductId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ReplacedByProductId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "SellInPerson",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "SellOnline",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "TrackInventory",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ArtworkKey",
                table: "NebulaCreationBatchVariants");

            migrationBuilder.DropColumn(
                name: "ArtworkName",
                table: "NebulaCreationBatchVariants");

            migrationBuilder.CreateIndex(
                name: "IX_NebulaCreationBatchVariants_BatchId_ProductTypeCode_LeatherCode",
                table: "NebulaCreationBatchVariants",
                columns: new[] { "BatchId", "ProductTypeCode", "LeatherCode" },
                unique: true);
        }
    }
}
