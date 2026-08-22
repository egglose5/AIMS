using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddSellableProducts : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "SellableProducts",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    Identifier = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    Name = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    Category = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    ProductType = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    PriceCents = table.Column<long>(type: "bigint", nullable: false),
                    CogsCents = table.Column<long>(type: "bigint", nullable: true),
                    Currency = table.Column<string>(type: "character varying(4)", maxLength: 4, nullable: false),
                    Notes = table.Column<string>(type: "character varying(500)", maxLength: 500, nullable: true),
                    IsActive = table.Column<bool>(type: "boolean", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_SellableProducts", x => x.Id);
                });

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_Category",
                table: "SellableProducts",
                column: "Category");

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_Identifier",
                table: "SellableProducts",
                column: "Identifier",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_SellableProducts_IsActive",
                table: "SellableProducts",
                column: "IsActive");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "SellableProducts");
        }
    }
}
