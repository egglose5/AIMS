using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    public partial class AddSquareShowOrderCapture : Migration
    {
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "CustomerId",
                table: "SquareSales",
                type: "character varying(192)",
                maxLength: 192,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "Note",
                table: "SquareSaleLineItems",
                type: "character varying(2000)",
                maxLength: 2000,
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ModifiersJson",
                table: "SquareSaleLineItems",
                type: "text",
                nullable: true);
        }

        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(name: "CustomerId", table: "SquareSales");
            migrationBuilder.DropColumn(name: "Note", table: "SquareSaleLineItems");
            migrationBuilder.DropColumn(name: "ModifiersJson", table: "SquareSaleLineItems");
        }
    }
}
