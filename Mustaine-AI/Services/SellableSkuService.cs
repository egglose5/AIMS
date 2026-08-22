using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface ISellableSkuService
{
    Task<string> GetOrAssignAsync(Guid sellableProductId, CancellationToken cancellationToken = default);
}

public sealed class SellableSkuService(
    ApplicationDbContext db,
    ISquareApiService squareApiService,
    ILogger<SellableSkuService> logger) : ISellableSkuService
{
    public async Task<string> GetOrAssignAsync(Guid sellableProductId, CancellationToken cancellationToken = default)
    {
        var liveSquareSkus = await GetLiveSquareSkuValuesAsync(cancellationToken);
        var strategy = db.Database.CreateExecutionStrategy();

        return await strategy.ExecuteAsync(async () =>
        {
            await using var tx = await db.Database.BeginTransactionAsync(
                System.Data.IsolationLevel.Serializable,
                cancellationToken);

            var product = await db.SellableProducts
                .SingleAsync(x => x.Id == sellableProductId, cancellationToken);

            if (!string.IsNullOrWhiteSpace(product.SquareSku))
            {
                await UpsertRegistryEntryAsync(product, product.SquareSku, cancellationToken);
                await db.SaveChangesAsync(cancellationToken);
                await tx.CommitAsync(cancellationToken);
                return product.SquareSku;
            }

            var reservedNumbers = await GetReservedSkuNumbersAsync(cancellationToken);
            foreach (var liveSquareSku in liveSquareSkus)
            {
                reservedNumbers.Add(liveSquareSku);
            }

            var next = reservedNumbers.Count == 0 ? 1 : reservedNumbers.Max();
            do
            {
                next++;
                if (next > 9999)
                    throw new InvalidOperationException("Existing 4-digit Square SKU range is exhausted.");
            }
            while (reservedNumbers.Contains(next));

            var assignedSku = next.ToString("D4");
            product.SquareSku = assignedSku;
            product.UpdatedAt = DateTimeOffset.UtcNow;

            await UpsertRegistryEntryAsync(product, assignedSku, cancellationToken);
            await db.SaveChangesAsync(cancellationToken);
            await tx.CommitAsync(cancellationToken);

            return assignedSku;
        });
    }

    private async Task<HashSet<int>> GetReservedSkuNumbersAsync(CancellationToken cancellationToken)
    {
        var productSkus = await db.SellableProducts
            .AsNoTracking()
            .Where(x => x.SquareSku != null && x.SquareSku != "")
            .Select(x => x.SquareSku!)
            .ToListAsync(cancellationToken);

        var registryRows = await db.SkuRegistryEntries
            .AsNoTracking()
            .Select(x => new { x.Sku, x.Status })
            .ToListAsync(cancellationToken);

        var numbers = new HashSet<int>();
        foreach (var sku in productSkus)
        {
            if (TryParseSellableSku(sku, out var parsed))
                numbers.Add(parsed);
        }

        foreach (var row in registryRows)
        {
            if ((string.Equals(row.Status, "RESERVED", StringComparison.OrdinalIgnoreCase)
                 || string.Equals(row.Status, "ASSIGNED", StringComparison.OrdinalIgnoreCase)
                 || string.Equals(row.Status, "RETIRED", StringComparison.OrdinalIgnoreCase))
                && TryParseSellableSku(row.Sku, out var parsed))
            {
                numbers.Add(parsed);
            }
        }

        return numbers;
    }

    private async Task<HashSet<int>> GetLiveSquareSkuValuesAsync(CancellationToken cancellationToken)
    {
        try
        {
            var items = await squareApiService.GetCatalogItemsAsync(cancellationToken: cancellationToken);
            var numbers = new HashSet<int>();

            foreach (var sku in items
                         .SelectMany(item => item.Variations.Select(variation => variation.Sku))
                         .Where(value => !string.IsNullOrWhiteSpace(value)))
            {
                if (TryParseSellableSku(sku!, out var parsed))
                    numbers.Add(parsed);
            }

            return numbers;
        }
        catch (Exception exception)
        {
            logger.LogWarning(exception, "Square SKU reconciliation was unavailable during sellable SKU allocation; falling back to local registry only.");
            return [];
        }
    }

    private async Task UpsertRegistryEntryAsync(
        SellableProductEntity product,
        string sku,
        CancellationToken cancellationToken)
    {
        var now = DateTimeOffset.UtcNow;
        var entry = await db.SkuRegistryEntries
            .SingleOrDefaultAsync(
                x => x.SellableProductId == product.Id || x.Sku == sku,
                cancellationToken);

        if (entry is not null &&
            !string.Equals(entry.Sku, sku, StringComparison.OrdinalIgnoreCase) &&
            entry.SellableProductId != product.Id)
        {
            throw new InvalidOperationException($"SKU registry conflict: {sku} is already attached to another product.");
        }

        entry ??= new SkuRegistryEntryEntity
        {
            Sku = sku,
            SellableProductId = product.Id,
            CreatedAt = now
        };

        entry.Sku = sku;
        entry.SellableProductId = product.Id;
        entry.ProductName = product.Name;
        entry.VariationName = BuildVariationName(product);
        entry.SquareCatalogItemId = product.SquareCatalogItemId;
        entry.SquareCatalogVariationId = product.SquareCatalogVariationId;
        entry.WooProductId = product.WooProductId;
        entry.WooVariationId = product.WooVariationId;
        entry.BarcodeValue = product.BarcodeValue;
        entry.Source = string.IsNullOrWhiteSpace(product.CreatedSource) ? "CONTROL_APP" : product.CreatedSource;
        entry.Status = ResolveRegistryStatus(product);
        entry.LastReconciledAt = now;
        entry.UpdatedAt = now;
        entry.ConflictSummary = null;
        entry.ReservedAt = entry.Status == "RESERVED" ? entry.ReservedAt ?? now : entry.ReservedAt;
        entry.AssignedAt = entry.Status == "ASSIGNED" ? entry.AssignedAt ?? now : entry.AssignedAt;
        entry.RetiredAt = entry.Status == "RETIRED" ? entry.RetiredAt ?? now : entry.RetiredAt;

        if (entry.Id == Guid.Empty)
            entry.Id = Guid.NewGuid();

        if (db.Entry(entry).State == EntityState.Detached)
            db.SkuRegistryEntries.Add(entry);
    }

    private static string ResolveRegistryStatus(SellableProductEntity product)
    {
        if (string.Equals(product.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase))
            return "RETIRED";

        if (string.Equals(product.LifecycleStatus, "DRAFT", StringComparison.OrdinalIgnoreCase))
            return "RESERVED";

        return "ASSIGNED";
    }

    private static string? BuildVariationName(SellableProductEntity product)
    {
        var parts = new[] { product.ProductTypeCode, product.LeatherCode }
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .ToArray();

        return parts.Length == 0 ? null : string.Join(" / ", parts);
    }

    private static bool TryParseSellableSku(string? value, out int parsed)
    {
        parsed = 0;
        return !string.IsNullOrWhiteSpace(value)
               && value.Length == 4
               && int.TryParse(value, out parsed)
               && parsed >= 0;
    }
}
