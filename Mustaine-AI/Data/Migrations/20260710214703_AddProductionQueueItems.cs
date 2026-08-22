using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddProductionQueueItems : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<decimal>(
                name: "CommissionPercentage",
                table: "SquareTeamMembers",
                type: "numeric(5,2)",
                precision: 5,
                scale: 2,
                nullable: true);

            migrationBuilder.AddColumn<decimal>(
                name: "CommissionPercentage",
                table: "EmployeeRosterEntries",
                type: "numeric(5,2)",
                precision: 5,
                scale: 2,
                nullable: true);

            migrationBuilder.CreateTable(
                name: "ProductionQueueItems",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ProductionDate = table.Column<DateOnly>(type: "date", nullable: false),
                    ElementType = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    ElementKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    ElementName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    SourceGroup = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    SourceReference = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: true),
                    Stage = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    MachineTarget = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    Notes = table.Column<string>(type: "character varying(500)", maxLength: 500, nullable: true),
                    AddedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ProductionQueueItems", x => x.Id);
                });

            migrationBuilder.CreateIndex(
                name: "IX_ProductionQueueItems_ProductionDate",
                table: "ProductionQueueItems",
                column: "ProductionDate");

            migrationBuilder.CreateIndex(
                name: "IX_ProductionQueueItems_ProductionDate_ElementKey",
                table: "ProductionQueueItems",
                columns: new[] { "ProductionDate", "ElementKey" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "ProductionQueueItems");

            migrationBuilder.DropColumn(
                name: "CommissionPercentage",
                table: "SquareTeamMembers");

            migrationBuilder.DropColumn(
                name: "CommissionPercentage",
                table: "EmployeeRosterEntries");
        }
    }
}
