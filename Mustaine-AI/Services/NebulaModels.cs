using System.ComponentModel.DataAnnotations;

namespace MustaineAI.Services;

public sealed record NebulaWorkspace(
    ProductRegistryDashboard Dashboard,
    IReadOnlyList<NebulaFamilyTemplateSummary> FamilyTemplates,
    IReadOnlyList<NebulaArtworkSummary> ArtworkOptions,
    IReadOnlyList<NebulaBatchSummary> RecentBatches);

public sealed record NebulaFamilyTemplateSummary(
    Guid? TemplateId,
    string FamilyKey,
    string FamilyName,
    string? ProductTypeCode,
    string? ProductionFamily,
    string? SquareCategoryName,
    decimal DefaultPrice,
    IReadOnlyList<NebulaVariantOption> VariantOptions,
    bool IsStoredTemplate);

public sealed record NebulaVariantOption(
    string DimensionKey,
    string OptionCode,
    string OptionName,
    bool IsDefaultSelected,
    int SortOrder);

public sealed record NebulaArtworkSummary(
    Guid? ArtworkId,
    string ArtworkKey,
    string ArtworkName,
    string? DesignAssetPath,
    string? ProductImagePath);

public sealed record NebulaBatchSummary(
    Guid BatchId,
    string OperationKey,
    string WorkflowType,
    string Status,
    string RequestedName,
    string? ArtworkName,
    DateTimeOffset CreatedAt,
    IReadOnlyList<NebulaBatchVariantSummary> Variants);

public sealed record NebulaBatchVariantSummary(
    Guid VariantId,
    Guid? SellableProductId,
    string ProductName,
    string? ProductTypeCode,
    string? LeatherCode,
    string Status,
    string? ReservedSquareSku,
    string? SquareCatalogItemId,
    string? SquareCatalogVariationId,
    string? LastError,
    bool RetryAllowed);

public sealed class SaveFamilyTemplateInput
{
    public Guid? TemplateId { get; set; }

    [Required]
    public string FamilyName { get; set; } = string.Empty;

    public string? ProductTypeCode { get; set; }

    public string? ProductionFamily { get; set; }

    public string? SquareCategoryName { get; set; }

    public string? WooCategoryName { get; set; }

    public decimal? DefaultPrice { get; set; }

    public string VariantOptionsText { get; set; } = string.Empty;

    public string TaxBehavior { get; set; } = "STANDARD";

    public string InventoryBehavior { get; set; } = "TRACKED";

    public string FulfillmentModel { get; set; } = "MANUFACTURED";

    public bool SellInPerson { get; set; } = true;

    public bool SellOnline { get; set; } = true;

    public bool TrackInventory { get; set; } = true;

    public decimal? ShippingLengthInches { get; set; }

    public decimal? ShippingWidthInches { get; set; }

    public decimal? ShippingHeightInches { get; set; }

    public decimal? ShippingWeightOunces { get; set; }

    public string? DefaultDescription { get; set; }
}

public sealed class NebulaArtworkWorkflowInput
{
    [Required]
    public string ArtworkName { get; set; } = string.Empty;

    public List<string> FamilyKeys { get; set; } = [];

    public string? Notes { get; set; }

    public string? DesignAssetPath { get; set; }

    public string? ProductImagePath { get; set; }

    public bool SyncToSquare { get; set; } = true;
}

public sealed class NebulaProductWorkflowInput
{
    [Required]
    public string ProductName { get; set; } = string.Empty;

    [Required]
    public string FamilyKey { get; set; } = string.Empty;

    public string? ArtworkName { get; set; }

    public string? Notes { get; set; }

    public string? DesignAssetPath { get; set; }

    public string? ProductImagePath { get; set; }

    public decimal? PriceOverride { get; set; }

    public List<string> SelectedVariantCodes { get; set; } = [];

    public bool SyncToSquare { get; set; } = true;
}

public sealed class NebulaDuplicateWorkflowInput
{
    [Required]
    public Guid SourceProductId { get; set; }

    [Required]
    public string NewProductName { get; set; } = string.Empty;

    public string? ArtworkName { get; set; }

    public string? Notes { get; set; }

    public bool SyncToSquare { get; set; } = false;
}

public sealed record SaveFamilyTemplateResult(Guid TemplateId, string FamilyKey, string FamilyName);

public sealed record NebulaBatchResult(
    Guid BatchId,
    string OperationKey,
    string Status,
    string Message,
    IReadOnlyList<NebulaBatchVariantSummary> Variants);
