// Entity Framework Core database context for the Control-App application
// Extends IdentityDbContext to include Identity/Authentication tables plus custom business entities
// Defines all database entities, relationships, and schema configurations

using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace MustaineAI.Data;

/// <summary>
/// Main database context for the Control-App application.
/// Inherits from IdentityDbContext to provide user authentication/authorization tables.
/// All business entities are defined as DbSet properties and configured in OnModelCreating.
/// </summary>
public class ApplicationDbContext(DbContextOptions<ApplicationDbContext> options) : IdentityDbContext<ApplicationUser>(options)
{
    /// <summary>Identity schema version - set to v3 for compatibility with latest ASP.NET Core Identity.</summary>
    protected override Version SchemaVersion => IdentitySchemaVersions.Version3;

    // ===== INTEGRATION SETTINGS =====
    /// <summary>Square POS API connection credentials and configuration.</summary>
    public DbSet<SquareConnectionSettingsEntity> SquareConnectionSettings => Set<SquareConnectionSettingsEntity>();

    /// <summary>WooCommerce ecommerce platform connection credentials.</summary>
    public DbSet<WooCommerceConnectionSettingsEntity> WooCommerceConnectionSettings => Set<WooCommerceConnectionSettingsEntity>();

    // ===== EMPLOYEE MANAGEMENT =====
    /// <summary>Employee roster entries - names, roles, departments, contact info.</summary>
    public DbSet<EmployeeRosterEntryEntity> EmployeeRosterEntries => Set<EmployeeRosterEntryEntity>();

    // ===== PRODUCTION MANAGEMENT =====
    /// <summary>Production machines - laser cutters, 3D printers, engravers, etc.</summary>
    public DbSet<ProductionMachineEntity> ProductionMachines => Set<ProductionMachineEntity>();

    /// <summary>Production queue items - work orders queued for manufacturing.</summary>
    public DbSet<ProductionQueueItemEntity> ProductionQueueItems => Set<ProductionQueueItemEntity>();

    /// <summary>Consumable materials and supplies inventory (paint, resin, cardboard, etc.).</summary>
    public DbSet<StockConsumableEntity> StockConsumables => Set<StockConsumableEntity>();

    // ===== PRODUCT CATALOG =====
    /// <summary>Main sellable products offered for sale.</summary>
    public DbSet<SellableProductEntity> SellableProducts => Set<SellableProductEntity>();

    /// <summary>Product elements - individual components/variants that make up a sellable product.</summary>
    public DbSet<SellableProductElementEntity> SellableProductElements => Set<SellableProductElementEntity>();

    /// <summary>Single-row sequence used to issue permanent five-digit Ancient Innovations SKUs.</summary>
    public DbSet<PermanentSkuSequenceEntity> PermanentSkuSequences => Set<PermanentSkuSequenceEntity>();

    /// <summary>Permanent registry of Ancient Innovations SKU assignments, reservations, and retirements.</summary>
    public DbSet<SkuRegistryEntryEntity> SkuRegistryEntries => Set<SkuRegistryEntryEntity>();

    /// <summary>Reusable Nebula family templates that define defaults for future products and artwork variants.</summary>
    public DbSet<ProductFamilyTemplateEntity> ProductFamilyTemplates => Set<ProductFamilyTemplateEntity>();

    /// <summary>Configured variant options for each Nebula family template.</summary>
    public DbSet<ProductFamilyVariantOptionEntity> ProductFamilyVariantOptions => Set<ProductFamilyVariantOptionEntity>();

    /// <summary>Artwork/design identities and their associated uploaded assets.</summary>
    public DbSet<ProductArtworkEntity> ProductArtworks => Set<ProductArtworkEntity>();

    /// <summary>Round 2 Nebula creation operations for retry-safe partial-failure handling.</summary>
    public DbSet<NebulaCreationBatchEntity> NebulaCreationBatches => Set<NebulaCreationBatchEntity>();

    /// <summary>Per-variant creation/sync state within a Nebula batch.</summary>
    public DbSet<NebulaCreationBatchVariantEntity> NebulaCreationBatchVariants => Set<NebulaCreationBatchVariantEntity>();

    /// <summary>Immutable inventory movement ledger.</summary>
    public DbSet<InventoryTransactionEntity> InventoryTransactions => Set<InventoryTransactionEntity>();

    /// <summary>Manufacturing templates used for product creation.</summary>
    public DbSet<FinishedGoodsTemplateEntity> FinishedGoodsTemplates => Set<FinishedGoodsTemplateEntity>();

    /// <summary>Frequently used elements pinned for quick access.</summary>
    public DbSet<PinnedElementEntity> PinnedElements => Set<PinnedElementEntity>();

    /// <summary>Manufacturing files and design assets for finished goods.</summary>
    public DbSet<FinishedGoodsManufacturingFileEntity> FinishedGoodsManufacturingFiles => Set<FinishedGoodsManufacturingFileEntity>();

    // ===== SQUARE INTEGRATION =====
    /// <summary>Team members synced from Square (sales staff, managers).</summary>
    public DbSet<SquareTeamMemberEntity> SquareTeamMembers => Set<SquareTeamMemberEntity>();

    /// <summary>Sales/orders synced from Square POS system.</summary>
    public DbSet<SquareSaleEntity> SquareSales => Set<SquareSaleEntity>();

    /// <summary>Individual line items within Square sales orders.</summary>
    public DbSet<SquareSaleLineItemEntity> SquareSaleLineItems => Set<SquareSaleLineItemEntity>();

    /// <summary>Fulfillment status for custom Show Order line items.</summary>
    /// <summary>Shared source-agnostic fulfillment lines for shipping and production handoff.</summary>
    public DbSet<FulfillmentOrderLineEntity> FulfillmentOrderLines => Set<FulfillmentOrderLineEntity>();

    public DbSet<ShowOrderFulfillmentEntity> ShowOrderFulfillments => Set<ShowOrderFulfillmentEntity>();

    /// <summary>Shared artwork subcategory classification used by notebook and modular inventory cards.</summary>
    public DbSet<ArtworkSubcategoryEntity> ArtworkSubcategories => Set<ArtworkSubcategoryEntity>();

    /// <summary>Local operational hold list for inventory/barcode cards. Square categories remain untouched.</summary>
    public DbSet<InventoryHoldEntity> InventoryHolds => Set<InventoryHoldEntity>();


    // ===== ANCIENT INNOVATIONS BRAIN CORE =====
    public DbSet<BrainAgentProfileEntity> BrainAgentProfiles => Set<BrainAgentProfileEntity>();
    public DbSet<BrainCapabilityGrantEntity> BrainCapabilityGrants => Set<BrainCapabilityGrantEntity>();
    public DbSet<BrainAuditEventEntity> BrainAuditEvents => Set<BrainAuditEventEntity>();
    public DbSet<BrainMemoryItemEntity> BrainMemoryItems => Set<BrainMemoryItemEntity>();
    public DbSet<BrainDecisionRecordEntity> BrainDecisionRecords => Set<BrainDecisionRecordEntity>();
    public DbSet<BrainLearningCandidateEntity> BrainLearningCandidates => Set<BrainLearningCandidateEntity>();
    public DbSet<BrainContradictionEntity> BrainContradictions => Set<BrainContradictionEntity>();
    public DbSet<BrainToolExecutionEntity> BrainToolExecutions => Set<BrainToolExecutionEntity>();
    public DbSet<BrainApprovalRequestEntity> BrainApprovalRequests => Set<BrainApprovalRequestEntity>();
    public DbSet<BrainReasoningRunEntity> BrainReasoningRuns => Set<BrainReasoningRunEntity>();

    // ===== SHOW ARM =====
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

    /// <summary>
    /// Configures entity models, table schemas, constraints, relationships, and indices.
    /// Called by Entity Framework Core during model creation.
    /// </summary>
    protected override void OnModelCreating(ModelBuilder builder)
    {
        base.OnModelCreating(builder);

        // ===== CONFIGURATION: Ancient Innovations Brain Core =====
        builder.Entity<BrainAgentProfileEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.DisplayName).HasMaxLength(160);
            entity.Property(x => x.Purpose).HasMaxLength(2000);
            entity.Property(x => x.ArmScope).HasMaxLength(120);
            entity.Property(x => x.RuntimeKind).HasMaxLength(120);
            entity.Property(x => x.AutonomyLevel).HasMaxLength(80);
            entity.HasIndex(x => x.AgentKey).IsUnique();
        });
        builder.Entity<BrainCapabilityGrantEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.CapabilityKey).HasMaxLength(200);
            entity.Property(x => x.AccessMode).HasMaxLength(40);
            entity.Property(x => x.BoundaryNote).HasMaxLength(2000);
            entity.HasIndex(x => new { x.AgentKey, x.CapabilityKey }).IsUnique();
        });
        builder.Entity<BrainAuditEventEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.EventType).HasMaxLength(100);
            entity.Property(x => x.TargetArm).HasMaxLength(120);
            entity.Property(x => x.ActionKey).HasMaxLength(200);
            entity.Property(x => x.Outcome).HasMaxLength(80);
            entity.Property(x => x.Rationale).HasColumnType("text"); // B93_LONG_TEXT
            entity.Property(x => x.CorrelationId).HasMaxLength(160);
            entity.HasIndex(x => x.OccurredAt);
            entity.HasIndex(x => x.AgentKey);
        });
        builder.Entity<BrainMemoryItemEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.MemoryKey).HasMaxLength(80);
            entity.Property(x => x.MemoryType).HasMaxLength(40);
            entity.Property(x => x.ArmScope).HasMaxLength(120);
            entity.Property(x => x.SubjectType).HasMaxLength(120);
            entity.Property(x => x.SubjectKey).HasMaxLength(240);
            entity.Property(x => x.Title).HasMaxLength(300);
            entity.Property(x => x.Content).HasColumnType("text");
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.Confidence).HasPrecision(5, 4);
            entity.Property(x => x.SourceType).HasMaxLength(80);
            entity.Property(x => x.SourceRef).HasMaxLength(1000);
            entity.Property(x => x.EvidenceSummary).HasColumnType("text");
            entity.Property(x => x.CreatedBy).HasMaxLength(120);
            entity.HasIndex(x => x.MemoryKey).IsUnique();
            entity.HasIndex(x => new { x.ArmScope, x.SubjectType, x.SubjectKey });
            entity.HasIndex(x => new { x.MemoryType, x.Status });
            entity.HasIndex(x => x.UpdatedAt);
        });
        builder.Entity<BrainDecisionRecordEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.DecisionKey).HasMaxLength(80);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.ArmScope).HasMaxLength(120);
            entity.Property(x => x.SubjectType).HasMaxLength(120);
            entity.Property(x => x.SubjectKey).HasMaxLength(240);
            entity.Property(x => x.DecisionType).HasMaxLength(100);
            entity.Property(x => x.Recommendation).HasColumnType("text");
            entity.Property(x => x.RecommendationReasoning).HasColumnType("text");
            entity.Property(x => x.RecommendationConfidence).HasPrecision(5, 4);
            entity.Property(x => x.HumanDecision).HasColumnType("text");
            entity.Property(x => x.HumanReasoning).HasColumnType("text");
            entity.Property(x => x.Outcome).HasColumnType("text");
            entity.Property(x => x.OutcomeNotes).HasColumnType("text");
            entity.HasIndex(x => x.DecisionKey).IsUnique();
            entity.HasIndex(x => new { x.ArmScope, x.SubjectType, x.SubjectKey });
            entity.HasIndex(x => x.RecommendedAt);
        });
        builder.Entity<BrainLearningCandidateEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.LearningKey).HasMaxLength(80);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.ArmScope).HasMaxLength(120);
            entity.Property(x => x.SubjectType).HasMaxLength(120);
            entity.Property(x => x.SubjectKey).HasMaxLength(240);
            entity.Property(x => x.ProposedLesson).HasColumnType("text");
            entity.Property(x => x.Reasoning).HasColumnType("text");
            entity.Property(x => x.EvidenceRefs).HasColumnType("text");
            entity.Property(x => x.Confidence).HasPrecision(5, 4);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.ReviewReason).HasColumnType("text");
            entity.HasIndex(x => x.LearningKey).IsUnique();
            entity.HasIndex(x => new { x.Status, x.CreatedAt });
            entity.HasIndex(x => new { x.ArmScope, x.SubjectType, x.SubjectKey });
        });
        builder.Entity<BrainContradictionEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Description).HasColumnType("text");
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.DetectedBy).HasMaxLength(120);
            entity.Property(x => x.Resolution).HasColumnType("text");
            entity.Property(x => x.ResolvedBy).HasMaxLength(120);
            entity.HasIndex(x => new { x.Status, x.CreatedAt });
            entity.HasIndex(x => x.MemoryAId);
            entity.HasIndex(x => x.MemoryBId);
        });
        builder.Entity<BrainToolExecutionEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ExecutionKey).HasMaxLength(80);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.ToolKey).HasMaxLength(200);
            entity.Property(x => x.CapabilityKey).HasMaxLength(200);
            entity.Property(x => x.TargetArm).HasMaxLength(120);
            entity.Property(x => x.State).HasMaxLength(40);
            entity.Property(x => x.InputSummary).HasColumnType("text");
            entity.Property(x => x.OutputSummary).HasColumnType("text");
            entity.Property(x => x.DenialReason).HasColumnType("text");
            entity.Property(x => x.CorrelationId).HasMaxLength(160);
            entity.HasIndex(x => x.ExecutionKey).IsUnique();
            entity.HasIndex(x => new { x.AgentKey, x.RequestedAt });
            entity.HasIndex(x => new { x.State, x.RequestedAt });
        });
        builder.Entity<BrainApprovalRequestEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ApprovalKey).HasMaxLength(80);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.ToolKey).HasMaxLength(200);
            entity.Property(x => x.CapabilityKey).HasMaxLength(200);
            entity.Property(x => x.TargetArm).HasMaxLength(120);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.RequestReason).HasColumnType("text");
            entity.Property(x => x.ReviewedBy).HasMaxLength(120);
            entity.Property(x => x.ReviewReason).HasColumnType("text");
            entity.HasIndex(x => x.ApprovalKey).IsUnique();
            entity.HasIndex(x => new { x.Status, x.RequestedAt });
            entity.HasIndex(x => x.BrainToolExecutionId);
        });

        builder.Entity<BrainReasoningRunEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.RunKey).HasMaxLength(80);
            entity.Property(x => x.AgentKey).HasMaxLength(80);
            entity.Property(x => x.TaskType).HasMaxLength(100);
            entity.Property(x => x.SubjectType).HasMaxLength(120);
            entity.Property(x => x.SubjectKey).HasMaxLength(240);
            entity.Property(x => x.ProviderKey).HasMaxLength(100);
            entity.Property(x => x.ModelName).HasMaxLength(200);
            entity.Property(x => x.State).HasMaxLength(40);
            entity.Property(x => x.UserQuestion).HasColumnType("text");
            entity.Property(x => x.ContextSummary).HasColumnType("text");
            entity.Property(x => x.OutputText).HasColumnType("text");
            entity.Property(x => x.ErrorMessage).HasColumnType("text");
            entity.HasIndex(x => x.RunKey).IsUnique();
            entity.HasIndex(x => new { x.AgentKey, x.StartedAt });
            entity.HasIndex(x => new { x.State, x.StartedAt });
        });

        // ===== CONFIGURATION: Show Arm =====
        // The Show Arm model is shared with ShowArmDbContext so its data can move to Ops
        // without duplicating or drifting the EF relationship/index definitions.
        ShowArmModelConfiguration.Configure(builder);

        builder.Entity<ArtworkSubcategoryEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ArtworkKey).HasMaxLength(300);
            entity.Property(x => x.ArtworkName).HasMaxLength(300);
            entity.Property(x => x.Subcategory).HasMaxLength(80);
            entity.HasIndex(x => x.ArtworkKey).IsUnique();
        });

        builder.Entity<InventoryHoldEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.HoldKey).HasMaxLength(500);
            entity.Property(x => x.ItemName).HasMaxLength(300);
            entity.Property(x => x.OriginalCategory).HasMaxLength(160);
            entity.HasIndex(x => x.HoldKey).IsUnique();
        });

        // ===== CONFIGURATION: Square Connection Settings =====
        builder.Entity<SquareConnectionSettingsEntity>(entity =>
        {
            entity.HasKey(x => x.Id);  // Single record per environment (Sandbox/Production)
            entity.Property(x => x.Id).HasMaxLength(64);
            entity.Property(x => x.Environment).HasMaxLength(32);  // "Sandbox" or "Production"
            entity.Property(x => x.ApplicationId).HasMaxLength(192);  // OAuth application ID
            entity.Property(x => x.ApiVersion).HasMaxLength(32);  // API version (e.g., "2024-06-14")
        });

        // ===== CONFIGURATION: WooCommerce Connection Settings =====
        builder.Entity<WooCommerceConnectionSettingsEntity>(entity =>
        {
            entity.HasKey(x => x.Id);  // Single record
            entity.Property(x => x.Id).HasMaxLength(64);
            entity.Property(x => x.StoreUrl).HasMaxLength(500);
            entity.Property(x => x.ConsumerKey).HasMaxLength(220);
        });

        // ===== CONFIGURATION: Employee Roster =====
        builder.Entity<EmployeeRosterEntryEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Name).HasMaxLength(220);
            entity.Property(x => x.Role).HasMaxLength(120);  // e.g., "Manager", "Production Tech"
            entity.Property(x => x.Department).HasMaxLength(120);
            entity.Property(x => x.Status).HasMaxLength(32);  // e.g., "ACTIVE", "INACTIVE"
            entity.Property(x => x.EmailAddress).HasMaxLength(256);
            entity.Property(x => x.PhoneNumber).HasMaxLength(40);
            entity.Property(x => x.CommissionPercentage).HasPrecision(5, 2);  // Decimal(5,2) for percentage
        });

        // ===== CONFIGURATION: Production Machines =====
        builder.Entity<ProductionMachineEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Identifier).HasMaxLength(120);  // Human-readable name
            entity.Property(x => x.MachineType).HasMaxLength(120);  // e.g., "Laser Cutter", "3D Printer"
            entity.Property(x => x.ControllerType).HasMaxLength(120);  // Controller software/hardware type
            entity.Property(x => x.IpAddress).HasMaxLength(64);  // For network communication
            entity.Property(x => x.ConnectionMode).HasMaxLength(80);  // e.g., "USB", "WiFi", "Ethernet"
            entity.Property(x => x.Notes).HasMaxLength(500);

            entity.HasIndex(x => x.Identifier).IsUnique();  // Machine identifiers must be unique
        });

        // ===== CONFIGURATION: Production Queue Items =====
        builder.Entity<ProductionQueueItemEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ElementType).HasMaxLength(80);  // Type of item being produced
            entity.Property(x => x.ElementKey).HasMaxLength(260);  // Reference to design/element
            entity.Property(x => x.Sku).HasMaxLength(120);  // Stock keeping unit
            entity.Property(x => x.ElementName).HasMaxLength(220);
            entity.Property(x => x.SourceGroup).HasMaxLength(120);  // Which product/order group this came from
            entity.Property(x => x.SourceReference).HasMaxLength(260);  // Reference to source
            entity.Property(x => x.Stage).HasMaxLength(80);  // e.g., "Queued", "In Progress", "Complete"
            entity.Property(x => x.MachineTarget).HasMaxLength(120);  // Which machine to run on
            entity.Property(x => x.Notes).HasMaxLength(500);
            entity.Property(x => x.FrontFaceTier).HasMaxLength(32);  // Design tier for front face
            entity.Property(x => x.FrontFaceColor).HasMaxLength(32);  // Color for front face

            // Indices for efficient querying by production date
            entity.HasIndex(x => x.ProductionDate);
            entity.HasIndex(x => new { x.ProductionDate, x.ElementKey }).IsUnique();  // Uniqueness per day/element
        });

        // ===== CONFIGURATION: Stock Consumables =====
        builder.Entity<StockConsumableEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Name).HasMaxLength(220);
            entity.Property(x => x.Type).HasMaxLength(80);  // e.g., "Paint", "Resin", "Cardboard"
            entity.Property(x => x.Supplier).HasMaxLength(220);
            entity.Property(x => x.Amount).HasPrecision(18, 4);  // Total stock in inventory
            entity.Property(x => x.AmountPerPiece).HasPrecision(18, 4);  // Consumption per item produced

            entity.HasIndex(x => x.Type);  // Find consumables by type
            entity.HasIndex(x => x.Supplier);  // Find consumables by supplier
        });

        // ===== CONFIGURATION: Sellable Products =====
        builder.Entity<SellableProductEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Identifier).HasMaxLength(120);  // Product code/SKU
            entity.Property(x => x.Name).HasMaxLength(220);
            entity.Property(x => x.PermanentSku).HasMaxLength(5);
            entity.Property(x => x.ProductFamily).HasMaxLength(120);
            entity.Property(x => x.ProductionFamily).HasMaxLength(120);
            entity.Property(x => x.ProductTypeCode).HasMaxLength(40);
            entity.Property(x => x.ArtworkKey).HasMaxLength(260);
            entity.Property(x => x.ArtworkName).HasMaxLength(220);
            entity.Property(x => x.LeatherCode).HasMaxLength(8);
            entity.Property(x => x.Currency).HasMaxLength(4);  // e.g., "USD"
            entity.Property(x => x.SquareCategoryName).HasMaxLength(120);  // Category in Square catalog
            entity.Property(x => x.SquareCategoryId).HasMaxLength(192);  // Square API category ID
            entity.Property(x => x.Notes).HasMaxLength(500);
            entity.Property(x => x.SquareCatalogItemId).HasMaxLength(192);  // Synced to Square
            entity.Property(x => x.SquareCatalogVariationId).HasMaxLength(192);  // Synced to Square
            entity.Property(x => x.SquareSku).HasMaxLength(120);
            entity.Property(x => x.LifecycleStatus).HasMaxLength(120);
            entity.Property(x => x.CreatedSource).HasMaxLength(64);
            entity.Property(x => x.WooProductId).HasMaxLength(192);
            entity.Property(x => x.WooVariationId).HasMaxLength(192);
            entity.Property(x => x.BarcodeValue).HasMaxLength(160);
            entity.Property(x => x.SellInPerson).HasDefaultValue(true);
            entity.Property(x => x.SellOnline).HasDefaultValue(true);
            entity.Property(x => x.TrackInventory).HasDefaultValue(true);

            entity.HasIndex(x => x.Identifier).IsUnique();
            entity.HasIndex(x => x.PermanentSku).IsUnique();
            entity.HasIndex(x => x.SquareSku)
                .IsUnique()
                .HasFilter("\"SquareSku\" IS NOT NULL");
            entity.HasIndex(x => new { x.ProductTypeCode, x.ArtworkKey, x.LeatherCode })
                .IsUnique()
                .HasFilter("\"ProductTypeCode\" IS NOT NULL AND \"ArtworkKey\" IS NOT NULL AND \"LeatherCode\" IS NOT NULL");
            entity.HasIndex(x => x.IsActive);  // Filter for active products
            entity.HasIndex(x => x.LifecycleStatus);
            entity.HasIndex(x => x.MergedIntoProductId);
            entity.HasIndex(x => x.ReplacedByProductId);
        });


        // ===== CONFIGURATION: Permanent SKU Sequence =====
        builder.Entity<PermanentSkuSequenceEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.HasCheckConstraint("CK_PermanentSkuSequence_SingleRow", "\"Id\" = 1");
        });

        // ===== CONFIGURATION: SKU Registry =====
        builder.Entity<SkuRegistryEntryEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Sku).HasMaxLength(120);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.ProductName).HasMaxLength(220);
            entity.Property(x => x.VariationName).HasMaxLength(220);
            entity.Property(x => x.SquareCatalogItemId).HasMaxLength(192);
            entity.Property(x => x.SquareCatalogVariationId).HasMaxLength(192);
            entity.Property(x => x.WooProductId).HasMaxLength(192);
            entity.Property(x => x.WooVariationId).HasMaxLength(192);
            entity.Property(x => x.BarcodeValue).HasMaxLength(160);
            entity.Property(x => x.Source).HasMaxLength(64);
            entity.Property(x => x.ConflictSummary).HasMaxLength(2000);
            entity.HasOne(x => x.SellableProduct).WithMany().HasForeignKey(x => x.SellableProductId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => x.Sku).IsUnique();
            entity.HasIndex(x => x.Status);
            entity.HasIndex(x => x.SellableProductId);
        });

        builder.Entity<ProductFamilyTemplateEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.FamilyKey).HasMaxLength(120);
            entity.Property(x => x.FamilyName).HasMaxLength(160);
            entity.Property(x => x.ProductTypeCode).HasMaxLength(40);
            entity.Property(x => x.ProductionFamily).HasMaxLength(120);
            entity.Property(x => x.SquareCategoryName).HasMaxLength(120);
            entity.Property(x => x.SquareCategoryId).HasMaxLength(192);
            entity.Property(x => x.WooCategoryName).HasMaxLength(160);
            entity.Property(x => x.TaxBehavior).HasMaxLength(40);
            entity.Property(x => x.InventoryBehavior).HasMaxLength(40);
            entity.Property(x => x.FulfillmentModel).HasMaxLength(40);
            entity.Property(x => x.Currency).HasMaxLength(4);
            entity.Property(x => x.ShippingLengthInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingWidthInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingHeightInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingWeightOunces).HasPrecision(10, 2);
            entity.Property(x => x.DefaultDescription).HasColumnType("text");
            entity.Property(x => x.DefaultNotes).HasMaxLength(500);
            entity.HasIndex(x => x.FamilyKey).IsUnique();
            entity.HasIndex(x => new { x.FamilyName, x.ProductTypeCode }).IsUnique();
        });

        builder.Entity<ProductFamilyVariantOptionEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.DimensionKey).HasMaxLength(40);
            entity.Property(x => x.OptionCode).HasMaxLength(40);
            entity.Property(x => x.OptionName).HasMaxLength(120);
            entity.HasOne(x => x.ProductFamilyTemplate)
                .WithMany()
                .HasForeignKey(x => x.ProductFamilyTemplateId)
                .OnDelete(DeleteBehavior.Cascade);
            entity.HasIndex(x => x.ProductFamilyTemplateId);
            entity.HasIndex(x => new { x.ProductFamilyTemplateId, x.DimensionKey, x.OptionCode }).IsUnique();
        });

        builder.Entity<ProductArtworkEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ArtworkKey).HasMaxLength(260);
            entity.Property(x => x.ArtworkName).HasMaxLength(220);
            entity.Property(x => x.DesignAssetPath).HasMaxLength(400);
            entity.Property(x => x.ProductImagePath).HasMaxLength(400);
            entity.Property(x => x.Notes).HasMaxLength(500);
            entity.HasIndex(x => x.ArtworkKey).IsUnique();
        });

        builder.Entity<NebulaCreationBatchEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.OperationKey).HasMaxLength(80);
            entity.Property(x => x.WorkflowType).HasMaxLength(40);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.RequestedName).HasMaxLength(220);
            entity.Property(x => x.ArtworkKey).HasMaxLength(260);
            entity.Property(x => x.ArtworkName).HasMaxLength(220);
            entity.Property(x => x.PayloadJson).HasColumnType("text");
            entity.Property(x => x.LastError).HasMaxLength(2000);
            entity.HasIndex(x => x.OperationKey).IsUnique();
            entity.HasIndex(x => new { x.WorkflowType, x.CreatedAt });
            entity.HasIndex(x => x.Status);
        });

        builder.Entity<NebulaCreationBatchVariantEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ProductName).HasMaxLength(220);
            entity.Property(x => x.ArtworkKey).HasMaxLength(260);
            entity.Property(x => x.ArtworkName).HasMaxLength(220);
            entity.Property(x => x.ProductTypeCode).HasMaxLength(40);
            entity.Property(x => x.LeatherCode).HasMaxLength(8);
            entity.Property(x => x.Status).HasMaxLength(40);
            entity.Property(x => x.ReservedSquareSku).HasMaxLength(120);
            entity.Property(x => x.SquareCatalogItemId).HasMaxLength(192);
            entity.Property(x => x.SquareCatalogVariationId).HasMaxLength(192);
            entity.Property(x => x.WooProductId).HasMaxLength(192);
            entity.Property(x => x.WooVariationId).HasMaxLength(192);
            entity.Property(x => x.LastError).HasMaxLength(2000);
            entity.HasOne(x => x.Batch)
                .WithMany()
                .HasForeignKey(x => x.BatchId)
                .OnDelete(DeleteBehavior.Cascade);
            entity.HasOne(x => x.ProductFamilyTemplate)
                .WithMany()
                .HasForeignKey(x => x.ProductFamilyTemplateId)
                .OnDelete(DeleteBehavior.Restrict);
            entity.HasOne(x => x.SellableProduct)
                .WithMany()
                .HasForeignKey(x => x.SellableProductId)
                .OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => x.BatchId);
            entity.HasIndex(x => x.SellableProductId);
            entity.HasIndex(x => x.Status);
            entity.HasIndex(x => new { x.BatchId, x.ArtworkKey, x.ProductTypeCode, x.LeatherCode }).IsUnique();
        });

        // ===== CONFIGURATION: Inventory Ledger =====
        builder.Entity<InventoryTransactionEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.PermanentSku).HasMaxLength(5);
            entity.Property(x => x.LocationCode).HasMaxLength(80);
            entity.Property(x => x.TransactionType).HasMaxLength(40);
            entity.Property(x => x.Notes).HasMaxLength(500);
            entity.HasOne(x => x.SellableProduct).WithMany().HasForeignKey(x => x.SellableProductId).OnDelete(DeleteBehavior.Restrict);
            entity.HasIndex(x => new { x.LocationCode, x.PermanentSku });
            entity.HasIndex(x => x.CreatedAt);
        });

        // ===== CONFIGURATION: Sellable Product Elements =====
        builder.Entity<SellableProductElementEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ElementType).HasMaxLength(80);  // Component type
            entity.Property(x => x.ElementKey).HasMaxLength(260);  // Reference to design
            entity.Property(x => x.ElementName).HasMaxLength(220);
            entity.Property(x => x.CategoryName).HasMaxLength(120);  // For Square integration
            entity.Property(x => x.DesignFileName).HasMaxLength(260);

            // Relationship to parent product - cascade delete if product is deleted
            entity.HasOne(x => x.SellableProduct)
                .WithMany()
                .HasForeignKey(x => x.SellableProductId)
                .OnDelete(DeleteBehavior.Cascade);

            entity.HasIndex(x => x.SellableProductId);
            entity.HasIndex(x => new { x.SellableProductId, x.ElementKey }).IsUnique();  // One element per product
        });

        // ===== CONFIGURATION: Finished Goods Templates =====
        builder.Entity<FinishedGoodsTemplateEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.Id).HasMaxLength(64);
            entity.Property(x => x.TemplateName).HasMaxLength(220);
            entity.Property(x => x.TemplateCategory).HasMaxLength(120);
            entity.Property(x => x.SkuCategoryLetter).HasMaxLength(32);  // Letter prefix for SKU generation
            entity.Property(x => x.PrimaryCategoryKey).HasMaxLength(120);
            entity.Property(x => x.ShippingLengthInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingWidthInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingHeightInches).HasPrecision(10, 2);
            entity.Property(x => x.ShippingWeightOunces).HasPrecision(10, 2);
        });

        // ===== CONFIGURATION: Pinned Elements =====
        builder.Entity<PinnedElementEntity>(entity =>
        {
            entity.HasKey(x => x.ElementKey);  // Element key is the identifier
            entity.Property(x => x.ElementKey).HasMaxLength(260);
            entity.Property(x => x.DisplayName).HasMaxLength(220);
            entity.Property(x => x.SourceGroup).HasMaxLength(120);

            entity.HasIndex(x => x.CreatedAt);  // Find recent pinned items
        });

        // ===== CONFIGURATION: Finished Goods Manufacturing Files =====
        builder.Entity<FinishedGoodsManufacturingFileEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.ElementKey).HasMaxLength(260);
            entity.Property(x => x.SourceGroupKey).HasMaxLength(120);
            entity.Property(x => x.ElementLabel).HasMaxLength(220);
            entity.Property(x => x.StoredFileName).HasMaxLength(260);
            entity.Property(x => x.RelativeFilePath).HasMaxLength(400);

            entity.HasIndex(x => x.ElementKey).IsUnique();  // One file per element
            entity.HasIndex(x => x.SourceGroupKey);  // Find files by source group
        });

        // ===== CONFIGURATION: Square Team Members =====
        builder.Entity<SquareTeamMemberEntity>(entity =>
        {
            entity.HasKey(x => x.SquareTeamMemberId);  // Square API ID
            entity.Property(x => x.SquareTeamMemberId).HasMaxLength(192);
            entity.Property(x => x.GivenName).HasMaxLength(100);
            entity.Property(x => x.FamilyName).HasMaxLength(100);
            entity.Property(x => x.DisplayName).HasMaxLength(220);
            entity.Property(x => x.EmailAddress).HasMaxLength(256);
            entity.Property(x => x.PhoneNumber).HasMaxLength(40);
            entity.Property(x => x.Status).HasMaxLength(32);  // e.g., "ACTIVE", "INACTIVE"
            entity.Property(x => x.LocationAssignmentType).HasMaxLength(128);  // e.g., "ALL_LOCATIONS"
            entity.Property(x => x.CommissionPercentage).HasPrecision(5, 2);
        });

        // ===== CONFIGURATION: Square Sales (Orders) =====
        builder.Entity<SquareSaleEntity>(entity =>
        {
            entity.HasKey(x => x.SquarePaymentId);  // Square payment ID is unique identifier
            entity.Property(x => x.SquarePaymentId).HasMaxLength(192);
            entity.Property(x => x.LocationId).HasMaxLength(50);  // Which location the sale occurred
            entity.Property(x => x.TeamMemberId).HasMaxLength(192);  // Which team member made the sale
            entity.Property(x => x.TeamMemberName).HasMaxLength(220);
            entity.Property(x => x.OrderId).HasMaxLength(192);  // Associated Square order ID
            entity.Property(x => x.CustomerId).HasMaxLength(192);  // Customer attached to the Square order
            entity.Property(x => x.Status).HasMaxLength(50);  // e.g., "COMPLETED", "CANCELED"
            entity.Property(x => x.Currency).HasMaxLength(4);  // e.g., "USD"
            entity.Property(x => x.ReceiptNumber).HasMaxLength(16);

            entity.HasIndex(x => x.TeamMemberId);  // Find sales by team member for commissions
            entity.HasIndex(x => x.LocationId);   // Find sales by location
            entity.HasIndex(x => x.CreatedAt);    // Find sales by date
        });

        // ===== CONFIGURATION: Square Sale Line Items =====
        builder.Entity<SquareSaleLineItemEntity>(entity =>
        {
            entity.HasKey(x => new { x.SquareOrderId, x.LineItemUid });  // Composite key - order + line item
            entity.Property(x => x.SquareOrderId).HasMaxLength(192);
            entity.Property(x => x.LineItemUid).HasMaxLength(192);
            entity.Property(x => x.LineItemName).HasMaxLength(220);
            entity.Property(x => x.VariationName).HasMaxLength(220);  // e.g., "Large", "Red", "Premium"
            entity.Property(x => x.CatalogObjectId).HasMaxLength(192);  // Reference to Square catalog
            entity.Property(x => x.ReportingCategoryId).HasMaxLength(192);  // For sales reporting
            entity.Property(x => x.ReportingCategoryName).HasMaxLength(220);
            entity.Property(x => x.Note).HasMaxLength(2000);
            entity.Property(x => x.ModifiersJson).HasColumnType("text");
            entity.Property(x => x.Quantity).HasPrecision(18, 4);  // Quantity ordered

            entity.HasIndex(x => x.ReportingCategoryId);  // Find items by category for reporting
            entity.HasIndex(x => x.SaleCreatedAt);        // Find items by sale date
            entity.HasIndex(x => x.SquareOrderId);        // Find all items in an order
        });

        builder.Entity<FulfillmentOrderLineEntity>(entity =>
        {
            entity.HasKey(x => x.Id);
            entity.Property(x => x.SourceChannel).HasMaxLength(40);
            entity.Property(x => x.SourceOrderId).HasMaxLength(192);
            entity.Property(x => x.SourceLineItemId).HasMaxLength(192);
            entity.Property(x => x.SourceOrderNumber).HasMaxLength(120);
            entity.Property(x => x.SourceCustomerId).HasMaxLength(192);
            entity.Property(x => x.CustomerName).HasMaxLength(220);
            entity.Property(x => x.CustomerEmail).HasMaxLength(256);
            entity.Property(x => x.CustomerPhone).HasMaxLength(40);
            entity.Property(x => x.ShipToName).HasMaxLength(220);
            entity.Property(x => x.ShipAddress1).HasMaxLength(220);
            entity.Property(x => x.ShipAddress2).HasMaxLength(220);
            entity.Property(x => x.ShipCity).HasMaxLength(120);
            entity.Property(x => x.ShipState).HasMaxLength(80);
            entity.Property(x => x.ShipPostalCode).HasMaxLength(32);
            entity.Property(x => x.ShipCountry).HasMaxLength(80);
            entity.Property(x => x.ProductName).HasMaxLength(220);
            entity.Property(x => x.VariationName).HasMaxLength(220);
            entity.Property(x => x.Sku).HasMaxLength(120);
            entity.Property(x => x.Quantity).HasPrecision(18, 4);
            entity.Property(x => x.Currency).HasMaxLength(4);
            entity.Property(x => x.OrderNotes).HasMaxLength(2000);
            entity.Property(x => x.SelectionJson).HasColumnType("text");
            entity.Property(x => x.ProductionStatus).HasMaxLength(40);
            entity.Property(x => x.FulfillmentStatus).HasMaxLength(40);
            entity.Property(x => x.Carrier).HasMaxLength(80);
            entity.Property(x => x.TrackingNumber).HasMaxLength(192);
            entity.HasIndex(x => new { x.SourceChannel, x.SourceOrderId, x.SourceLineItemId }).IsUnique();
            entity.HasIndex(x => x.FulfillmentStatus);
            entity.HasIndex(x => x.ProductionStatus);
            entity.HasIndex(x => x.OrderCreatedAt);
        });

        builder.Entity<ShowOrderFulfillmentEntity>(entity =>
        {
            entity.HasKey(x => new { x.SquareOrderId, x.LineItemUid });
            entity.Property(x => x.SquareOrderId).HasMaxLength(192);
            entity.Property(x => x.LineItemUid).HasMaxLength(192);
            entity.Property(x => x.Status).HasMaxLength(40);
        });
    }


    // Pass 9.8.2: PostgreSQL text columns cannot contain NUL (0x00).
    // Legacy email archives can legitimately contain these bytes after MIME decoding,
    // so sanitize all tracked string values at the persistence boundary. This keeps
    // the original useful text while preventing one malformed historical message
    // from aborting the archive import.
    private void SanitizePostgresTextValues()
    {
        foreach (var entry in ChangeTracker.Entries())
        {
            if (entry.State != Microsoft.EntityFrameworkCore.EntityState.Added &&
                entry.State != Microsoft.EntityFrameworkCore.EntityState.Modified)
                continue;

            foreach (var property in entry.Properties)
            {
                if (property.Metadata.ClrType != typeof(string))
                    continue;

                if (property.CurrentValue is string value && value.IndexOf('\0') >= 0)
                    property.CurrentValue = value.Replace("\0", string.Empty);
            }
        }
    }

    public override int SaveChanges(bool acceptAllChangesOnSuccess)
    {
        SanitizePostgresTextValues();
        return base.SaveChanges(acceptAllChangesOnSuccess);
    }

    public override System.Threading.Tasks.Task<int> SaveChangesAsync(
        bool acceptAllChangesOnSuccess,
        System.Threading.CancellationToken cancellationToken = default)
    {
        SanitizePostgresTextValues();
        return base.SaveChangesAsync(acceptAllChangesOnSuccess, cancellationToken);
    }
}
