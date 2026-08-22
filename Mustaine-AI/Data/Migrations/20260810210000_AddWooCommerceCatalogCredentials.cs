using Microsoft.EntityFrameworkCore.Migrations;
using Microsoft.EntityFrameworkCore.Infrastructure;

#nullable disable
namespace MustaineAI.Data.Migrations;

[Migration("20260810210000_AddWooCommerceCatalogCredentials")]
public partial class AddWooCommerceCatalogCredentials : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.Sql(
            """
            ALTER TABLE IF EXISTS "WooCommerceConnectionSettings"
            ADD COLUMN IF NOT EXISTS "StoreUrl" character varying(500);

            ALTER TABLE IF EXISTS "WooCommerceConnectionSettings"
            ADD COLUMN IF NOT EXISTS "ConsumerKey" character varying(220);

            ALTER TABLE IF EXISTS "WooCommerceConnectionSettings"
            ADD COLUMN IF NOT EXISTS "ConsumerSecret" text;
            """);
    }
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DropColumn(name: "StoreUrl", table: "WooCommerceConnectionSettings");
        migrationBuilder.DropColumn(name: "ConsumerKey", table: "WooCommerceConnectionSettings");
        migrationBuilder.DropColumn(name: "ConsumerSecret", table: "WooCommerceConnectionSettings");
    }
}
