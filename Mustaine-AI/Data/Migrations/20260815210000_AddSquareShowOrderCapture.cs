using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    public partial class AddSquareShowOrderCapture : Migration
    {
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.Sql(
                """
                ALTER TABLE IF EXISTS "SquareSales"
                ADD COLUMN IF NOT EXISTS "CustomerId" character varying(192);

                ALTER TABLE IF EXISTS "SquareSaleLineItems"
                ADD COLUMN IF NOT EXISTS "Note" character varying(2000);

                ALTER TABLE IF EXISTS "SquareSaleLineItems"
                ADD COLUMN IF NOT EXISTS "ModifiersJson" text;
                """);
        }

        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(name: "CustomerId", table: "SquareSales");
            migrationBuilder.DropColumn(name: "Note", table: "SquareSaleLineItems");
            migrationBuilder.DropColumn(name: "ModifiersJson", table: "SquareSaleLineItems");
        }
    }
}
