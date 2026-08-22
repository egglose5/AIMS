// Database entity definitions for the Control-App application
// These classes represent the tables and columns in PostgreSQL
// They are organized by functional domain (integration settings, employees, production, products, Square sync)

using System.ComponentModel.DataAnnotations;

namespace MustaineAI.Data;

// ===== INTEGRATION CONFIGURATION ENTITIES =====

/// <summary>
/// Stores Square POS API credentials and configuration.
/// Single record per environment (Sandbox or Production).
/// </summary>
public sealed class SquareConnectionSettingsEntity
{
    /// <summary>Primary key - always "Square" constant.</summary>
    [Key]
    [MaxLength(64)]
    public string Id { get; set; } = SquareConnectionSettingsEntity.DefaultId;

    /// <summary>Square environment - "Sandbox" for testing or "Production" for live.</summary>
    [MaxLength(32)]
    public string Environment { get; set; } = "Sandbox";

    /// <summary>OAuth 2.0 application ID from Square Developer Dashboard.</summary>
    [MaxLength(192)]
    public string? ApplicationId { get; set; }

    /// <summary>OAuth 2.0 access token for API requests (obtained via OAuth flow).</summary>
    public string? AccessToken { get; set; }

    /// <summary>Square API version for this integration (e.g., "2024-06-14"). Helps maintain compatibility.</summary>
    [MaxLength(32)]
    public string? ApiVersion { get; set; }

    /// <summary>Timestamp of last update (for auditing credential changes).</summary>
    public DateTimeOffset UpdatedAt { get; set; }

    /// <summary>Constant key for the single configuration record.</summary>
    public const string DefaultId = "Square";
}

public sealed class WooCommerceConnectionSettingsEntity
{
    [Key]
    [MaxLength(64)]
    public string Id { get; set; } = WooCommerceConnectionSettingsEntity.DefaultId;

    public string? ApiKey { get; set; }

    [MaxLength(500)]
    public string? StoreUrl { get; set; }

    [MaxLength(220)]
    public string? ConsumerKey { get; set; }

    public string? ConsumerSecret { get; set; }

    public DateTimeOffset UpdatedAt { get; set; }

    public const string DefaultId = "WooCommerce";
}

public sealed class EmployeeRosterEntryEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(220)]
    public string Name { get; set; } = string.Empty;

    [MaxLength(120)]
    public string? Role { get; set; }

    [MaxLength(120)]
    public string? Department { get; set; }

    [MaxLength(32)]
    public string Status { get; set; } = "ACTIVE";

    [MaxLength(256)]
    public string? EmailAddress { get; set; }

    [MaxLength(40)]
    public string? PhoneNumber { get; set; }

    public decimal? CommissionPercentage { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ProductionMachineEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(120)]
    public string Identifier { get; set; } = string.Empty;

    [MaxLength(120)]
    public string MachineType { get; set; } = string.Empty;

    [MaxLength(120)]
    public string ControllerType { get; set; } = string.Empty;

    [MaxLength(64)]
    public string IpAddress { get; set; } = string.Empty;

    public int? TcpPort { get; set; }

    [MaxLength(80)]
    public string ConnectionMode { get; set; } = "TCP";

    [MaxLength(500)]
    public string? Notes { get; set; }

    public bool IsEnabled { get; set; } = true;

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ProductionQueueItemEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    public DateOnly ProductionDate { get; set; } = DateOnly.FromDateTime(DateTime.UtcNow);

    [MaxLength(80)]
    public string ElementType { get; set; } = string.Empty;

    [MaxLength(260)]
    public string ElementKey { get; set; } = string.Empty;

    [MaxLength(120)]
    public string? Sku { get; set; }

    [MaxLength(220)]
    public string ElementName { get; set; } = string.Empty;

    [MaxLength(120)]
    public string SourceGroup { get; set; } = string.Empty;

    [MaxLength(260)]
    public string? SourceReference { get; set; }

    [MaxLength(80)]
    public string Stage { get; set; } = "Queued";

    [MaxLength(120)]
    public string? MachineTarget { get; set; }

    [MaxLength(500)]
    public string? Notes { get; set; }

    [MaxLength(32)]
    public string? FrontFaceTier { get; set; }

    [MaxLength(32)]
    public string? FrontFaceColor { get; set; }

    public int Quantity { get; set; } = 1;

    public DateTimeOffset AddedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class StockConsumableEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(220)]
    public string Name { get; set; } = string.Empty;

    [MaxLength(80)]
    public string Type { get; set; } = "Stock";

    [MaxLength(220)]
    public string Supplier { get; set; } = string.Empty;

    public long CostCents { get; set; }

    public long? BulkingLaborCostCents { get; set; }

    public decimal Amount { get; set; }

    public decimal AmountPerPiece { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class SellableProductEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(120)]
    public string Identifier { get; set; } = string.Empty;

    [MaxLength(220)]
    public string Name { get; set; } = string.Empty;

    // Permanent Ancient Innovations SKU. Once assigned, never change or reuse.
    [MaxLength(5)]
    public string? PermanentSku { get; set; }

    [MaxLength(120)]
    public string? ProductFamily { get; set; }

    [MaxLength(120)]
    public string? ProductionFamily { get; set; }

    // Stable physical-variation identity. These describe what the finished unit IS;
    // display names/categories may change without changing the SKU.
    [MaxLength(40)]
    public string? ProductTypeCode { get; set; }

    [MaxLength(260)]
    public string? ArtworkKey { get; set; }

    [MaxLength(220)]
    public string? ArtworkName { get; set; }

    [MaxLength(8)]
    public string? LeatherCode { get; set; }

    public long PriceCents { get; set; }

    [MaxLength(4)]
    public string Currency { get; set; } = "USD";

    [MaxLength(120)]
    public string? SquareCategoryName { get; set; }

    [MaxLength(192)]
    public string? SquareCategoryId { get; set; }

    [MaxLength(500)]
    public string? Notes { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogItemId { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogVariationId { get; set; }

    [MaxLength(120)]
    public string? SquareSku { get; set; }

    [MaxLength(120)]
    public string LifecycleStatus { get; set; } = "ACTIVE";

    [MaxLength(64)]
    public string CreatedSource { get; set; } = "CONTROL_APP";

    [MaxLength(192)]
    public string? WooProductId { get; set; }

    [MaxLength(192)]
    public string? WooVariationId { get; set; }

    [MaxLength(160)]
    public string? BarcodeValue { get; set; }

    public bool SellInPerson { get; set; } = true;

    public bool SellOnline { get; set; } = true;

    public bool TrackInventory { get; set; } = true;

    public Guid? MergedIntoProductId { get; set; }

    public Guid? ReplacedByProductId { get; set; }

    public DateTimeOffset? SquareSyncedAt { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class SkuRegistryEntryEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(120)]
    public string Sku { get; set; } = string.Empty;

    [MaxLength(40)]
    public string Status { get; set; } = "RESERVED";

    public Guid? SellableProductId { get; set; }

    public SellableProductEntity? SellableProduct { get; set; }

    [MaxLength(220)]
    public string? ProductName { get; set; }

    [MaxLength(220)]
    public string? VariationName { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogItemId { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogVariationId { get; set; }

    [MaxLength(192)]
    public string? WooProductId { get; set; }

    [MaxLength(192)]
    public string? WooVariationId { get; set; }

    [MaxLength(160)]
    public string? BarcodeValue { get; set; }

    [MaxLength(64)]
    public string Source { get; set; } = "CONTROL_APP";

    [MaxLength(2000)]
    public string? ConflictSummary { get; set; }

    public DateTimeOffset? ReservedAt { get; set; }

    public DateTimeOffset? AssignedAt { get; set; }

    public DateTimeOffset? RetiredAt { get; set; }

    public DateTimeOffset? LastReconciledAt { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ProductFamilyTemplateEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(120)]
    public string FamilyKey { get; set; } = string.Empty;

    [MaxLength(160)]
    public string FamilyName { get; set; } = string.Empty;

    [MaxLength(40)]
    public string? ProductTypeCode { get; set; }

    [MaxLength(120)]
    public string? ProductionFamily { get; set; }

    [MaxLength(120)]
    public string? SquareCategoryName { get; set; }

    [MaxLength(192)]
    public string? SquareCategoryId { get; set; }

    [MaxLength(160)]
    public string? WooCategoryName { get; set; }

    [MaxLength(40)]
    public string TaxBehavior { get; set; } = "STANDARD";

    [MaxLength(40)]
    public string InventoryBehavior { get; set; } = "TRACKED";

    [MaxLength(40)]
    public string FulfillmentModel { get; set; } = "MANUFACTURED";

    public long DefaultPriceCents { get; set; }

    [MaxLength(4)]
    public string Currency { get; set; } = "USD";

    public decimal? ShippingLengthInches { get; set; }

    public decimal? ShippingWidthInches { get; set; }

    public decimal? ShippingHeightInches { get; set; }

    public decimal? ShippingWeightOunces { get; set; }

    public bool SellInPerson { get; set; } = true;

    public bool SellOnline { get; set; } = true;

    public bool TrackInventory { get; set; } = true;

    public string? DefaultDescription { get; set; }

    public string? DefaultNotes { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ProductFamilyVariantOptionEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    public Guid ProductFamilyTemplateId { get; set; }

    public ProductFamilyTemplateEntity? ProductFamilyTemplate { get; set; }

    [MaxLength(40)]
    public string DimensionKey { get; set; } = "LEATHER";

    [MaxLength(40)]
    public string OptionCode { get; set; } = string.Empty;

    [MaxLength(120)]
    public string OptionName { get; set; } = string.Empty;

    public bool IsDefaultSelected { get; set; } = true;

    public bool IsEnabled { get; set; } = true;

    public int SortOrder { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ProductArtworkEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(260)]
    public string ArtworkKey { get; set; } = string.Empty;

    [MaxLength(220)]
    public string ArtworkName { get; set; } = string.Empty;

    [MaxLength(400)]
    public string? DesignAssetPath { get; set; }

    [MaxLength(400)]
    public string? ProductImagePath { get; set; }

    [MaxLength(500)]
    public string? Notes { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class NebulaCreationBatchEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(80)]
    public string OperationKey { get; set; } = string.Empty;

    [MaxLength(40)]
    public string WorkflowType { get; set; } = string.Empty;

    [MaxLength(40)]
    public string Status { get; set; } = "DRAFT";

    [MaxLength(220)]
    public string RequestedName { get; set; } = string.Empty;

    [MaxLength(260)]
    public string? ArtworkKey { get; set; }

    [MaxLength(220)]
    public string? ArtworkName { get; set; }

    public string? PayloadJson { get; set; }

    [MaxLength(2000)]
    public string? LastError { get; set; }

    public DateTimeOffset? CompletedAt { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class NebulaCreationBatchVariantEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    public Guid BatchId { get; set; }

    public NebulaCreationBatchEntity? Batch { get; set; }

    public Guid? ProductFamilyTemplateId { get; set; }

    public ProductFamilyTemplateEntity? ProductFamilyTemplate { get; set; }

    public Guid? SellableProductId { get; set; }

    public SellableProductEntity? SellableProduct { get; set; }

    [MaxLength(220)]
    public string ProductName { get; set; } = string.Empty;

    [MaxLength(260)]
    public string? ArtworkKey { get; set; }

    [MaxLength(220)]
    public string? ArtworkName { get; set; }

    [MaxLength(40)]
    public string? ProductTypeCode { get; set; }

    [MaxLength(8)]
    public string? LeatherCode { get; set; }

    [MaxLength(40)]
    public string Status { get; set; } = "PENDING_DRAFT";

    [MaxLength(120)]
    public string? ReservedSquareSku { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogItemId { get; set; }

    [MaxLength(192)]
    public string? SquareCatalogVariationId { get; set; }

    [MaxLength(192)]
    public string? WooProductId { get; set; }

    [MaxLength(192)]
    public string? WooVariationId { get; set; }

    [MaxLength(2000)]
    public string? LastError { get; set; }

    public int AttemptCount { get; set; }

    public bool RetryAllowed { get; set; } = true;

    public DateTimeOffset? LastAttemptedAt { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class SellableProductElementEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    public Guid SellableProductId { get; set; }

    public SellableProductEntity? SellableProduct { get; set; }

    [MaxLength(80)]
    public string ElementType { get; set; } = string.Empty;

    [MaxLength(260)]
    public string ElementKey { get; set; } = string.Empty;

    [MaxLength(220)]
    public string ElementName { get; set; } = string.Empty;

    [MaxLength(120)]
    public string? CategoryName { get; set; }

    [MaxLength(260)]
    public string? DesignFileName { get; set; }

    public long CogsCents { get; set; }

    public bool HasImage { get; set; }

    public int SortOrder { get; set; }

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class FinishedGoodsTemplateEntity
{
    [Key]
    [MaxLength(64)]
    public string Id { get; set; } = FinishedGoodsTemplateEntity.DefaultId;

    [MaxLength(220)]
    public string TemplateName { get; set; } = "Finished Good";

    [MaxLength(120)]
    public string TemplateCategory { get; set; } = "Finished Goods";

    [MaxLength(32)]
    public string SkuCategoryLetter { get; set; } = "F";

    public int SkuNumberDigits { get; set; } = 3;

    public string? LinkedCategoryKeysCsv { get; set; }

    public string? CombinationCategoryKeysCsv { get; set; }

    [MaxLength(120)]
    public string? PrimaryCategoryKey { get; set; }

    public string? FrontFaceTierOptionsCsv { get; set; }

    public string? FrontFaceColorOptionsCsv { get; set; }

    public decimal? ShippingLengthInches { get; set; }

    public decimal? ShippingWidthInches { get; set; }

    public decimal? ShippingHeightInches { get; set; }

    public decimal? ShippingWeightOunces { get; set; }

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;

    public const string DefaultId = "Active";
}

public sealed class PinnedElementEntity
{
    [Key]
    [MaxLength(260)]
    public string ElementKey { get; set; } = string.Empty;

    [MaxLength(220)]
    public string DisplayName { get; set; } = string.Empty;

    [MaxLength(120)]
    public string SourceGroup { get; set; } = string.Empty;

    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class FinishedGoodsManufacturingFileEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(260)]
    public string ElementKey { get; set; } = string.Empty;

    [MaxLength(120)]
    public string SourceGroupKey { get; set; } = string.Empty;

    [MaxLength(220)]
    public string ElementLabel { get; set; } = string.Empty;

    [MaxLength(260)]
    public string StoredFileName { get; set; } = string.Empty;

    [MaxLength(400)]
    public string RelativeFilePath { get; set; } = string.Empty;

    public string? InputDefinition { get; set; }

    public string? OutputDefinition { get; set; }

    public DateTimeOffset UploadedAt { get; set; } = DateTimeOffset.UtcNow;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class SquareTeamMemberEntity
{
    [Key]
    [MaxLength(192)]
    public string SquareTeamMemberId { get; set; } = string.Empty;

    [MaxLength(100)]
    public string? GivenName { get; set; }

    [MaxLength(100)]
    public string? FamilyName { get; set; }

    [MaxLength(220)]
    public string? DisplayName { get; set; }

    [MaxLength(256)]
    public string? EmailAddress { get; set; }

    [MaxLength(40)]
    public string? PhoneNumber { get; set; }

    [MaxLength(32)]
    public string? Status { get; set; }

    public bool IsOwner { get; set; }

    [MaxLength(128)]
    public string? LocationAssignmentType { get; set; }

    public decimal? CommissionPercentage { get; set; }

    public string? AssignedLocationIdsJson { get; set; }

    public DateTimeOffset? SquareUpdatedAt { get; set; }

    public DateTimeOffset SyncedAt { get; set; }
}

public sealed class SquareSaleEntity
{
    [Key]
    [MaxLength(192)]
    public string SquarePaymentId { get; set; } = string.Empty;

    [MaxLength(50)]
    public string? LocationId { get; set; }

    [MaxLength(192)]
    public string? TeamMemberId { get; set; }

    [MaxLength(220)]
    public string? TeamMemberName { get; set; }

    [MaxLength(192)]
    public string? OrderId { get; set; }

    [MaxLength(192)]
    public string? CustomerId { get; set; }

    [MaxLength(50)]
    public string? Status { get; set; }

    public long AmountCents { get; set; }

    public long TipCents { get; set; }

    public long OrderGrossBeforeTaxCents { get; set; }

    public long OrderTaxCents { get; set; }

    public long OrderDiscountCents { get; set; }

    public long OrderServiceChargeCents { get; set; }

    public long OrderAdjustmentCents { get; set; }

    public long OrderTotalCents { get; set; }

    [MaxLength(4)]
    public string? Currency { get; set; }

    [MaxLength(16)]
    public string? ReceiptNumber { get; set; }

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset? UpdatedAt { get; set; }

    public DateTimeOffset SyncedAt { get; set; }
}

public sealed class SquareSaleLineItemEntity
{
    public string SquareOrderId { get; set; } = string.Empty;

    public string LineItemUid { get; set; } = string.Empty;

    [MaxLength(220)]
    public string? LineItemName { get; set; }

    [MaxLength(220)]
    public string? VariationName { get; set; }

    [MaxLength(192)]
    public string? CatalogObjectId { get; set; }

    [MaxLength(192)]
    public string? ReportingCategoryId { get; set; }

    [MaxLength(220)]
    public string? ReportingCategoryName { get; set; }

    [MaxLength(2000)]
    public string? Note { get; set; }

    public string? ModifiersJson { get; set; }

    public decimal Quantity { get; set; }

    public long GrossAmountCents { get; set; }

    public int SortOrder { get; set; }

    public DateTimeOffset SaleCreatedAt { get; set; }

    public DateTimeOffset SyncedAt { get; set; }
}


/// <summary>
/// Shared fulfillment record used by every order source (Square Show Orders, WooCommerce, and future channels).
/// This is intentionally source-agnostic so shipping/production can operate from one queue.
/// </summary>
public sealed class FulfillmentOrderLineEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();

    [MaxLength(40)]
    public string SourceChannel { get; set; } = string.Empty;

    [MaxLength(192)]
    public string SourceOrderId { get; set; } = string.Empty;

    [MaxLength(192)]
    public string SourceLineItemId { get; set; } = string.Empty;

    [MaxLength(120)]
    public string? SourceOrderNumber { get; set; }

    [MaxLength(192)]
    public string? SourceCustomerId { get; set; }

    [MaxLength(220)]
    public string? CustomerName { get; set; }

    [MaxLength(256)]
    public string? CustomerEmail { get; set; }

    [MaxLength(40)]
    public string? CustomerPhone { get; set; }

    [MaxLength(220)]
    public string? ShipToName { get; set; }

    [MaxLength(220)]
    public string? ShipAddress1 { get; set; }

    [MaxLength(220)]
    public string? ShipAddress2 { get; set; }

    [MaxLength(120)]
    public string? ShipCity { get; set; }

    [MaxLength(80)]
    public string? ShipState { get; set; }

    [MaxLength(32)]
    public string? ShipPostalCode { get; set; }

    [MaxLength(80)]
    public string? ShipCountry { get; set; }

    [MaxLength(220)]
    public string ProductName { get; set; } = string.Empty;

    [MaxLength(220)]
    public string? VariationName { get; set; }

    [MaxLength(120)]
    public string? Sku { get; set; }

    public decimal Quantity { get; set; } = 1;
    public long UnitPriceCents { get; set; }

    [MaxLength(4)]
    public string Currency { get; set; } = "USD";

    [MaxLength(2000)]
    public string? OrderNotes { get; set; }

    public string? SelectionJson { get; set; }

    [MaxLength(40)]
    public string ProductionStatus { get; set; } = "UNASSESSED";

    [MaxLength(40)]
    public string FulfillmentStatus { get; set; } = "OPEN";

    [MaxLength(80)]
    public string? Carrier { get; set; }

    [MaxLength(192)]
    public string? TrackingNumber { get; set; }

    public DateTimeOffset? ShippedAt { get; set; }
    public DateTimeOffset OrderCreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class ShowOrderFulfillmentEntity
{
    public string SquareOrderId { get; set; } = string.Empty;
    public string LineItemUid { get; set; } = string.Empty;

    [MaxLength(40)]
    public string Status { get; set; } = "NEEDS_PRODUCTION";

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class PermanentSkuSequenceEntity
{
    [Key]
    public int Id { get; set; } = 1;
    public int LastIssuedNumber { get; set; }
}

public sealed class InventoryTransactionEntity
{
    [Key]
    public Guid Id { get; set; } = Guid.NewGuid();
    public Guid SellableProductId { get; set; }
    public SellableProductEntity? SellableProduct { get; set; }
    [MaxLength(5)]
    public string PermanentSku { get; set; } = string.Empty;
    [MaxLength(80)]
    public string LocationCode { get; set; } = "FINISHED_SHELF";
    [MaxLength(40)]
    public string TransactionType { get; set; } = string.Empty;
    public int QuantityDelta { get; set; }
    [MaxLength(500)]
    public string? Notes { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
}


public sealed class ArtworkSubcategoryEntity
{
    public int Id { get; set; }
    public string ArtworkKey { get; set; } = string.Empty;
    public string ArtworkName { get; set; } = string.Empty;
    public string Subcategory { get; set; } = string.Empty;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}


public sealed class InventoryHoldEntity
{
    [Key]
    public int Id { get; set; }

    [MaxLength(500)]
    public string HoldKey { get; set; } = string.Empty;

    [MaxLength(300)]
    public string ItemName { get; set; } = string.Empty;

    [MaxLength(160)]
    public string OriginalCategory { get; set; } = string.Empty;

    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}
