using Microsoft.EntityFrameworkCore;

namespace MustaineAI.Data;

/// <summary>
/// Single source of truth for Entity Framework configuration of Show Arm entities.
/// ApplicationDbContext keeps this mapping for migration compatibility, while ShowArmDbContext
/// uses the same mapping on the separately configurable Show Arm connection.
/// </summary>
public static class ShowArmModelConfiguration
{
    public static void Configure(ModelBuilder builder)
    {
        builder.Entity<ShowEventEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Name).HasMaxLength(240);
            entity.Property(x => x.PromoterName).HasMaxLength(240);
            entity.Property(x => x.City).HasMaxLength(120);
            entity.Property(x => x.State).HasMaxLength(80);
            entity.Property(x => x.EventType).HasMaxLength(100);
            entity.Property(x => x.WebsiteUrl).HasMaxLength(1000);
            entity.Property(x => x.ExclusionReason).HasMaxLength(1000);
            entity.HasIndex(x => new { x.Name, x.City, x.State }).IsUnique();
        });

        builder.Entity<ShowEditionEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.LeadSource).HasMaxLength(40);
            entity.Property(x => x.ResearchStatus).HasMaxLength(60);
            entity.Property(x => x.Recommendation).HasMaxLength(60);
            entity.Property(x => x.ResearchPriority).HasMaxLength(40);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.JuryFee).HasPrecision(12, 2);
            entity.Property(x => x.BoothFee).HasPrecision(12, 2);
            entity.HasOne(x => x.ShowEvent).WithMany(x => x.Editions).HasForeignKey(x => x.ShowEventId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => new { x.ShowEventId, x.Year }).IsUnique();
            entity.HasIndex(x => x.StartDate);
        });

        builder.Entity<ShowResearchEvidenceEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.EvidenceType).HasMaxLength(80);
            entity.Property(x => x.SourceName).HasMaxLength(300);
            entity.Property(x => x.SourceUrl).HasMaxLength(1200);
            entity.Property(x => x.Reliability).HasMaxLength(40);
            entity.Property(x => x.Sentiment).HasMaxLength(40);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => new { x.ShowEditionId, x.EvidenceType });
        });

        builder.Entity<ShowVendorProfileEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.VendorName).HasMaxLength(220);
            entity.Property(x => x.HomeCity).HasMaxLength(120);
            entity.Property(x => x.HomeState).HasMaxLength(80);
            entity.Property(x => x.MaxTravelHours).HasPrecision(5, 2);
            entity.Property(x => x.TargetGrossSales).HasPrecision(12, 2);
            entity.HasOne(x => x.ApplicationUser).WithMany().HasForeignKey(x => x.ApplicationUserId).OnDelete(DeleteBehavior.SetNull);
            entity.HasIndex(x => x.VendorName).IsUnique();
            entity.HasIndex(x => x.ApplicationUserId).IsUnique();
        });

        builder.Entity<ShowOpportunityEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Priority).HasMaxLength(8);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.ForecastLow).HasPrecision(12, 2);
            entity.Property(x => x.ForecastHigh).HasPrecision(12, 2);
            entity.Property(x => x.ForecastConfidence).HasMaxLength(40);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => new { x.ShowEditionId, x.ShowVendorProfileId, x.Priority });
        });

        builder.Entity<ShowAssignmentEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => new { x.ShowEditionId, x.ShowVendorProfileId }).IsUnique();
        });

        builder.Entity<ShowApplicationEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.Platform).HasMaxLength(80);
            entity.Property(x => x.ApplicationUrl).HasMaxLength(1600);
            entity.Property(x => x.ExternalApplicationId).HasMaxLength(240);
            entity.Property(x => x.ExternalStatus).HasMaxLength(120);
            entity.Property(x => x.NextAction).HasMaxLength(500);
            entity.Property(x => x.JuryFeePaid).HasPrecision(12, 2);
            entity.Property(x => x.BoothFeePaid).HasPrecision(12, 2);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.SetNull);
            entity.HasIndex(x => x.ShowEditionId);
        });

        builder.Entity<ShowForecastEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ForecastLow).HasPrecision(12, 2);
            entity.Property(x => x.ForecastHigh).HasPrecision(12, 2);
            entity.Property(x => x.ExpectedGross).HasPrecision(12, 2);
            entity.Property(x => x.ProductionTarget).HasPrecision(12, 2);
            entity.Property(x => x.Confidence).HasMaxLength(40);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.SetNull);
            entity.HasIndex(x => new { x.ShowEditionId, x.FrozenAt });
        });

        builder.Entity<ShowResultEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.GrossSquareSales).HasPrecision(12, 2);
            entity.Property(x => x.BoothExpense).HasPrecision(12, 2);
            entity.Property(x => x.HotelExpense).HasPrecision(12, 2);
            entity.Property(x => x.TravelExpense).HasPrecision(12, 2);
            entity.Property(x => x.OtherExpense).HasPrecision(12, 2);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => new { x.ShowEditionId, x.ShowVendorProfileId }).IsUnique();
        });

        builder.Entity<ShowTourEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Name).HasMaxLength(240);
            entity.Property(x => x.Region).HasMaxLength(240);
            entity.Property(x => x.Status).HasMaxLength(40);
        });

        builder.Entity<ShowTourStopEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasOne(x => x.ShowTour).WithMany().HasForeignKey(x => x.ShowTourId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => new { x.ShowTourId, x.Sequence }).IsUnique();
            entity.HasIndex(x => new { x.ShowTourId, x.ShowEditionId }).IsUnique();
        });

        builder.Entity<ShowLocationEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Name).HasMaxLength(240);
            entity.HasOne(x => x.ShowEvent).WithMany().HasForeignKey(x => x.ShowEventId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => new { x.ShowEventId, x.Name }).IsUnique();
        });

        builder.Entity<ShowMapEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Title).HasMaxLength(300);
            entity.Property(x => x.MapType).HasMaxLength(80);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => new { x.ShowEditionId, x.Year });
        });

        builder.Entity<ShowLearningEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ActualGross).HasPrecision(12, 2);
            entity.Property(x => x.DollarVariance).HasPrecision(12, 2);
            entity.Property(x => x.PercentVariance).HasPrecision(8, 2);
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => x.ShowEditionId);
        });

        builder.Entity<ShowCalibrationRecordEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.PeriodLabel).HasMaxLength(160);
            entity.Property(x => x.VendorName).HasMaxLength(220);
            entity.Property(x => x.ActualGross).HasPrecision(12, 2);
            entity.Property(x => x.GrossLow).HasPrecision(12, 2);
            entity.Property(x => x.GrossHigh).HasPrecision(12, 2);
            entity.Property(x => x.SourceType).HasMaxLength(80);
            entity.HasOne(x => x.ShowEvent).WithMany().HasForeignKey(x => x.ShowEventId).OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => new { x.ShowEventId, x.Year, x.PeriodLabel, x.VendorName });
        });

        builder.Entity<ShowNoteEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.NoteType).HasMaxLength(40);
            entity.Property(x => x.CreatedBy).HasMaxLength(220);
            entity.Property(x => x.NoteText).HasColumnType("text");
            entity.HasOne(x => x.ShowEdition).WithMany().HasForeignKey(x => x.ShowEditionId).OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.SetNull);
            entity.HasIndex(x => new { x.ShowEditionId, x.CreatedAt });
            entity.HasIndex(x => x.UseForMarketing);
        });

        builder.Entity<ShowEmailIntakeEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ExternalMessageId).HasMaxLength(500);
            entity.Property(x => x.Route).HasMaxLength(80);
            entity.Property(x => x.Status).HasMaxLength(80);
            entity.Property(x => x.MailboxAddress).HasMaxLength(320);
            entity.Property(x => x.UnsubscribeUrl).HasMaxLength(2000);
            entity.HasIndex(x => x.ExternalMessageId).IsUnique().HasFilter("\"ExternalMessageId\" IS NOT NULL");
            entity.HasIndex(x => new { x.Status, x.Route });
        });

        builder.Entity<ShowDiscoveryLeadEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Title).HasMaxLength(500);
            entity.Property(x => x.Url).HasMaxLength(1600);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.HasOne(x => x.ShowVendorProfile).WithMany().HasForeignKey(x => x.ShowVendorProfileId).OnDelete(DeleteBehavior.SetNull);
            entity.HasIndex(x => new { x.TargetYear, x.TargetMonth, x.Status });
            // A discovered show is a placement lead. The same event may legitimately be
            // evaluated for multiple vendors/months, so URL uniqueness must be scoped.
            entity.HasIndex(x => new { x.ShowVendorProfileId, x.TargetYear, x.TargetMonth, x.Url }).IsUnique();
        });

    }
}
