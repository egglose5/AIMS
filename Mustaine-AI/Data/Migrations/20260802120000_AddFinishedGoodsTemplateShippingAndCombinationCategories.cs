using Microsoft.EntityFrameworkCore.Infrastructure;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    [DbContext(typeof(ApplicationDbContext))]
    [Migration("20260802120000_AddFinishedGoodsTemplateShippingAndCombinationCategories")]
    public partial class AddFinishedGoodsTemplateShippingAndCombinationCategories : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.Sql(
                """
                ALTER TABLE IF EXISTS "FinishedGoodsTemplates"
                ADD COLUMN IF NOT EXISTS "CombinationCategoryKeysCsv" text;

                ALTER TABLE IF EXISTS "FinishedGoodsTemplates"
                ADD COLUMN IF NOT EXISTS "ShippingHeightInches" numeric(10,2);

                ALTER TABLE IF EXISTS "FinishedGoodsTemplates"
                ADD COLUMN IF NOT EXISTS "ShippingLengthInches" numeric(10,2);

                ALTER TABLE IF EXISTS "FinishedGoodsTemplates"
                ADD COLUMN IF NOT EXISTS "ShippingWeightOunces" numeric(10,2);

                ALTER TABLE IF EXISTS "FinishedGoodsTemplates"
                ADD COLUMN IF NOT EXISTS "ShippingWidthInches" numeric(10,2);
                """);
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
