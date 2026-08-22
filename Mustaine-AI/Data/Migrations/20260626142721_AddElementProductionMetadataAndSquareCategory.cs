using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddElementProductionMetadataAndSquareCategory : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "CogsCents",
                table: "SellableProducts");

            migrationBuilder.AddColumn<string>(
                name: "SquareCategoryId",
                table: "SellableProducts",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "SquareCategoryName",
                table: "SellableProducts",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "CategoryName",
                table: "SellableProductElements",
                type: "character varying(120)",
                maxLength: 120,
                nullable: true);

            migrationBuilder.AddColumn<long>(
                name: "CogsCents",
                table: "SellableProductElements",
                type: "bigint",
                nullable: false,
                defaultValue: 0L);

            migrationBuilder.AddColumn<string>(
                name: "DesignFileName",
                table: "SellableProductElements",
                type: "character varying(260)",
                maxLength: 260,
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "HasImage",
                table: "SellableProductElements",
                type: "boolean",
                nullable: false,
                defaultValue: false);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "SquareCategoryId",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "SquareCategoryName",
                table: "SellableProducts");

            migrationBuilder.DropColumn(
                name: "CategoryName",
                table: "SellableProductElements");

            migrationBuilder.DropColumn(
                name: "CogsCents",
                table: "SellableProductElements");

            migrationBuilder.DropColumn(
                name: "DesignFileName",
                table: "SellableProductElements");

            migrationBuilder.DropColumn(
                name: "HasImage",
                table: "SellableProductElements");

            migrationBuilder.AddColumn<long>(
                name: "CogsCents",
                table: "SellableProducts",
                type: "bigint",
                nullable: true);
        }
    }
}
