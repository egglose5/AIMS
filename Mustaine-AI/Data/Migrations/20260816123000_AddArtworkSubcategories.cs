using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace MustaineAI.Data.Migrations
{
    public partial class AddArtworkSubcategories : Migration
    {
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "ArtworkSubcategories",
                columns: table => new
                {
                    Id = table.Column<int>(type: "integer", nullable: false)
                        .Annotation("Npgsql:ValueGenerationStrategy", Npgsql.EntityFrameworkCore.PostgreSQL.Metadata.NpgsqlValueGenerationStrategy.IdentityByDefaultColumn),
                    ArtworkKey = table.Column<string>(type: "character varying(300)", maxLength: 300, nullable: false),
                    ArtworkName = table.Column<string>(type: "character varying(300)", maxLength: 300, nullable: false),
                    Subcategory = table.Column<string>(type: "character varying(80)", maxLength: 80, nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table => table.PrimaryKey("PK_ArtworkSubcategories", x => x.Id));

            migrationBuilder.CreateIndex(
                name: "IX_ArtworkSubcategories_ArtworkKey",
                table: "ArtworkSubcategories",
                column: "ArtworkKey",
                unique: true);
        }

        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(name: "ArtworkSubcategories");
        }
    }
}
