using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddFinishedGoodsTemplateShippingAndCombinationCategories : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "CombinationCategoryKeysCsv",
                table: "FinishedGoodsTemplates",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<decimal>(
                name: "ShippingHeightInches",
                table: "FinishedGoodsTemplates",
                type: "numeric(10,2)",
                precision: 10,
                scale: 2,
                nullable: true);

            migrationBuilder.AddColumn<decimal>(
                name: "ShippingLengthInches",
                table: "FinishedGoodsTemplates",
                type: "numeric(10,2)",
                precision: 10,
                scale: 2,
                nullable: true);

            migrationBuilder.AddColumn<decimal>(
                name: "ShippingWeightOunces",
                table: "FinishedGoodsTemplates",
                type: "numeric(10,2)",
                precision: 10,
                scale: 2,
                nullable: true);

            migrationBuilder.AddColumn<decimal>(
                name: "ShippingWidthInches",
                table: "FinishedGoodsTemplates",
                type: "numeric(10,2)",
                precision: 10,
                scale: 2,
                nullable: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "CombinationCategoryKeysCsv",
                table: "FinishedGoodsTemplates");

            migrationBuilder.DropColumn(
                name: "ShippingHeightInches",
                table: "FinishedGoodsTemplates");

            migrationBuilder.DropColumn(
                name: "ShippingLengthInches",
                table: "FinishedGoodsTemplates");

            migrationBuilder.DropColumn(
                name: "ShippingWeightOunces",
                table: "FinishedGoodsTemplates");

            migrationBuilder.DropColumn(
                name: "ShippingWidthInches",
                table: "FinishedGoodsTemplates");
        }
    }
}
