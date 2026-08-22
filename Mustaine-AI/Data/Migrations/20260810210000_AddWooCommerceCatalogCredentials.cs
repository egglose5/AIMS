using Microsoft.EntityFrameworkCore.Migrations;
using Microsoft.EntityFrameworkCore.Infrastructure;

#nullable disable
namespace MustaineAI.Data.Migrations;

[Migration("20260810210000_AddWooCommerceCatalogCredentials")]
public partial class AddWooCommerceCatalogCredentials : Migration
{
    protected override void Up(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.AddColumn<string>(name: "StoreUrl", table: "WooCommerceConnectionSettings", type: "character varying(500)", maxLength: 500, nullable: true);
        migrationBuilder.AddColumn<string>(name: "ConsumerKey", table: "WooCommerceConnectionSettings", type: "character varying(220)", maxLength: 220, nullable: true);
        migrationBuilder.AddColumn<string>(name: "ConsumerSecret", table: "WooCommerceConnectionSettings", type: "text", nullable: true);
    }
    protected override void Down(MigrationBuilder migrationBuilder)
    {
        migrationBuilder.DropColumn(name: "StoreUrl", table: "WooCommerceConnectionSettings");
        migrationBuilder.DropColumn(name: "ConsumerKey", table: "WooCommerceConnectionSettings");
        migrationBuilder.DropColumn(name: "ConsumerSecret", table: "WooCommerceConnectionSettings");
    }
}
