using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace MustaineAI.Data;

/// <summary>
/// Database boundary for the remotely accessible Show Arm.
///
/// IMPORTANT ARCHITECTURE RULE:
/// - Production, Inventory, Fulfillment and Tax continue to use ApplicationDbContext locally.
/// - Show Arm pages/services use this context.
/// - By default this context falls back to the normal local database, so today's installation
///   behaves exactly as before. Setting ConnectionStrings__ShowArmConnection moves only the
///   Show Arm to an Ops PostgreSQL database without moving the local operational arms.
///
/// Identity is included because the vendor portal maps vendor profiles to authenticated users.
/// On an Ops deployment the normal app/identity connection and ShowArmConnection may point to
/// the same small PostgreSQL database.
/// </summary>
public sealed class ShowArmDbContext(DbContextOptions<ShowArmDbContext> options)
    : IdentityDbContext<ApplicationUser>(options)
{
    protected override Version SchemaVersion => IdentitySchemaVersions.Version3;

    public DbSet<ShowEventEntity> ShowEvents => Set<ShowEventEntity>();
    public DbSet<ShowEditionEntity> ShowEditions => Set<ShowEditionEntity>();
    public DbSet<ShowResearchEvidenceEntity> ShowResearchEvidence => Set<ShowResearchEvidenceEntity>();
    public DbSet<ShowVendorProfileEntity> ShowVendorProfiles => Set<ShowVendorProfileEntity>();
    public DbSet<ShowOpportunityEntity> ShowOpportunities => Set<ShowOpportunityEntity>();
    public DbSet<ShowAssignmentEntity> ShowAssignments => Set<ShowAssignmentEntity>();
    public DbSet<ShowApplicationEntity> ShowApplications => Set<ShowApplicationEntity>();
    public DbSet<ShowForecastEntity> ShowForecasts => Set<ShowForecastEntity>();
    public DbSet<ShowResultEntity> ShowResults => Set<ShowResultEntity>();
    public DbSet<ShowTourEntity> ShowTours => Set<ShowTourEntity>();
    public DbSet<ShowTourStopEntity> ShowTourStops => Set<ShowTourStopEntity>();
    public DbSet<ShowLocationEntity> ShowLocations => Set<ShowLocationEntity>();
    public DbSet<ShowMapEntity> ShowMaps => Set<ShowMapEntity>();
    public DbSet<ShowLearningEntity> ShowLearnings => Set<ShowLearningEntity>();
    public DbSet<ShowCalibrationRecordEntity> ShowCalibrationRecords => Set<ShowCalibrationRecordEntity>();
    public DbSet<ShowDiscoveryLeadEntity> ShowDiscoveryLeads => Set<ShowDiscoveryLeadEntity>();
    public DbSet<ShowVendorCloseoutEntity> ShowVendorCloseouts => Set<ShowVendorCloseoutEntity>();
    public DbSet<ShowFinancialReferenceEntity> ShowFinancialReferences => Set<ShowFinancialReferenceEntity>();
    public DbSet<ShowDocumentEntity> ShowDocuments => Set<ShowDocumentEntity>();
    public DbSet<ShowNoteEntity> ShowNotes => Set<ShowNoteEntity>();
    public DbSet<ShowEmailIntakeEntity> ShowEmailIntakes => Set<ShowEmailIntakeEntity>();

    protected override void OnModelCreating(ModelBuilder builder)
    {
        base.OnModelCreating(builder);
        ShowArmModelConfiguration.Configure(builder);
    }
}
