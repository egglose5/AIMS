using System;
using Microsoft.EntityFrameworkCore.Migrations;
using Npgsql.EntityFrameworkCore.PostgreSQL.Metadata;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddPermanentSkuAndInitialInventory : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "ArtworkKey",
                table: "SellableProducts",
                type: "character varying(260)",
                maxLength: 260,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ArtworkName",
                table: "SellableProducts",
                type: "character varying(220)",
                maxLength: 220,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "LeatherCode",
                table: "SellableProducts",
                type: "character varying(8)",
                maxLength: 8,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "PermanentSku",
                table: "SellableProducts",
                type: "character varying(5)",
                maxLength: 5,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ProductTypeCode",
                table: "SellableProducts",
                type: "character varying(40)",
                maxLength: 40,
                nullable: true);

            migrationBuilder.CreateTable(
                name: "InventoryTransactions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    SellableProductId = table.Column<Guid>(type: "uuid", nullable: false),
                    PermanentSku = table.Column<string>(type: "character varying(5)", maxLength: 5, nullable: false),
                    LocationCode = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    TransactionType = table.Column<string>(type: "character varying(40)", maxLength: 40, nullable: false),
                    QuantityDelta = table.Column<int>(type: "integer", nullable: false),
                    Notes = table.Column<string>(type: "character varying(500)", maxLength: 500, nullable: true),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_InventoryTransactions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_InventoryTransactions_SellableProducts_SellableProductId",
                        column: x => x.SellableProductId,
                        principalTable: "SellableProducts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Restrict);
                });

            migrationBuilder.CreateTable(
                name: "PermanentSkuSequences",
                columns: table => new
                {
                    Id = table.Column<int>(type: "integer", nullable: false)
                        .Annotation("Npgsql:ValueGenerationStrategy", NpgsqlValueGenerationStrategy.IdentityByDefaultColumn),
                    LastIssuedNumber = table.Column<int>(type: "integer", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_PermanentSkuSequences", x => x.Id);
                    table.CheckConstraint("CK_PermanentSkuSequence_SingleRow", "\"Id\" = 1");
                });

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_PermanentSku",
                table: "SellableProducts",
                column: "PermanentSku",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_ProductTypeCode_ArtworkKey_LeatherCode",
                table: "SellableProducts",
                columns: new[] { "ProductTypeCode", "ArtworkKey", "LeatherCode" },
                unique: true,
                filter: "\"ProductTypeCode\" IS NOT NULL AND \"ArtworkKey\" IS NOT NULL AND \"LeatherCode\" IS NOT NULL");

            migrationBuilder.CreateIndex(
                name: "IX_InventoryTransactions_CreatedAt",
                table: "InventoryTransactions",
                column: "CreatedAt");

            migrationBuilder.CreateIndex(
                name: "IX_InventoryTransactions_LocationCode_PermanentSku",
                table: "InventoryTransactions",
                columns: new[] { "LocationCode", "PermanentSku" });

            migrationBuilder.CreateIndex(
                name: "IX_InventoryTransactions_SellableProductId",
                table: "InventoryTransactions",
                column: "SellableProductId");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "InventoryTransactions");

            migrationBuilder.DropTable(
                name: "PermanentSkuSequences");

            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_PermanentSku",
                table: "SellableProducts");

            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_ProductTypeCode_ArtworkKey_LeatherCode",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ArtworkKey",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ArtworkName",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "LeatherCode",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "PermanentSku",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ProductTypeCode",
                table: "SellableProducts");

        }
    }
}
