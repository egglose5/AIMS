using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddSquareOrderMoneyBreakdown : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<long>(
                name: "OrderAdjustmentCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<long>(
                name: "OrderDiscountCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<long>(
                name: "OrderGrossBeforeTaxCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<long>(
                name: "OrderServiceChargeCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<long>(
                name: "OrderTaxCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<long>(
                name: "OrderTotalCents",
                table: "SquareSales",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "OrderAdjustmentCents",
                table: "SquareSales");

            migrationBuilder.DropColumn(
                name: "OrderDiscountCents",
                table: "SquareSales");

            migrationBuilder.DropColumn(
                name: "OrderGrossBeforeTaxCents",
                table: "SquareSales");

            migrationBuilder.DropColumn(
                name: "OrderServiceChargeCents",
                table: "SquareSales");

            migrationBuilder.DropColumn(
                name: "OrderTaxCents",
                table: "SquareSales");

            migrationBuilder.DropColumn(
                name: "OrderTotalCents",
                table: "SquareSales");
        }
    }
}
