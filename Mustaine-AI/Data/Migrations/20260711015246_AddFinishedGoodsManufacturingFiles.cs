using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddFinishedGoodsManufacturingFiles : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "FinishedGoodsManufacturingFiles",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ElementKey = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    SourceGroupKey = table.Column<string>(type: "character varying(120)", maxLength: 120, nullable: false),
                    ElementLabel = table.Column<string>(type: "character varying(220)", maxLength: 220, nullable: false),
                    StoredFileName = table.Column<string>(type: "character varying(260)", maxLength: 260, nullable: false),
                    RelativeFilePath = table.Column<string>(type: "character varying(400)", maxLength: 400, nullable: false),
                    InputDefinition = table.Column<string>(type: "text", nullable: true),
                    OutputDefinition = table.Column<string>(type: "text", nullable: true),
                    UploadedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_FinishedGoodsManufacturingFiles", x => x.Id);
                });

            migrationBuilder.CreateIndex(
                name: "IX_FinishedGoodsManufacturingFiles_ElementKey",
                table: "FinishedGoodsManufacturingFiles",
                column: "ElementKey",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_FinishedGoodsManufacturingFiles_SourceGroupKey",
                table: "FinishedGoodsManufacturingFiles",
                column: "SourceGroupKey");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "FinishedGoodsManufacturingFiles");
        }
    }
}
