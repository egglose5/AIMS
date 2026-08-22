using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface IProductRegistryService
{
    Task<ProductRegistryDashboard> GetDashboardAsync(CancellationToken cancellationToken = default);
    Task<CreateDraftProductResult> CreateDraftProductAsync(NewProductIntakeInput input, CancellationToken cancellationToken = default);
    Task<SquareSkuReconciliationReport> ReconcileSquareCatalogAsync(CancellationToken cancellationToken = default);
}

public sealed class ProductRegistryService(
    ApplicationDbContext db,
    ISellableSkuService sellableSkuService,
    ISquareApiService squareApiService) : IProductRegistryService
{
    public async Task<ProductRegistryDashboard> GetDashboardAsync(CancellationToken cancellationToken = default)
    {
        var totalProducts = await db.SellableProducts.CountAsync(cancellationToken);
        var draftProducts = await db.SellableProducts.CountAsync(x => x.LifecycleStatus == "DRAFT", cancellationToken);
        var activeProducts = await db.SellableProducts.CountAsync(x => x.LifecycleStatus == "ACTIVE", cancellationToken);
        var squareMappedProducts = await db.SellableProducts.CountAsync(
            x => x.SquareCatalogItemId != null || x.SquareCatalogVariationId != null,
            cancellationToken);
        var reservedSkuCount = await db.SkuRegistryEntries.CountAsync(x => x.Status == "RESERVED", cancellationToken);

        var products = await db.SellableProducts
            .AsNoTracking()
            .OrderByDescending(x => x.CreatedAt)
            .Take(200)
            .ToListAsync(cancellationToken);

        var familyOptions = products
            .Select(x => x.ProductFamily)
            .Concat(products.Select(x => x.SquareCategoryName))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
            .Cast<string>()
            .ToList();

        return new ProductRegistryDashboard(
            totalProducts,
            draftProducts,
            activeProducts,
            squareMappedProducts,
            reservedSkuCount,
            products.Select(ProductRegistryRow.FromEntity).ToList(),
            familyOptions);
    }

    public async Task<CreateDraftProductResult> CreateDraftProductAsync(NewProductIntakeInput input, CancellationToken cancellationToken = default)
    {
        var trimmedName = input.ProductName?.Trim();
        if (string.IsNullOrWhiteSpace(trimmedName))
            throw new InvalidOperationException("Product name is required.");

        var existing = await db.SellableProducts
            .AsNoTracking()
            .Where(x => x.LifecycleStatus != "DISCONTINUED")
            .ToListAsync(cancellationToken);

        var duplicate = existing.FirstOrDefault(x =>
            string.Equals(Normalize(x.Name), Normalize(trimmedName), StringComparison.Ordinal) &&
            string.Equals(Normalize(x.ProductFamily), Normalize(input.ProductFamily), StringComparison.Ordinal) &&
            string.Equals(Normalize(x.ArtworkName), Normalize(input.DesignName), StringComparison.Ordinal));

        if (duplicate is not null)
            throw new InvalidOperationException($"A matching product already exists in the registry ({duplicate.Name}, SKU {duplicate.SquareSku ?? "pending"}).");

        var now = DateTimeOffset.UtcNow;
        var designName = Clean(input.DesignName);
        var productFamily = Clean(input.ProductFamily);
        var productionFamily = Clean(input.ProductionFamily) ?? productFamily;
        var product = new SellableProductEntity
        {
            Identifier = $"draft-{Guid.NewGuid():N}",
            Name = trimmedName,
            ProductFamily = productFamily,
            ProductionFamily = productionFamily,
            ArtworkName = designName,
            ArtworkKey = string.IsNullOrWhiteSpace(designName) ? null : Normalize(designName),
            ProductTypeCode = InferProductType(trimmedName, productFamily),
            PriceCents = ConvertPriceToCents(input.Price),
            Currency = "USD",
            Notes = Clean(input.Notes),
            LifecycleStatus = "DRAFT",
            CreatedSource = "CONTROL_APP_MANUAL",
            IsActive = true,
            CreatedAt = now,
            UpdatedAt = now
        };

        db.SellableProducts.Add(product);
        await db.SaveChangesAsync(cancellationToken);

        var sku = await sellableSkuService.GetOrAssignAsync(product.Id, cancellationToken);

        return new CreateDraftProductResult(product.Id, sku, product.LifecycleStatus, product.Name);
    }

    public async Task<SquareSkuReconciliationReport> ReconcileSquareCatalogAsync(CancellationToken cancellationToken = default)
    {
        var products = await db.SellableProducts
            .AsNoTracking()
            .ToListAsync(cancellationToken);

        var registryRows = await db.SkuRegistryEntries
            .AsNoTracking()
            .ToListAsync(cancellationToken);

        var squareItems = await squareApiService.GetCatalogItemsAsync(cancellationToken: cancellationToken);
        var squareRows = ExpandSquareRows(squareItems);

        var duplicateGroups = new List<SkuConflictRow>();
        var conflictRows = new List<SkuConflictRow>();
        var missingSquareSkus = squareRows
            .Where(x => string.IsNullOrWhiteSpace(x.Sku))
            .ToList();

        var productsBySquareId = products
            .Where(x => !string.IsNullOrWhiteSpace(x.SquareCatalogItemId) || !string.IsNullOrWhiteSpace(x.SquareCatalogVariationId))
            .ToDictionary(
                x => $"{x.SquareCatalogItemId}|{x.SquareCatalogVariationId}",
                x => x,
                StringComparer.OrdinalIgnoreCase);

        var unmappedSquareRows = squareRows
            .Where(x => !productsBySquareId.ContainsKey($"{x.ItemId}|{x.VariationId}"))
            .ToList();

        var safeMatches = new List<SquareSafeMatchRow>();
        foreach (var squareRow in unmappedSquareRows.Where(x => !string.IsNullOrWhiteSpace(x.Sku)))
        {
            var skuMatch = products
                .Where(x => string.Equals(x.SquareSku, squareRow.Sku, StringComparison.OrdinalIgnoreCase))
                .ToList();

            if (skuMatch.Count == 1 &&
                string.IsNullOrWhiteSpace(skuMatch[0].SquareCatalogItemId) &&
                string.IsNullOrWhiteSpace(skuMatch[0].SquareCatalogVariationId))
            {
                safeMatches.Add(new SquareSafeMatchRow(
                    skuMatch[0].Id,
                    skuMatch[0].Name,
                    skuMatch[0].PermanentSku ?? "",
                    squareRow.ItemId,
                    squareRow.VariationId,
                    squareRow.DisplayName));
            }
            else if (skuMatch.Count > 1)
            {
                conflictRows.Add(new SkuConflictRow(
                    squareRow.Sku ?? "",
                    "Multiple Control App products share the same sellable SKU.",
                    skuMatch.Select(x => x.Name).Append(squareRow.DisplayName).ToList()));
            }
        }

        var duplicateCandidates = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var group in products.Where(x => !string.IsNullOrWhiteSpace(x.SquareSku)).GroupBy(x => x.SquareSku!, StringComparer.OrdinalIgnoreCase))
            if (group.Select(x => x.Id).Distinct().Count() > 1)
                duplicateCandidates.Add(group.Key);
        foreach (var group in registryRows.Where(x => !string.IsNullOrWhiteSpace(x.Sku)).GroupBy(x => x.Sku, StringComparer.OrdinalIgnoreCase))
            if (group.Select(x => x.SellableProductId ?? x.Id).Distinct().Count() > 1)
                duplicateCandidates.Add(group.Key);
        foreach (var group in squareRows.Where(x => !string.IsNullOrWhiteSpace(x.Sku)).GroupBy(x => x.Sku!, StringComparer.OrdinalIgnoreCase))
            if (group.Select(x => $"{x.ItemId}|{x.VariationId}").Distinct(StringComparer.OrdinalIgnoreCase).Count() > 1)
                duplicateCandidates.Add(group.Key);

        foreach (var sku in duplicateCandidates.OrderBy(x => x, StringComparer.OrdinalIgnoreCase).Take(25))
        {
            var details = new List<string>();
            details.AddRange(products.Where(x => string.Equals(x.SquareSku, sku, StringComparison.OrdinalIgnoreCase)).Select(x => $"Control App: {x.Name}"));
            details.AddRange(registryRows.Where(x => string.Equals(x.Sku, sku, StringComparison.OrdinalIgnoreCase)).Select(x => $"Registry: {x.ProductName ?? x.Sku}"));
            details.AddRange(squareRows.Where(x => string.Equals(x.Sku, sku, StringComparison.OrdinalIgnoreCase)).Select(x => $"Square: {x.DisplayName}"));
            duplicateGroups.Add(new SkuConflictRow(sku, "SKU appears in multiple records and needs review.", details.Distinct(StringComparer.OrdinalIgnoreCase).ToList()));
        }

        foreach (var product in products)
        {
            if (!string.IsNullOrWhiteSpace(product.SquareCatalogVariationId) &&
                squareRows.All(x => !string.Equals(x.VariationId, product.SquareCatalogVariationId, StringComparison.OrdinalIgnoreCase)))
            {
                conflictRows.Add(new SkuConflictRow(
                    product.SquareSku ?? "(pending)",
                    "Control App product points at a Square variation that is no longer present.",
                    [$"{product.Name} -> {product.SquareCatalogVariationId}"]));
            }
        }

        return new SquareSkuReconciliationReport(
            products.Count(x => !string.IsNullOrWhiteSpace(x.SquareSku)),
            duplicateGroups.Count,
            missingSquareSkus.Count,
            unmappedSquareRows.Count,
            safeMatches.Count,
            conflictRows.Count,
            duplicateGroups,
            missingSquareSkus.Take(25).ToList(),
            unmappedSquareRows.Take(25).ToList(),
            safeMatches.Take(25).ToList(),
            conflictRows.Take(25).ToList());
    }

    private static List<SquareCatalogSkuRow> ExpandSquareRows(IReadOnlyList<SquareCatalogItem> items)
    {
        var rows = new List<SquareCatalogSkuRow>();
        foreach (var item in items)
        {
            if (item.Variations.Count == 0)
            {
                rows.Add(new SquareCatalogSkuRow(item.Id, null, item.Name, item.Name, null));
                continue;
            }

            foreach (var variation in item.Variations)
            {
                var displayName = string.IsNullOrWhiteSpace(variation.Name) || variation.Name == "Regular"
                    ? item.Name
                    : $"{item.Name} - {variation.Name}";
                rows.Add(new SquareCatalogSkuRow(item.Id, variation.Id, item.Name, displayName, variation.Sku));
            }
        }

        return rows;
    }

    private static string? Clean(string? value)
        => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    private static string Normalize(string? value)
        => string.IsNullOrWhiteSpace(value)
            ? string.Empty
            : new string(value.Where(char.IsLetterOrDigit).Select(char.ToLowerInvariant).ToArray());

    private static string InferProductType(string name, string? family)
    {
        var combined = $"{name} {family}";
        return combined.Contains("notebook", StringComparison.OrdinalIgnoreCase)
            ? "NOTEBOOK"
            : "CONTROL_APP_PRODUCT";
    }

    private static long ConvertPriceToCents(decimal? price)
    {
        if (price is null)
            return 0;

        return (long)Math.Round(price.Value * 100m, MidpointRounding.AwayFromZero);
    }
}

public sealed record ProductRegistryDashboard(
    int TotalProducts,
    int DraftProducts,
    int ActiveProducts,
    int SquareMappedProducts,
    int ReservedSkuCount,
    IReadOnlyList<ProductRegistryRow> Products,
    IReadOnlyList<string> FamilyOptions);

public sealed record ProductRegistryRow(
    Guid Id,
    string Name,
    string? SquareSku,
    string? PermanentSku,
    string LifecycleStatus,
    string? ProductFamily,
    string? ProductionFamily,
    string? ArtworkName,
    string? ProductTypeCode,
    string? LeatherCode,
    decimal Price,
    string? SquareCatalogItemId,
    string? SquareCatalogVariationId,
    string? WooProductId,
    string? WooVariationId,
    DateTimeOffset CreatedAt)
{
    public static ProductRegistryRow FromEntity(SellableProductEntity entity)
        => new(
            entity.Id,
            entity.Name,
            entity.SquareSku,
            entity.PermanentSku,
            entity.LifecycleStatus,
            entity.ProductFamily,
            entity.ProductionFamily,
            entity.ArtworkName,
            entity.ProductTypeCode,
            entity.LeatherCode,
            entity.PriceCents / 100m,
            entity.SquareCatalogItemId,
            entity.SquareCatalogVariationId,
            entity.WooProductId,
            entity.WooVariationId,
            entity.CreatedAt);
}

public sealed record NewProductIntakeInput(
    string ProductName,
    string? ProductFamily,
    string? DesignName,
    decimal? Price,
    string? ProductionFamily,
    string? Notes);

public sealed record CreateDraftProductResult(
    Guid ProductId,
    string AssignedSku,
    string Status,
    string ProductName);

public sealed record SquareSkuReconciliationReport(
    int AssignedSkuCount,
    int DuplicateSkuCount,
    int MissingSquareSkuCount,
    int UnmappedSquareCount,
    int SafeMatchCount,
    int ConflictCount,
    IReadOnlyList<SkuConflictRow> DuplicateSkus,
    IReadOnlyList<SquareCatalogSkuRow> MissingSquareSkus,
    IReadOnlyList<SquareCatalogSkuRow> UnmappedSquareRows,
    IReadOnlyList<SquareSafeMatchRow> SafeMatches,
    IReadOnlyList<SkuConflictRow> Conflicts);

public sealed record SquareCatalogSkuRow(
    string? ItemId,
    string? VariationId,
    string ItemName,
    string DisplayName,
    string? Sku);

public sealed record SquareSafeMatchRow(
    Guid ProductId,
    string ProductName,
    string Sku,
    string? SquareItemId,
    string? SquareVariationId,
    string SquareDisplayName);

public sealed record SkuConflictRow(
    string Sku,
    string Message,
    IReadOnlyList<string> Details);
