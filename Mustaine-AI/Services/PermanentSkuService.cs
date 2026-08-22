using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface IPermanentSkuService
{
    Task<string> GetOrAssignAsync(Guid sellableProductId, CancellationToken cancellationToken = default);
}

public sealed class PermanentSkuService(
    ApplicationDbContext db) : IPermanentSkuService
{
    public async Task<string> GetOrAssignAsync(Guid sellableProductId, CancellationToken cancellationToken = default)
    {
        var strategy = db.Database.CreateExecutionStrategy();

        return await strategy.ExecuteAsync(async () =>
        {
            await using var tx = await db.Database.BeginTransactionAsync(
                System.Data.IsolationLevel.Serializable,
                cancellationToken);

            var product = await db.SellableProducts
                .SingleAsync(x => x.Id == sellableProductId, cancellationToken);

            if (!string.IsNullOrWhiteSpace(product.PermanentSku))
            {
                await UpsertRegistryEntryAsync(product, product.PermanentSku, cancellationToken);
                await db.SaveChangesAsync(cancellationToken);
                await tx.CommitAsync(cancellationToken);
                return product.PermanentSku;
            }

            var sequence = await db.PermanentSkuSequences
                .SingleOrDefaultAsync(x => x.Id == 1, cancellationToken);

            if (sequence is null)
            {
                sequence = new PermanentSkuSequenceEntity
                {
                    Id = 1,
                    LastIssuedNumber = 0
                };
                db.PermanentSkuSequences.Add(sequence);
            }

            var reservedNumbers = await GetReservedSkuNumbersAsync(cancellationToken);
            var currentMax = reservedNumbers.Count == 0
                ? sequence.LastIssuedNumber
                : Math.Max(sequence.LastIssuedNumber, reservedNumbers.Max());

            var next = currentMax;
            do
            {
                next++;
                if (next > 99999)
                    throw new InvalidOperationException("Permanent SKU range 00001-99999 is exhausted.");
            }
            while (reservedNumbers.Contains(next));

            var assignedSku = next.ToString("D5");
            sequence.LastIssuedNumber = next;
            product.PermanentSku = assignedSku;
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
            .Where(x => x.PermanentSku != null && x.PermanentSku != "")
            .Select(x => x.PermanentSku!)
            .ToListAsync(cancellationToken);

        var registrySkus = await db.SkuRegistryEntries
            .AsNoTracking()
            .Where(x => x.Sku != null && x.Sku != "")
            .Select(x => x.Sku)
            .ToListAsync(cancellationToken);

        var numbers = new HashSet<int>();
        foreach (var sku in productSkus.Concat(registrySkus))
        {
            if (TryParsePermanentSku(sku, out var parsed))
                numbers.Add(parsed);
        }

        return numbers;
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

    private static bool TryParsePermanentSku(string? value, out int parsed)
    {
        parsed = 0;
        return !string.IsNullOrWhiteSpace(value)
               && value.Length == 5
               && int.TryParse(value, out parsed)
               && parsed > 0;
    }
}
