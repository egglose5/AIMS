using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class RemoveSellableProductStandaloneClassification : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropIndex(
                name: "IX_SellableProducts_Category",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "Category",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "ProductType",
                table: "SellableProducts");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "Category",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ProductType",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_Category",
                table: "SellableProducts",
                column: "Category");
        }
    }
}
