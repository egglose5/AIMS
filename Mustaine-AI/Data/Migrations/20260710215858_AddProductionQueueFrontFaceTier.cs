using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddProductionQueueFrontFaceTier : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "FrontFaceTier",
                table: "ProductionQueueItems",
                type: "character varying(32)",
                maxLength: 32,
                nullable: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "FrontFaceTier",
                table: "ProductionQueueItems");
        }
    }
}
