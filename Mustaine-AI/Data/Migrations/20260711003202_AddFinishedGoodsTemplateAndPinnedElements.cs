using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddFinishedGoodsTemplateAndPinnedElements : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "FinishedGoodsTemplates",
                columns: table => new
                {
                    Id = table.Column<string>(type: "character varying(64)", maxLength: 64, nullable: false),
                    TemplateName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    TemplateCategory = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    SkuCategoryLetter = table.Column<string>(type: "character varying(32)", maxLength: 32, nullable: false),
                    SkuNumberDigits = table.Column<int>(type: "integer", nullable: false),
                    LinkedCategoryKeysCsv = table.Column<string>(type: "text", nullable: true),
                    PrimaryCategoryKey = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: true),
                    FrontFaceTierOptionsCsv = table.Column<string>(type: "text", nullable: true),
                    FrontFaceColorOptionsCsv = table.Column<string>(type: "text", nullable: true),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_FinishedGoodsTemplates", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "PinnedElements",
                columns: table => new
                {
                    ElementKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    DisplayName = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    SourceGroup = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_PinnedElements", x => x.ElementKey);
                });

            migrationBuilder.CreateIndex(
                name: "IX_PinnedElements_CreatedAt",
                table: "PinnedElements",
                column: "CreatedAt");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "FinishedGoodsTemplates");

            migrationBuilder.DropTable(
                name: "PinnedElements");
        }
    }
}
