using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddSellableProductElementsAndSquareCatalogSync : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "SquareCatalogItemId",
                table: "SellableProducts",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "SquareCatalogVariationId",
                table: "SellableProducts",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.AddColumn<DateTimeOffset>(
                name: "SquareSyncedAt",
                table: "SellableProducts",
                type: "timestamp with time zone",
                nullable: true);

            migrationBuilder.CreateTable(
                name: "SellableProductElements",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    SellableProductId = table.Column<Guid>(type: "uuid", nullable: false),
                    ElementType = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    ElementKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    ElementName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    SortOrder = table.Column<int>(type: "integer", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_SellableProductElements", x => x.Id);
                    table.ForeignKey(
                        name: "FK_SellableProductElements_SellableProducts_SellableProductId",
                        column: x => x.SellableProductId,
                        principalTable: "SellableProducts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateIndex(
                name: "IX_SellableProductElements_SellableProductId",
                table: "SellableProductElements",
                column: "SellableProductId");

            migrationBuilder.CreateIndex(
                name: "IX_SellableProductElements_SellableProductId_ElementKey",
                table: "SellableProductElements",
                columns: new[] { "SellableProductId", "ElementKey" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "SellableProductElements");

            migrationBuilder.DropColumn(
                name: "SquareCatalogItemId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "SquareCatalogVariationId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "SquareSyncedAt",
                table: "SellableProducts");
        }
    }
}
