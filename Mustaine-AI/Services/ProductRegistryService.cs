using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface IProductRegistryService
{
    Task<NebulaWorkspace> GetWorkspaceAsync(CancellationToken cancellationToken = default);
    Task<ProductRegistryDashboard> GetDashboardAsync(CancellationToken cancellationToken = default);
    Task<CreateDraftProductResult> CreateDraftProductAsync(NewProductIntakeInput input, CancellationToken cancellationToken = default);
    Task<SaveFamilyTemplateResult> SaveFamilyTemplateAsync(SaveFamilyTemplateInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> CreateArtworkBatchAsync(NebulaArtworkWorkflowInput input, CancellationToken cancellationToken = default);
    Task<BulkArtworkPreviewResult> PrepareBulkArtworkPreviewAsync(NebulaBulkArtworkPreviewInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> CommitBulkArtworkBatchAsync(NebulaBulkArtworkCommitInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> CreateProductBatchAsync(NebulaProductWorkflowInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> DuplicateProductAsync(NebulaDuplicateWorkflowInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> RetryBatchAsync(Guid batchId, CancellationToken cancellationToken = default);
    Task<NebulaCatalogHealthReport> ReconcileCatalogAsync(CancellationToken cancellationToken = default);
    Task<NebulaProductDetail> GetProductDetailAsync(Guid productId, CancellationToken cancellationToken = default);
    Task UpdateLifecycleAsync(NebulaLifecycleUpdateInput input, CancellationToken cancellationToken = default);
    Task SaveProductRelationshipAsync(NebulaProductRelationshipInput input, CancellationToken cancellationToken = default);
    Task LinkSquareIdentityAsync(NebulaSquareLinkInput input, CancellationToken cancellationToken = default);
}

public sealed class ProductRegistryService(
    ApplicationDbContext db,
    ISellableSkuService sellableSkuService,
    IPermanentSkuService permanentSkuService,
    ISquareApiService squareApiService,
    IWooCommerceApiService wooCommerceApiService,
    IArtworkVisualService artworkVisualService,
    ILogger<ProductRegistryService> logger) : IProductRegistryService
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = false
    };

    public async Task<NebulaWorkspace> GetWorkspaceAsync(CancellationToken cancellationToken = default)
    {
        var dashboard = await GetDashboardAsync(cancellationToken);
        var families = await LoadFamilyTemplatesAsync(cancellationToken);
        var artworks = await LoadArtworkOptionsAsync(cancellationToken);
        var batches = await LoadRecentBatchesAsync(cancellationToken);
        var catalogProducts = await LoadCatalogProductsAsync(cancellationToken);
        var catalog = new NebulaCatalogSummary(
            catalogProducts,
            catalogProducts.Select(x => x.ProductFamily).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(x => x, StringComparer.OrdinalIgnoreCase).Cast<string>().ToList(),
            catalogProducts.Select(x => x.ProductionFamily).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(x => x, StringComparer.OrdinalIgnoreCase).Cast<string>().ToList(),
            catalogProducts.Select(x => x.LifecycleStatus).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(x => x, StringComparer.OrdinalIgnoreCase).ToList(),
            catalogProducts.Select(x => x.ProductTypeCode).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(x => x, StringComparer.OrdinalIgnoreCase).Cast<string>().ToList(),
            catalogProducts.Select(x => x.LeatherCode).Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).OrderBy(x => x, StringComparer.OrdinalIgnoreCase).Cast<string>().ToList());
        return new NebulaWorkspace(dashboard, families, artworks, batches, catalog);
    }

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

        var permanentSku = (await permanentSkuService.GetOrAssignManyAsync([product.Id], cancellationToken))[product.Id];
        var squareSku = (await sellableSkuService.GetOrAssignManyAsync([product.Id], cancellationToken))[product.Id];

        return new CreateDraftProductResult(product.Id, squareSku, permanentSku, product.LifecycleStatus, product.Name);
    }

    public async Task<SaveFamilyTemplateResult> SaveFamilyTemplateAsync(SaveFamilyTemplateInput input, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(input.FamilyName))
            throw new InvalidOperationException("Family name is required.");

        var familyName = input.FamilyName.Trim();
        var familyKey = BuildFamilyKey(familyName, input.ProductTypeCode);
        var template = input.TemplateId is { } templateId
            ? await db.ProductFamilyTemplates.SingleOrDefaultAsync(x => x.Id == templateId, cancellationToken)
            : await db.ProductFamilyTemplates.SingleOrDefaultAsync(x => x.FamilyKey == familyKey, cancellationToken);

        template ??= new ProductFamilyTemplateEntity
        {
            FamilyKey = familyKey,
            CreatedAt = DateTimeOffset.UtcNow,
        };

        template.FamilyKey = familyKey;
        template.FamilyName = familyName;
        template.ProductTypeCode = CleanCode(input.ProductTypeCode) ?? NormalizeFamilyCode(familyName);
        template.ProductionFamily = Clean(input.ProductionFamily) ?? familyName;
        template.SquareCategoryName = Clean(input.SquareCategoryName) ?? familyName;
        template.WooCategoryName = Clean(input.WooCategoryName);
        template.TaxBehavior = CleanCode(input.TaxBehavior) ?? "STANDARD";
        template.InventoryBehavior = CleanCode(input.InventoryBehavior) ?? "TRACKED";
        template.FulfillmentModel = CleanCode(input.FulfillmentModel) ?? "MANUFACTURED";
        template.DefaultPriceCents = ConvertPriceToCents(input.DefaultPrice);
        template.Currency = "USD";
        template.SellInPerson = input.SellInPerson;
        template.SellOnline = input.SellOnline;
        template.TrackInventory = input.TrackInventory;
        template.ShippingLengthInches = input.ShippingLengthInches;
        template.ShippingWidthInches = input.ShippingWidthInches;
        template.ShippingHeightInches = input.ShippingHeightInches;
        template.ShippingWeightOunces = input.ShippingWeightOunces;
        template.DefaultDescription = CleanLong(input.DefaultDescription);
        template.UpdatedAt = DateTimeOffset.UtcNow;
        template.IsActive = true;

        if (db.Entry(template).State == EntityState.Detached)
            db.ProductFamilyTemplates.Add(template);

        await db.SaveChangesAsync(cancellationToken);

        var existingOptions = await db.ProductFamilyVariantOptions
            .Where(x => x.ProductFamilyTemplateId == template.Id)
            .ToListAsync(cancellationToken);
        db.ProductFamilyVariantOptions.RemoveRange(existingOptions);

        var options = ParseVariantOptions(input.VariantOptionsText);
        foreach (var option in options)
        {
            db.ProductFamilyVariantOptions.Add(new ProductFamilyVariantOptionEntity
            {
                ProductFamilyTemplateId = template.Id,
                DimensionKey = option.DimensionKey,
                OptionCode = option.OptionCode,
                OptionName = option.OptionName,
                IsDefaultSelected = option.IsDefaultSelected,
                IsEnabled = true,
                SortOrder = option.SortOrder,
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow
            });
        }

        await db.SaveChangesAsync(cancellationToken);
        return new SaveFamilyTemplateResult(template.Id, template.FamilyKey, template.FamilyName);
    }

    public async Task<NebulaBatchResult> CreateArtworkBatchAsync(NebulaArtworkWorkflowInput input, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(input.ArtworkName))
            throw new InvalidOperationException("Artwork / design name is required.");
        if (input.FamilyKeys.Count == 0)
            throw new InvalidOperationException("Select at least one product family.");

        var artwork = await UpsertArtworkAsync(
            input.ArtworkName,
            input.DesignAssetPath,
            input.ProductImagePath,
            input.Notes,
            cancellationToken);

        var templates = await LoadFamilyTemplatesAsync(cancellationToken);
        var selectedTemplates = templates
            .Where(x => input.FamilyKeys.Contains(x.FamilyKey, StringComparer.OrdinalIgnoreCase))
            .ToList();

        if (selectedTemplates.Count == 0)
            throw new InvalidOperationException("The selected family templates were not found.");

        var batch = await CreateBatchAsync(
            "NEW_ARTWORK",
            input.ArtworkName.Trim(),
            artwork.ArtworkKey,
            artwork.ArtworkName,
            input,
            cancellationToken);

        foreach (var template in selectedTemplates)
        {
            var options = ResolveDefaultOptions(template);
            foreach (var option in options)
            {
                try
                {
                    await CreateVariantDraftAsync(
                        batch,
                        template,
                        BuildArtworkProductName(artwork.ArtworkName, template.FamilyName, option.OptionName),
                        artwork,
                        option,
                        template.DefaultPrice,
                        input.Notes,
                        input.SyncToSquare,
                        cancellationToken);
                }
                catch (Exception ex)
                {
                    await CreateFailedBatchVariantAsync(
                        batch,
                        template,
                        artwork.ArtworkKey,
                        artwork.ArtworkName,
                        BuildArtworkProductName(artwork.ArtworkName, template.FamilyName, option.OptionName),
                        option,
                        ex.Message,
                        cancellationToken);
                }
            }
        }

        await ReserveBatchSkusAsync(batch.Id, cancellationToken);
        return await FinalizeBatchAsync(batch.Id, input.SyncToSquare, cancellationToken);
    }

    public async Task<BulkArtworkPreviewResult> PrepareBulkArtworkPreviewAsync(NebulaBulkArtworkPreviewInput input, CancellationToken cancellationToken = default)
    {
        if (input.FamilyKeys.Count == 0)
            throw new InvalidOperationException("Select at least one product family.");

        var artworkNames = ParseArtworkNames(input.ArtworkNamesText);
        if (artworkNames.Count == 0)
            throw new InvalidOperationException("Enter at least one artwork name.");

        var templates = (await LoadFamilyTemplatesAsync(cancellationToken))
            .Where(x => input.FamilyKeys.Contains(x.FamilyKey, StringComparer.OrdinalIgnoreCase))
            .ToList();

        if (templates.Count == 0)
            throw new InvalidOperationException("The selected family templates were not found.");

        var existingProducts = await db.SellableProducts
            .AsNoTracking()
            .ToListAsync(cancellationToken);

        var rows = new List<BulkArtworkPreviewRow>();
        var duplicateTracker = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var artworkName in artworkNames)
        {
            var artworkKey = ResolveArtworkKey(artworkName);
            foreach (var template in templates)
            {
                foreach (var option in ResolveDefaultOptions(template))
                {
                    var productTypeCode = CleanCode(template.ProductTypeCode) ?? NormalizeFamilyCode(template.FamilyName);
                    var leatherCode = string.IsNullOrWhiteSpace(option.OptionCode) ? null : CleanCode(option.OptionCode);
                    var productName = BuildArtworkProductName(artworkName, template.FamilyName, option.OptionName);
                    var rowKey = $"{artworkKey}|{template.FamilyKey}|{productTypeCode}|{leatherCode}";

                    string? conflict = null;
                    string? existingProductName = null;
                    if (!duplicateTracker.Add(rowKey))
                    {
                        conflict = "This artwork/family/variant combination appears more than once in the current batch.";
                    }
                    else
                    {
                        var existing = existingProducts.FirstOrDefault(x =>
                            string.Equals(x.ProductTypeCode, productTypeCode, StringComparison.OrdinalIgnoreCase) &&
                            string.Equals(x.ArtworkKey, artworkKey, StringComparison.OrdinalIgnoreCase) &&
                            string.Equals(x.LeatherCode, leatherCode, StringComparison.OrdinalIgnoreCase));
                        if (existing is not null)
                        {
                            conflict = "A sellable variant with this artwork and variant identity already exists.";
                            existingProductName = existing.Name;
                        }
                    }

                    rows.Add(new BulkArtworkPreviewRow(
                        Guid.NewGuid().ToString("N"),
                        artworkName,
                        template.FamilyKey,
                        template.FamilyName,
                        template.ProductTypeCode,
                        template.ProductionFamily,
                        option.OptionCode,
                        option.OptionName,
                        productName,
                        template.DefaultPrice,
                        string.Empty,
                        conflict is null,
                        conflict,
                        existingProductName));
                }
            }
        }

        var creatableRows = rows.Where(x => x.Create).ToList();
        var proposedSkus = await sellableSkuService.PreviewNextSkusAsync(creatableRows.Count, cancellationToken);
        var proposedLookup = creatableRows
            .Select((row, index) => new { row.ClientRowId, ProposedSku = proposedSkus[index] })
            .ToDictionary(x => x.ClientRowId, x => x.ProposedSku, StringComparer.OrdinalIgnoreCase);

        rows = rows.Select(row => row with
        {
            ProposedSquareSku = proposedLookup.TryGetValue(row.ClientRowId, out var proposed) ? proposed : "Conflict"
        }).ToList();

        return new BulkArtworkPreviewResult(
            $"Prepared {rows.Count} variant row(s); {creatableRows.Count} are ready for creation.",
            rows);
    }

    public async Task<NebulaBatchResult> CommitBulkArtworkBatchAsync(NebulaBulkArtworkCommitInput input, CancellationToken cancellationToken = default)
    {
        var selectedRows = input.Rows
            .Where(x => x.Create)
            .Where(x => !string.IsNullOrWhiteSpace(x.ArtworkName) && !string.IsNullOrWhiteSpace(x.FamilyKey))
            .ToList();

        if (selectedRows.Count == 0)
            throw new InvalidOperationException("Select at least one preview row to create.");

        var templates = (await LoadFamilyTemplatesAsync(cancellationToken))
            .Where(x => selectedRows.Select(r => r.FamilyKey).Contains(x.FamilyKey, StringComparer.OrdinalIgnoreCase))
            .ToDictionary(x => x.FamilyKey, StringComparer.OrdinalIgnoreCase);

        var batch = await CreateBatchAsync(
            "BULK_ARTWORK",
            $"Bulk artwork batch ({selectedRows.Count} variants)",
            null,
            null,
            input,
            cancellationToken);

        var artworks = new Dictionary<string, ProductArtworkEntity>(StringComparer.OrdinalIgnoreCase);
        foreach (var row in selectedRows)
        {
            if (!templates.TryGetValue(row.FamilyKey, out var template))
            {
                await CreateFailedBatchVariantAsync(
                    batch,
                    null,
                    ResolveArtworkKey(row.ArtworkName),
                    row.ArtworkName,
                    BuildArtworkProductName(row.ArtworkName, row.FamilyKey, row.VariantName),
                    new NebulaVariantOption("LEATHER", row.VariantCode, row.VariantName, true, 0),
                    "The selected product family template was not found.",
                    cancellationToken);
                continue;
            }

            try
            {
                if (!artworks.TryGetValue(row.ArtworkName.Trim(), out var artwork))
                {
                    artwork = await UpsertArtworkAsync(
                        row.ArtworkName,
                        row.DesignAssetPath,
                        row.ProductImagePath,
                        input.Notes,
                        cancellationToken);
                    artworks[row.ArtworkName.Trim()] = artwork;
                }

                var option = template.VariantOptions.FirstOrDefault(x => string.Equals(x.OptionCode, row.VariantCode, StringComparison.OrdinalIgnoreCase))
                    ?? new NebulaVariantOption("LEATHER", row.VariantCode, string.IsNullOrWhiteSpace(row.VariantName) ? FriendlyOptionName(row.VariantCode) : row.VariantName, true, 0);

                await CreateVariantDraftAsync(
                    batch,
                    template,
                    BuildArtworkProductName(artwork.ArtworkName, template.FamilyName, option.OptionName),
                    artwork,
                    option,
                    row.Price,
                    input.Notes,
                    input.SyncToSquare,
                    cancellationToken);
            }
            catch (Exception ex)
            {
                await CreateFailedBatchVariantAsync(
                    batch,
                    template,
                    ResolveArtworkKey(row.ArtworkName),
                    row.ArtworkName,
                    BuildArtworkProductName(row.ArtworkName, template.FamilyName, row.VariantName),
                    new NebulaVariantOption("LEATHER", row.VariantCode, row.VariantName, true, 0),
                    ex.Message,
                    cancellationToken);
            }
        }

        await ReserveBatchSkusAsync(batch.Id, cancellationToken);
        return await FinalizeBatchAsync(batch.Id, input.SyncToSquare, cancellationToken);
    }

    public async Task<NebulaBatchResult> CreateProductBatchAsync(NebulaProductWorkflowInput input, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(input.ProductName))
            throw new InvalidOperationException("Product name is required.");
        if (string.IsNullOrWhiteSpace(input.FamilyKey))
            throw new InvalidOperationException("Family is required.");

        ProductArtworkEntity? artwork = null;
        if (!string.IsNullOrWhiteSpace(input.ArtworkName))
        {
            artwork = await UpsertArtworkAsync(
                input.ArtworkName,
                input.DesignAssetPath,
                input.ProductImagePath,
                input.Notes,
                cancellationToken);
        }

        var template = (await LoadFamilyTemplatesAsync(cancellationToken))
            .FirstOrDefault(x => string.Equals(x.FamilyKey, input.FamilyKey, StringComparison.OrdinalIgnoreCase));

        if (template is null)
            throw new InvalidOperationException("The selected family was not found.");

        var selectedOptions = template.VariantOptions.Count == 0
            ? [new NebulaVariantOption("LEATHER", "", "Regular", true, 0)]
            : template.VariantOptions
                .Where(x => input.SelectedVariantCodes.Count == 0
                    ? x.IsDefaultSelected
                    : input.SelectedVariantCodes.Contains(x.OptionCode, StringComparer.OrdinalIgnoreCase))
                .OrderBy(x => x.SortOrder)
                .ToList();

        if (selectedOptions.Count == 0)
            throw new InvalidOperationException("Select at least one variant option.");

        var batch = await CreateBatchAsync(
            "NEW_PRODUCT",
            input.ProductName.Trim(),
            artwork?.ArtworkKey,
            artwork?.ArtworkName,
            input,
            cancellationToken);

        foreach (var option in selectedOptions)
        {
            try
            {
                await CreateVariantDraftAsync(
                    batch,
                    template,
                    BuildProductName(input.ProductName.Trim(), option.OptionName),
                    artwork,
                    option,
                    input.PriceOverride ?? template.DefaultPrice,
                    input.Notes,
                    input.SyncToSquare,
                    cancellationToken);
            }
            catch (Exception ex)
            {
                await CreateFailedBatchVariantAsync(
                    batch,
                    template,
                    artwork?.ArtworkKey,
                    artwork?.ArtworkName,
                    BuildProductName(input.ProductName.Trim(), option.OptionName),
                    option,
                    ex.Message,
                    cancellationToken);
            }
        }

        await ReserveBatchSkusAsync(batch.Id, cancellationToken);
        return await FinalizeBatchAsync(batch.Id, input.SyncToSquare, cancellationToken);
    }

    public async Task<NebulaBatchResult> DuplicateProductAsync(NebulaDuplicateWorkflowInput input, CancellationToken cancellationToken = default)
    {
        var source = await db.SellableProducts
            .AsNoTracking()
            .SingleOrDefaultAsync(x => x.Id == input.SourceProductId, cancellationToken);

        if (source is null)
            throw new InvalidOperationException("Source product was not found.");

        var familyKey = BuildFamilyKey(source.ProductFamily ?? source.Name, source.ProductTypeCode);
        var template = (await LoadFamilyTemplatesAsync(cancellationToken))
            .FirstOrDefault(x => string.Equals(x.FamilyKey, familyKey, StringComparison.OrdinalIgnoreCase))
            ?? new NebulaFamilyTemplateSummary(
                null,
                familyKey,
                source.ProductFamily ?? source.Name,
                source.ProductTypeCode,
                source.ProductionFamily,
                source.SquareCategoryName,
                source.PriceCents / 100m,
                source.LeatherCode is null ? [] : [new NebulaVariantOption("LEATHER", source.LeatherCode, FriendlyOptionName(source.LeatherCode), true, 0)],
                false,
                source.SellInPerson,
                source.SellOnline,
                source.TrackInventory);

        var artworkName = string.IsNullOrWhiteSpace(input.ArtworkName)
            ? $"{source.ArtworkName ?? source.Name} Copy"
            : input.ArtworkName.Trim();
        var artwork = await UpsertArtworkAsync(artworkName, null, null, input.Notes, cancellationToken);

        var batch = await CreateBatchAsync(
            "DUPLICATE_PRODUCT",
            input.NewProductName.Trim(),
            artwork.ArtworkKey,
            artwork.ArtworkName,
            input,
            cancellationToken);

        var option = new NebulaVariantOption(
            "LEATHER",
            source.LeatherCode ?? string.Empty,
            FriendlyOptionName(source.LeatherCode),
            true,
            0);

        try
        {
            await CreateVariantDraftAsync(
                batch,
                template,
                BuildProductName(input.NewProductName.Trim(), option.OptionName),
                artwork,
                option,
                source.PriceCents / 100m,
                input.Notes ?? source.Notes,
                input.SyncToSquare,
                cancellationToken,
                productTypeOverride: source.ProductTypeCode,
                productionFamilyOverride: source.ProductionFamily,
                squareCategoryOverride: source.SquareCategoryName);
        }
        catch (Exception ex)
        {
            await CreateFailedBatchVariantAsync(
                batch,
                template,
                artwork.ArtworkKey,
                artwork.ArtworkName,
                BuildProductName(input.NewProductName.Trim(), option.OptionName),
                option,
                ex.Message,
                cancellationToken);
        }

        await ReserveBatchSkusAsync(batch.Id, cancellationToken);
        return await FinalizeBatchAsync(batch.Id, input.SyncToSquare, cancellationToken);
    }

    public async Task<NebulaBatchResult> RetryBatchAsync(Guid batchId, CancellationToken cancellationToken = default)
    {
        var batch = await db.NebulaCreationBatches
            .SingleOrDefaultAsync(x => x.Id == batchId, cancellationToken);

        if (batch is null)
            throw new InvalidOperationException("Nebula batch was not found.");

        await ReserveBatchSkusAsync(batchId, cancellationToken);

        var variants = await db.NebulaCreationBatchVariants
            .Include(x => x.SellableProduct)
            .Where(x => x.BatchId == batchId)
            .ToListAsync(cancellationToken);

        foreach (var variant in variants.Where(x => x.RetryAllowed && x.SellableProductId is not null && x.Status is "SQUARE_FAILED" or "DRAFT_READY"))
            await TrySyncVariantAsync(variant, cancellationToken);

        return await FinalizeBatchAsync(batchId, syncToSquare: true, cancellationToken);
    }

    public async Task<NebulaCatalogHealthReport> ReconcileCatalogAsync(CancellationToken cancellationToken = default)
    {
        var products = await db.SellableProducts.AsNoTracking().ToListAsync(cancellationToken);
        var batches = await db.NebulaCreationBatchVariants.AsNoTracking().Where(x => x.Status == "SQUARE_FAILED").ToListAsync(cancellationToken);
        var squareItems = await squareApiService.GetCatalogItemsAsync(cancellationToken: cancellationToken);
        var squareRows = ExpandSquareRows(squareItems);

        IReadOnlyList<WooCommerceCatalogEntry> wooRows;
        string? wooError = null;
        try
        {
            wooRows = await wooCommerceApiService.GetPublishedCatalogAsync(cancellationToken);
        }
        catch (Exception ex)
        {
            wooRows = [];
            wooError = ex.Message;
        }

        var issues = new List<NebulaCatalogIssueRow>();
        var safeMatches = new List<SquareSafeMatchRow>();

        if (!string.IsNullOrWhiteSpace(wooError))
        {
            issues.Add(new NebulaCatalogIssueRow(
                "WOO",
                "WARN",
                "WOO_UNAVAILABLE",
                null,
                "Woo catalog could not be loaded",
                wooError,
                "Verify Woo read-only credentials in Settings before relying on reconciliation.",
                null,
                null));
        }

        foreach (var group in products.Where(x => !string.IsNullOrWhiteSpace(x.SquareSku)).GroupBy(x => x.SquareSku!, StringComparer.OrdinalIgnoreCase))
        {
            if (group.Count() <= 1)
                continue;

            foreach (var product in group)
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "INTERNAL",
                    "ERROR",
                    "DUPLICATE_INTERNAL_SKU",
                    product.Id,
                    $"Duplicate internal Square SKU {group.Key}",
                    "Multiple Control App products share the same sellable Square SKU.",
                    "Resolve the duplicate before any further downstream sync.",
                    null,
                    group.Key));
            }
        }

        foreach (var group in products.Where(x => !string.IsNullOrWhiteSpace(x.SquareCatalogVariationId)).GroupBy(x => x.SquareCatalogVariationId!, StringComparer.OrdinalIgnoreCase))
        {
            if (group.Count() <= 1)
                continue;

            foreach (var product in group)
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "INTERNAL",
                    "ERROR",
                    "DUPLICATE_SQUARE_MAPPING",
                    product.Id,
                    "Duplicate Square variation mapping",
                    "Multiple Control App products point at the same Square variation ID.",
                    "Split the mapping so every sellable variant owns exactly one downstream identity.",
                    group.Key,
                    product.SquareSku));
            }
        }

        foreach (var group in products.Where(x => !string.IsNullOrWhiteSpace(x.WooVariationId) || !string.IsNullOrWhiteSpace(x.WooProductId))
                     .GroupBy(x => $"{x.WooProductId}|{x.WooVariationId}", StringComparer.OrdinalIgnoreCase))
        {
            if (group.Count() <= 1)
                continue;

            foreach (var product in group)
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "INTERNAL",
                    "WARN",
                    "DUPLICATE_WOO_MAPPING",
                    product.Id,
                    "Duplicate Woo mapping",
                    "Multiple Control App products point at the same Woo identity.",
                    "Review the duplicate mapping before enabling or retrying Woo automation.",
                    group.Key,
                    product.SquareSku));
            }
        }

        var squareByCompositeId = squareRows.ToDictionary(x => $"{x.ItemId}|{x.VariationId}", StringComparer.OrdinalIgnoreCase);
        foreach (var product in products)
        {
            if (!string.IsNullOrWhiteSpace(product.SquareCatalogItemId) || !string.IsNullOrWhiteSpace(product.SquareCatalogVariationId))
            {
                var key = $"{product.SquareCatalogItemId}|{product.SquareCatalogVariationId}";
                if (!squareByCompositeId.TryGetValue(key, out var mappedSquare))
                {
                    issues.Add(new NebulaCatalogIssueRow(
                        "SQUARE",
                        "WARN",
                        "STALE_SQUARE_MAPPING",
                        product.Id,
                        product.Name,
                        "This Control App product points at a Square item/variation that is not present in the live catalog snapshot.",
                        "Refresh or relink the Square identity before retrying sync.",
                        key,
                        product.SquareSku));
                }
                else if (!string.Equals(mappedSquare.DisplayName, product.Name, StringComparison.OrdinalIgnoreCase))
                {
                    issues.Add(new NebulaCatalogIssueRow(
                        "SQUARE",
                        "INFO",
                        "SQUARE_NAME_MISMATCH",
                        product.Id,
                        product.Name,
                        $"Square currently shows \"{mappedSquare.DisplayName}\" for this mapped product.",
                        "Review whether the naming difference is intentional before syncing names again.",
                        key,
                        product.SquareSku));
                }
            }
            else if (!string.Equals(product.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase))
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "SQUARE",
                    "WARN",
                    "MISSING_SQUARE_IDENTITY",
                    product.Id,
                    product.Name,
                    "This sellable variant has no Square item/variation identity yet.",
                    "Create or relink the Square record before in-person sales rely on it.",
                    null,
                    product.SquareSku));
            }

            var expectWoo = product.SellOnline && !string.Equals(product.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase);
            if (expectWoo && string.IsNullOrWhiteSpace(product.WooProductId))
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "WOO",
                    "INFO",
                    "MISSING_WOO_IDENTITY",
                    product.Id,
                    product.Name,
                    "This online-enabled variant does not have a Woo identity yet.",
                    "Create or map the Woo product/variation before expecting website availability.",
                    null,
                    product.SquareSku));
            }
        }

        var productsBySquareSku = products
            .Where(x => !string.IsNullOrWhiteSpace(x.SquareSku))
            .GroupBy(x => x.SquareSku!, StringComparer.OrdinalIgnoreCase)
            .ToDictionary(x => x.Key, x => x.ToList(), StringComparer.OrdinalIgnoreCase);

        foreach (var row in squareRows)
        {
            if (string.IsNullOrWhiteSpace(row.Sku))
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "SQUARE",
                    "WARN",
                    "SQUARE_VARIATION_MISSING_SKU",
                    null,
                    row.DisplayName,
                    "Square variation has no SKU.",
                    "Assign or reconcile the SKU before using it as a stable sellable identity.",
                    $"{row.ItemId}|{row.VariationId}",
                    null));
                continue;
            }

            var mappedInternally = products.Any(x =>
                string.Equals(x.SquareCatalogItemId, row.ItemId, StringComparison.OrdinalIgnoreCase) &&
                string.Equals(x.SquareCatalogVariationId, row.VariationId, StringComparison.OrdinalIgnoreCase));

            if (!mappedInternally)
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "SQUARE",
                    "INFO",
                    "UNMAPPED_SQUARE_VARIANT",
                    null,
                    row.DisplayName,
                    "Square variation is not mapped to a Control App sellable variant.",
                    "Review whether this is intentional legacy catalog data or a missing link.",
                    $"{row.ItemId}|{row.VariationId}",
                    row.Sku));
            }

            if (productsBySquareSku.TryGetValue(row.Sku, out var skuMatches) &&
                skuMatches.Count == 1 &&
                string.IsNullOrWhiteSpace(skuMatches[0].SquareCatalogItemId) &&
                string.IsNullOrWhiteSpace(skuMatches[0].SquareCatalogVariationId))
            {
                safeMatches.Add(new SquareSafeMatchRow(
                    skuMatches[0].Id,
                    skuMatches[0].Name,
                    skuMatches[0].PermanentSku ?? string.Empty,
                    row.ItemId,
                    row.VariationId,
                    row.DisplayName));
            }
        }

        var wooByCompositeId = wooRows.ToDictionary(x => $"{x.ProductId}|{x.VariationId}", StringComparer.OrdinalIgnoreCase);
        foreach (var product in products.Where(x => !string.IsNullOrWhiteSpace(x.WooProductId)))
        {
            var key = $"{product.WooProductId}|{product.WooVariationId}";
            if (!wooByCompositeId.ContainsKey(key))
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "WOO",
                    "WARN",
                    "STALE_WOO_MAPPING",
                    product.Id,
                    product.Name,
                    "This product points at a Woo identity that is not present in the published catalog snapshot.",
                    "Verify whether the website record was removed, unpublished, or needs remapping.",
                    key,
                    product.SquareSku));
            }
        }

        foreach (var product in products.Where(x => string.Equals(x.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase)))
        {
            if (!string.IsNullOrWhiteSpace(product.SquareCatalogVariationId))
            {
                var key = $"{product.SquareCatalogItemId}|{product.SquareCatalogVariationId}";
                if (squareByCompositeId.ContainsKey(key))
                {
                    issues.Add(new NebulaCatalogIssueRow(
                        "SQUARE",
                        "INFO",
                        "DISCONTINUED_STILL_PRESENT_DOWNSTREAM",
                        product.Id,
                        product.Name,
                        "Discontinued product still has an active Square mapping on record.",
                        "Confirm whether the downstream listing should remain sellable or be paused manually.",
                        key,
                        product.SquareSku));
                }
            }

            if (!string.IsNullOrWhiteSpace(product.WooProductId))
            {
                issues.Add(new NebulaCatalogIssueRow(
                    "WOO",
                    "INFO",
                    "DISCONTINUED_WOO_REVIEW",
                    product.Id,
                    product.Name,
                    "Discontinued product still has Woo identity fields recorded.",
                    "Confirm whether the website listing should be unpublished or intentionally preserved.",
                    $"{product.WooProductId}|{product.WooVariationId}",
                    product.SquareSku));
            }
        }

        foreach (var failedVariant in batches)
        {
            issues.Add(new NebulaCatalogIssueRow(
                "INTERNAL",
                "WARN",
                "RECENT_SYNC_FAILURE",
                failedVariant.SellableProductId,
                failedVariant.ProductName,
                failedVariant.LastError ?? "A recent Square sync attempt failed.",
                "Use the batch retry once the underlying issue is corrected; the reserved SKU remains attached.",
                failedVariant.SquareCatalogVariationId,
                failedVariant.ReservedSquareSku));
        }

        return new NebulaCatalogHealthReport(
            issues.Count,
            issues.Count(x => x.Scope == "SQUARE"),
            issues.Count(x => x.Scope == "WOO"),
            issues.Count(x => x.Scope == "INTERNAL"),
            issues.OrderByDescending(IssueSeverityRank).ThenBy(x => x.Scope).ThenBy(x => x.Title).ToList(),
            safeMatches);
    }

    public async Task<NebulaProductDetail> GetProductDetailAsync(Guid productId, CancellationToken cancellationToken = default)
    {
        var product = await db.SellableProducts
            .AsNoTracking()
            .SingleOrDefaultAsync(x => x.Id == productId, cancellationToken);

        if (product is null)
            throw new InvalidOperationException("Product not found.");

        var artworkKey = product.ArtworkKey;
        var productFamily = product.ProductFamily;
        var productTypeCode = product.ProductTypeCode;
        var relatedVariants = await db.SellableProducts
            .AsNoTracking()
            .Where(x =>
                x.Id == productId ||
                (!string.IsNullOrWhiteSpace(artworkKey) &&
                 x.ArtworkKey == artworkKey &&
                 x.ProductFamily == productFamily &&
                 x.ProductTypeCode == productTypeCode))
            .OrderBy(x => x.LeatherCode)
            .ThenBy(x => x.Name)
            .ToListAsync(cancellationToken);

        var health = await ReconcileCatalogAsync(cancellationToken);
        var row = ProductRegistryRow.FromEntity(product);
        return new NebulaProductDetail(
            row,
            relatedVariants.Select(ProductRegistryRow.FromEntity).ToList(),
            health.Issues.Where(x => x.ProductId == productId).ToList(),
            BuildProductionReadiness(product),
            row.SquareSyncState,
            row.WooSyncState,
            ResolveBarcodeStatus(product, relatedVariants));
    }

    public async Task UpdateLifecycleAsync(NebulaLifecycleUpdateInput input, CancellationToken cancellationToken = default)
    {
        var status = CleanCode(input.LifecycleStatus);
        if (status is not ("DRAFT" or "ACTIVE" or "PAUSED" or "DISCONTINUED"))
            throw new InvalidOperationException("Lifecycle status must be Draft, Active, Paused, or Discontinued.");

        var product = await db.SellableProducts.SingleOrDefaultAsync(x => x.Id == input.ProductId, cancellationToken);
        if (product is null)
            throw new InvalidOperationException("Product not found.");

        product.LifecycleStatus = status;
        product.IsActive = status != "DISCONTINUED";
        product.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);
        await RefreshRegistryEntriesAsync(product, cancellationToken);
    }

    public async Task SaveProductRelationshipAsync(NebulaProductRelationshipInput input, CancellationToken cancellationToken = default)
    {
        if (input.ProductId == input.RelatedProductId)
            throw new InvalidOperationException("A product cannot point at itself.");

        var source = await db.SellableProducts.SingleOrDefaultAsync(x => x.Id == input.ProductId, cancellationToken);
        var related = await db.SellableProducts.SingleOrDefaultAsync(x => x.Id == input.RelatedProductId, cancellationToken);
        if (source is null || related is null)
            throw new InvalidOperationException("One of the selected products was not found.");

        var relationshipType = CleanCode(input.RelationshipType);
        switch (relationshipType)
        {
            case "MERGE":
                source.MergedIntoProductId = related.Id;
                break;
            case "REPLACEMENT":
                source.ReplacedByProductId = related.Id;
                break;
            default:
                throw new InvalidOperationException("Relationship type must be MERGE or REPLACEMENT.");
        }

        source.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);
    }

    public async Task LinkSquareIdentityAsync(NebulaSquareLinkInput input, CancellationToken cancellationToken = default)
    {
        var product = await db.SellableProducts.SingleOrDefaultAsync(x => x.Id == input.ProductId, cancellationToken);
        if (product is null)
            throw new InvalidOperationException("Product not found.");

        var squareRows = ExpandSquareRows(await squareApiService.GetCatalogItemsAsync(cancellationToken: cancellationToken));
        var target = squareRows.SingleOrDefault(x =>
            string.Equals(x.ItemId, input.SquareItemId, StringComparison.OrdinalIgnoreCase) &&
            string.Equals(x.VariationId, input.SquareVariationId, StringComparison.OrdinalIgnoreCase));
        if (target is null)
            throw new InvalidOperationException("The selected Square item/variation was not found in the live catalog snapshot.");

        var existingMapping = await db.SellableProducts
            .AsNoTracking()
            .Where(x => x.Id != input.ProductId)
            .FirstOrDefaultAsync(x =>
                x.SquareCatalogItemId == input.SquareItemId &&
                x.SquareCatalogVariationId == input.SquareVariationId,
                cancellationToken);
        if (existingMapping is not null)
            throw new InvalidOperationException($"Square identity already belongs to {existingMapping.Name}.");

        product.SquareCatalogItemId = input.SquareItemId;
        product.SquareCatalogVariationId = input.SquareVariationId;
        product.SquareSyncedAt = DateTimeOffset.UtcNow;
        product.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);
        await RefreshRegistryEntriesAsync(product, cancellationToken);
    }

    private async Task<List<NebulaFamilyTemplateSummary>> LoadFamilyTemplatesAsync(CancellationToken cancellationToken)
    {
        var storedTemplates = await db.ProductFamilyTemplates
            .AsNoTracking()
            .Where(x => x.IsActive)
            .OrderBy(x => x.FamilyName)
            .ToListAsync(cancellationToken);
        var storedOptions = await db.ProductFamilyVariantOptions
            .AsNoTracking()
            .OrderBy(x => x.SortOrder)
            .ToListAsync(cancellationToken);

        var result = storedTemplates
            .Select(template => new NebulaFamilyTemplateSummary(
                template.Id,
                template.FamilyKey,
                template.FamilyName,
                template.ProductTypeCode,
                template.ProductionFamily,
                template.SquareCategoryName,
                template.DefaultPriceCents / 100m,
                storedOptions.Where(x => x.ProductFamilyTemplateId == template.Id).Select(MapVariantOption).ToList(),
                true,
                template.SellInPerson,
                template.SellOnline,
                template.TrackInventory))
            .ToList();

        var existingProducts = await db.SellableProducts
            .AsNoTracking()
            .Where(x => x.IsActive)
            .ToListAsync(cancellationToken);

        var derived = existingProducts
            .Where(x => !string.IsNullOrWhiteSpace(x.ProductFamily) || !string.IsNullOrWhiteSpace(x.ProductTypeCode))
            .GroupBy(x => BuildFamilyKey(x.ProductFamily ?? x.Name, x.ProductTypeCode), StringComparer.OrdinalIgnoreCase)
            .Select(group =>
            {
                var sample = group.OrderByDescending(x => x.CreatedAt).First();
                var options = group
                    .Where(x => !string.IsNullOrWhiteSpace(x.LeatherCode))
                    .Select(x => x.LeatherCode!)
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                    .Select((code, index) => new NebulaVariantOption("LEATHER", code, FriendlyOptionName(code), true, index))
                    .ToList();
                return new NebulaFamilyTemplateSummary(
                    null,
                    group.Key,
                    sample.ProductFamily ?? sample.Name,
                    sample.ProductTypeCode,
                    sample.ProductionFamily,
                    sample.SquareCategoryName,
                    sample.PriceCents / 100m,
                    options,
                    false,
                    sample.SellInPerson,
                    sample.SellOnline,
                    sample.TrackInventory);
            })
            .Where(summary => result.All(x => !string.Equals(x.FamilyKey, summary.FamilyKey, StringComparison.OrdinalIgnoreCase)))
            .OrderBy(x => x.FamilyName, StringComparer.OrdinalIgnoreCase)
            .ToList();

        result.AddRange(derived);
        return result
            .OrderBy(x => x.FamilyName, StringComparer.OrdinalIgnoreCase)
            .ThenBy(x => x.ProductTypeCode, StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private async Task<List<NebulaArtworkSummary>> LoadArtworkOptionsAsync(CancellationToken cancellationToken)
    {
        var stored = await db.ProductArtworks
            .AsNoTracking()
            .OrderBy(x => x.ArtworkName)
            .Take(250)
            .ToListAsync(cancellationToken);

        var combined = stored
            .Select(x => new NebulaArtworkSummary(x.Id, x.ArtworkKey, x.ArtworkName, x.DesignAssetPath, x.ProductImagePath))
            .ToList();

        foreach (var visual in artworkVisualService.GetAll())
        {
            if (combined.Any(x => string.Equals(x.ArtworkKey, visual.Key, StringComparison.OrdinalIgnoreCase)))
                continue;

            combined.Add(new NebulaArtworkSummary(null, visual.Key, visual.Name, visual.ImageUrl, visual.ImageUrl));
        }

        return combined
            .GroupBy(x => Normalize(x.ArtworkName), StringComparer.OrdinalIgnoreCase)
            .Select(x => x.First())
            .OrderBy(x => x.ArtworkName, StringComparer.OrdinalIgnoreCase)
            .Take(250)
            .ToList();
    }

    private async Task<List<NebulaBatchSummary>> LoadRecentBatchesAsync(CancellationToken cancellationToken)
    {
        var batches = await db.NebulaCreationBatches
            .AsNoTracking()
            .OrderByDescending(x => x.CreatedAt)
            .Take(20)
            .ToListAsync(cancellationToken);
        var variants = await db.NebulaCreationBatchVariants
            .AsNoTracking()
            .Where(x => batches.Select(b => b.Id).Contains(x.BatchId))
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        return batches
            .Select(batch => new NebulaBatchSummary(
                batch.Id,
                batch.OperationKey,
                batch.WorkflowType,
                batch.Status,
                batch.RequestedName,
                batch.ArtworkName,
                batch.CreatedAt,
                variants.Where(x => x.BatchId == batch.Id).Select(MapBatchVariant).ToList()))
            .ToList();
    }

    private async Task<List<ProductRegistryRow>> LoadCatalogProductsAsync(CancellationToken cancellationToken)
        => (await db.SellableProducts
                .AsNoTracking()
                .OrderByDescending(x => x.UpdatedAt)
                .ThenByDescending(x => x.CreatedAt)
                .ToListAsync(cancellationToken))
            .Select(ProductRegistryRow.FromEntity)
            .ToList();

    private async Task<ProductArtworkEntity> UpsertArtworkAsync(
        string artworkName,
        string? designAssetPath,
        string? productImagePath,
        string? notes,
        CancellationToken cancellationToken)
    {
        var normalizedName = artworkName.Trim();
        var key = designAssetPath?.Trim().Replace('\\', '/')
                  ?? artworkVisualService.GetAll()
                      .FirstOrDefault(x => string.Equals(Normalize(x.Name), Normalize(normalizedName), StringComparison.OrdinalIgnoreCase))
                      ?.Key
                  ?? Normalize(normalizedName);

        var artwork = await db.ProductArtworks.SingleOrDefaultAsync(x => x.ArtworkKey == key, cancellationToken);

        artwork ??= new ProductArtworkEntity
        {
            ArtworkKey = key,
            CreatedAt = DateTimeOffset.UtcNow
        };

        artwork.ArtworkName = normalizedName;
        artwork.DesignAssetPath = designAssetPath ?? artwork.DesignAssetPath ?? artworkVisualService.FindImageUrl(normalizedName);
        artwork.ProductImagePath = productImagePath ?? artwork.ProductImagePath ?? artworkVisualService.FindImageUrl(normalizedName);
        artwork.Notes = Clean(notes);
        artwork.UpdatedAt = DateTimeOffset.UtcNow;

        if (db.Entry(artwork).State == EntityState.Detached)
            db.ProductArtworks.Add(artwork);

        await db.SaveChangesAsync(cancellationToken);
        return artwork;
    }

    private async Task<NebulaCreationBatchEntity> CreateBatchAsync(
        string workflowType,
        string requestedName,
        string? artworkKey,
        string? artworkName,
        object payload,
        CancellationToken cancellationToken)
    {
        var batch = new NebulaCreationBatchEntity
        {
            OperationKey = $"nebula-{Guid.NewGuid():N}",
            WorkflowType = workflowType,
            Status = "DRAFT",
            RequestedName = requestedName,
            ArtworkKey = artworkKey,
            ArtworkName = artworkName,
            PayloadJson = JsonSerializer.Serialize(payload, JsonOptions),
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        };
        db.NebulaCreationBatches.Add(batch);
        await db.SaveChangesAsync(cancellationToken);
        return batch;
    }

    private async Task CreateVariantDraftAsync(
        NebulaCreationBatchEntity batch,
        NebulaFamilyTemplateSummary template,
        string productName,
        ProductArtworkEntity? artwork,
        NebulaVariantOption option,
        decimal price,
        string? notes,
        bool syncToSquare,
        CancellationToken cancellationToken,
        string? productTypeOverride = null,
        string? productionFamilyOverride = null,
        string? squareCategoryOverride = null)
    {
        var artworkKey = artwork?.ArtworkKey;
        var productTypeCode = CleanCode(productTypeOverride ?? template.ProductTypeCode) ?? NormalizeFamilyCode(template.FamilyName);
        var leatherCode = string.IsNullOrWhiteSpace(option.OptionCode) ? null : CleanCode(option.OptionCode);

        if (!string.IsNullOrWhiteSpace(artworkKey))
        {
            var existingVariant = await db.SellableProducts
                .AsNoTracking()
                .FirstOrDefaultAsync(
                    x => x.ProductTypeCode == productTypeCode &&
                         x.ArtworkKey == artworkKey &&
                         x.LeatherCode == leatherCode,
                    cancellationToken);
            if (existingVariant is not null)
                throw new InvalidOperationException($"A variant already exists for {template.FamilyName} / {artwork!.ArtworkName} / {option.OptionName}.");
        }

        var product = new SellableProductEntity
        {
            Identifier = $"nebula-{Guid.NewGuid():N}",
            Name = productName,
            ProductFamily = template.FamilyName,
            ProductionFamily = productionFamilyOverride ?? template.ProductionFamily ?? template.FamilyName,
            ProductTypeCode = productTypeCode,
            ArtworkKey = artworkKey,
            ArtworkName = artwork?.ArtworkName,
            LeatherCode = leatherCode,
            PriceCents = ConvertPriceToCents(price),
            Currency = "USD",
            SquareCategoryName = squareCategoryOverride ?? template.SquareCategoryName ?? template.FamilyName,
            Notes = Clean(notes),
            LifecycleStatus = "DRAFT",
            CreatedSource = "NEBULA_ROUND3",
            SellInPerson = template.SellInPerson,
            SellOnline = template.SellOnline,
            TrackInventory = template.TrackInventory,
            IsActive = true,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        };

        db.SellableProducts.Add(product);
        await db.SaveChangesAsync(cancellationToken);

        var designPath = artwork?.DesignAssetPath ?? artworkVisualService.FindImageUrl(artwork?.ArtworkName);
        db.SellableProductElements.Add(new SellableProductElementEntity
        {
            SellableProductId = product.Id,
            ElementType = "ARTWORK",
            ElementKey = artwork?.ArtworkKey ?? $"{product.Id:N}",
            ElementName = artwork?.ArtworkName ?? product.Name,
            CategoryName = product.SquareCategoryName,
            DesignFileName = designPath,
            HasImage = !string.IsNullOrWhiteSpace(designPath),
            SortOrder = 0,
            CreatedAt = DateTimeOffset.UtcNow
        });

        db.NebulaCreationBatchVariants.Add(new NebulaCreationBatchVariantEntity
        {
            BatchId = batch.Id,
            ProductName = product.Name,
            ArtworkKey = artwork?.ArtworkKey,
            ArtworkName = artwork?.ArtworkName,
            ProductFamilyTemplateId = template.TemplateId,
            SellableProductId = product.Id,
            ProductTypeCode = product.ProductTypeCode,
            LeatherCode = product.LeatherCode,
            Status = "PENDING_RESERVATION",
            RetryAllowed = syncToSquare,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        });

        await db.SaveChangesAsync(cancellationToken);
    }

    private async Task CreateFailedBatchVariantAsync(
        NebulaCreationBatchEntity batch,
        NebulaFamilyTemplateSummary? template,
        string? artworkKey,
        string? artworkName,
        string productName,
        NebulaVariantOption option,
        string error,
        CancellationToken cancellationToken)
    {
        db.NebulaCreationBatchVariants.Add(new NebulaCreationBatchVariantEntity
        {
            BatchId = batch.Id,
            ProductName = productName,
            ArtworkKey = artworkKey,
            ArtworkName = artworkName,
            ProductFamilyTemplateId = template?.TemplateId,
            ProductTypeCode = template?.ProductTypeCode,
            LeatherCode = string.IsNullOrWhiteSpace(option.OptionCode) ? null : CleanCode(option.OptionCode),
            Status = "DRAFT_FAILED",
            LastError = error,
            RetryAllowed = false,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        });
        await db.SaveChangesAsync(cancellationToken);
    }

    private async Task ReserveBatchSkusAsync(Guid batchId, CancellationToken cancellationToken)
    {
        var variants = await db.NebulaCreationBatchVariants
            .Include(x => x.SellableProduct)
            .Where(x => x.BatchId == batchId && x.SellableProductId != null)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        var pendingProducts = variants
            .Where(x => x.SellableProduct is not null && string.IsNullOrWhiteSpace(x.ReservedSquareSku))
            .Select(x => x.SellableProductId!.Value)
            .Distinct()
            .ToList();

        if (pendingProducts.Count == 0)
            return;

        var permanentSkus = await permanentSkuService.GetOrAssignManyAsync(pendingProducts, cancellationToken);
        var squareSkus = await sellableSkuService.GetOrAssignManyAsync(pendingProducts, cancellationToken);

        foreach (var variant in variants.Where(x => x.SellableProductId is not null))
        {
            if (squareSkus.TryGetValue(variant.SellableProductId!.Value, out var squareSku))
                variant.ReservedSquareSku = squareSku;

            if (variant.Status == "PENDING_RESERVATION")
            {
                variant.Status = "DRAFT_READY";
                variant.LastError = null;
            }

            variant.UpdatedAt = DateTimeOffset.UtcNow;

            if (variant.SellableProduct is not null)
            {
                var permanentSku = permanentSkus[variant.SellableProductId!.Value];
                logger.LogInformation(
                    "Reserved SKUs for {ProductName}: permanent {PermanentSku}, square {SquareSku}.",
                    variant.SellableProduct.Name,
                    permanentSku,
                    squareSku);
            }
        }

        await db.SaveChangesAsync(cancellationToken);
    }

    private async Task<NebulaBatchResult> FinalizeBatchAsync(Guid batchId, bool syncToSquare, CancellationToken cancellationToken)
    {
        var batch = await db.NebulaCreationBatches.SingleAsync(x => x.Id == batchId, cancellationToken);
        var variants = await db.NebulaCreationBatchVariants
            .Include(x => x.SellableProduct)
            .Where(x => x.BatchId == batchId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        if (syncToSquare)
        {
            foreach (var variant in variants.Where(x => x.Status == "DRAFT_READY" && x.SellableProductId is not null))
                await TrySyncVariantAsync(variant, cancellationToken);
        }

        var refreshedVariants = await db.NebulaCreationBatchVariants
            .AsNoTracking()
            .Where(x => x.BatchId == batchId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        var failureCount = refreshedVariants.Count(x => x.Status is "SQUARE_FAILED" or "DRAFT_FAILED");
        var successCount = refreshedVariants.Count(x => x.Status == "SQUARE_SYNCED");
        var draftReadyCount = refreshedVariants.Count(x => x.Status == "DRAFT_READY");
        var totalCount = refreshedVariants.Count;

        batch.Status = failureCount > 0
            ? (successCount + draftReadyCount > 0 ? "PARTIAL_FAILURE" : "FAILED")
            : syncToSquare
                ? "COMPLETE"
                : "DRAFT_READY";
        batch.LastError = failureCount > 0
            ? $"{failureCount} variant(s) need attention."
            : null;
        batch.CompletedAt = batch.Status is "COMPLETE" or "DRAFT_READY" or "PARTIAL_FAILURE" ? DateTimeOffset.UtcNow : null;
        batch.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);

        var message = batch.Status switch
        {
            "COMPLETE" => $"Created {totalCount} variant(s). Square synced {successCount}.",
            "DRAFT_READY" => $"Prepared {totalCount} draft variant(s). Square sync was skipped.",
            "PARTIAL_FAILURE" => $"Prepared {totalCount} variant(s). {successCount} synced, {draftReadyCount} are draft-ready, and {failureCount} need follow-up.",
            _ => $"Prepared {totalCount} variant(s). Review the batch errors before retrying."
        };

        return new NebulaBatchResult(
            batch.Id,
            batch.OperationKey,
            batch.Status,
            message,
            refreshedVariants.Select(MapBatchVariant).ToList());
    }

    private async Task TrySyncVariantAsync(NebulaCreationBatchVariantEntity variant, CancellationToken cancellationToken)
    {
        if (variant.SellableProductId is null)
            return;

        variant.AttemptCount += 1;
        variant.LastAttemptedAt = DateTimeOffset.UtcNow;
        variant.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);

        var result = await squareApiService.SyncSellableProductSkuAsync(variant.SellableProductId.Value, cancellationToken: cancellationToken);
        if (result.IsSuccessful)
        {
            variant.Status = "SQUARE_SYNCED";
            variant.LastError = null;
            variant.SquareCatalogItemId = result.SquareCatalogItemId;
            variant.SquareCatalogVariationId = result.SquareCatalogVariationId;
            variant.RetryAllowed = false;
        }
        else
        {
            variant.Status = "SQUARE_FAILED";
            variant.LastError = result.Message;
            variant.RetryAllowed = true;
        }

        variant.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);
    }

    private async Task RefreshRegistryEntriesAsync(SellableProductEntity product, CancellationToken cancellationToken)
    {
        foreach (var sku in new[] { product.PermanentSku, product.SquareSku }.Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase))
        {
            var entry = await db.SkuRegistryEntries
                .SingleOrDefaultAsync(x => x.SellableProductId == product.Id && x.Sku == sku, cancellationToken);

            entry ??= new SkuRegistryEntryEntity
            {
                Sku = sku!,
                SellableProductId = product.Id,
                CreatedAt = DateTimeOffset.UtcNow
            };

            entry.Sku = sku!;
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
            entry.LastReconciledAt = DateTimeOffset.UtcNow;
            entry.UpdatedAt = DateTimeOffset.UtcNow;
            entry.ReservedAt = entry.Status == "RESERVED" ? entry.ReservedAt ?? DateTimeOffset.UtcNow : entry.ReservedAt;
            entry.AssignedAt = entry.Status == "ASSIGNED" ? entry.AssignedAt ?? DateTimeOffset.UtcNow : entry.AssignedAt;
            entry.RetiredAt = entry.Status == "RETIRED" ? entry.RetiredAt ?? DateTimeOffset.UtcNow : entry.RetiredAt;

            if (db.Entry(entry).State == EntityState.Detached)
                db.SkuRegistryEntries.Add(entry);
        }

        await db.SaveChangesAsync(cancellationToken);
    }

    private static NebulaBatchVariantSummary MapBatchVariant(NebulaCreationBatchVariantEntity entity)
        => new(
            entity.Id,
            entity.SellableProductId,
            entity.ProductName,
            entity.ArtworkName,
            entity.ProductTypeCode,
            entity.LeatherCode,
            entity.Status,
            entity.ReservedSquareSku,
            entity.SquareCatalogItemId,
            entity.SquareCatalogVariationId,
            entity.LastError,
            entity.RetryAllowed);

    private static NebulaVariantOption MapVariantOption(ProductFamilyVariantOptionEntity entity)
        => new(
            entity.DimensionKey,
            entity.OptionCode,
            entity.OptionName,
            entity.IsDefaultSelected,
            entity.SortOrder);

    private static List<NebulaVariantOption> ParseVariantOptions(string? text)
    {
        var value = text?.Trim();
        if (string.IsNullOrWhiteSpace(value))
            return [];

        return value.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Select((entry, index) =>
            {
                var parts = entry.Split(':', 2, StringSplitOptions.TrimEntries);
                var code = CleanCode(parts[0]) ?? string.Empty;
                var name = parts.Length > 1 && !string.IsNullOrWhiteSpace(parts[1]) ? parts[1].Trim() : FriendlyOptionName(code);
                return new NebulaVariantOption("LEATHER", code, name, true, index);
            })
            .Where(x => !string.IsNullOrWhiteSpace(x.OptionCode))
            .ToList();
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

    private static List<NebulaVariantOption> ResolveDefaultOptions(NebulaFamilyTemplateSummary template)
        => template.VariantOptions.Count == 0
            ? [new NebulaVariantOption("LEATHER", "", "Regular", true, 0)]
            : template.VariantOptions.Where(x => x.IsDefaultSelected).OrderBy(x => x.SortOrder).ToList();

    private static List<string> ParseArtworkNames(string text)
        => text
            .Split(['\n', '\r', ','], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Select(x => x.Trim())
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

    private string ResolveArtworkKey(string artworkName)
        => artworkVisualService.GetAll()
               .FirstOrDefault(x => string.Equals(Normalize(x.Name), Normalize(artworkName), StringComparison.OrdinalIgnoreCase))
               ?.Key
           ?? Normalize(artworkName);

    private static int IssueSeverityRank(NebulaCatalogIssueRow row)
        => row.Severity switch
        {
            "ERROR" => 0,
            "WARN" => 1,
            _ => 2
        };

    private static string BuildProductionReadiness(SellableProductEntity product)
    {
        var gaps = new List<string>();
        if (string.IsNullOrWhiteSpace(product.ProductionFamily))
            gaps.Add("production family");
        if (string.IsNullOrWhiteSpace(product.PermanentSku))
            gaps.Add("permanent SKU");
        if (string.IsNullOrWhiteSpace(product.ArtworkKey))
            gaps.Add("artwork link");
        return gaps.Count == 0 ? "Ready for Stash/Dynamo linkage." : $"Needs {string.Join(", ", gaps)} before downstream production is fully ready.";
    }

    private static string ResolveBarcodeStatus(SellableProductEntity product, IReadOnlyList<SellableProductEntity> relatedVariants)
    {
        if (string.IsNullOrWhiteSpace(product.BarcodeValue))
            return "No barcode assigned yet.";

        var duplicates = relatedVariants.Count(x => string.Equals(x.BarcodeValue, product.BarcodeValue, StringComparison.OrdinalIgnoreCase));
        return duplicates > 1 ? "Barcode duplicates another related variant and needs review." : "Barcode is unique within this design family.";
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

    private static string BuildArtworkProductName(string artworkName, string familyName, string? optionName)
        => BuildProductName($"{artworkName} - {familyName}", optionName);

    private static string BuildProductName(string baseName, string? optionName)
        => string.IsNullOrWhiteSpace(optionName) || string.Equals(optionName, "Regular", StringComparison.OrdinalIgnoreCase)
            ? baseName
            : $"{baseName} - {optionName}";

    private static string BuildFamilyKey(string familyName, string? productTypeCode)
    {
        var family = Normalize(familyName);
        var type = Normalize(productTypeCode);
        return string.IsNullOrWhiteSpace(type) ? family : $"{family}:{type}";
    }

    private static string? Clean(string? value)
        => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    private static string? CleanLong(string? value)
        => string.IsNullOrWhiteSpace(value) ? null : value.Trim();

    private static string? CleanCode(string? value)
        => string.IsNullOrWhiteSpace(value)
            ? null
            : new string(value.Trim().Where(ch => char.IsLetterOrDigit(ch) || ch == '_' || ch == '-').ToArray()).ToUpperInvariant();

    private static string Normalize(string? value)
        => string.IsNullOrWhiteSpace(value)
            ? string.Empty
            : new string(value.Where(char.IsLetterOrDigit).Select(char.ToLowerInvariant).ToArray());

    private static string NormalizeFamilyCode(string value)
        => string.Join("_", value
            .Split(' ', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Select(part => new string(part.Where(char.IsLetterOrDigit).ToArray()).ToUpperInvariant()))
            .Trim('_');

    private static string InferProductType(string name, string? family)
    {
        var combined = $"{name} {family}";
        if (combined.Contains("notebook", StringComparison.OrdinalIgnoreCase))
            return "NOTEBOOK";
        if (combined.Contains("modular", StringComparison.OrdinalIgnoreCase))
            return "MODULAR";
        return "CONTROL_APP_PRODUCT";
    }

    private static string FriendlyOptionName(string? optionCode)
        => optionCode?.ToUpperInvariant() switch
        {
            "BK" => "Black",
            "BR" => "Brown",
            "" or null => "Regular",
            _ => optionCode!
        };

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
    string? BarcodeValue,
    bool SellInPerson,
    bool SellOnline,
    bool TrackInventory,
    Guid? MergedIntoProductId,
    Guid? ReplacedByProductId,
    string SquareSyncState,
    string WooSyncState,
    DateTimeOffset CreatedAt,
    DateTimeOffset UpdatedAt)
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
            entity.BarcodeValue,
            entity.SellInPerson,
            entity.SellOnline,
            entity.TrackInventory,
            entity.MergedIntoProductId,
            entity.ReplacedByProductId,
            ResolveSquareSyncState(entity),
            ResolveWooSyncState(entity),
            entity.CreatedAt,
            entity.UpdatedAt);

    private static string ResolveSquareSyncState(SellableProductEntity entity)
    {
        if (string.Equals(entity.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase))
            return "DISCONTINUED";
        return string.IsNullOrWhiteSpace(entity.SquareCatalogVariationId) ? "PENDING" : "MAPPED";
    }

    private static string ResolveWooSyncState(SellableProductEntity entity)
    {
        if (!entity.SellOnline)
            return "N/A";
        if (string.Equals(entity.LifecycleStatus, "DISCONTINUED", StringComparison.OrdinalIgnoreCase))
            return "DISCONTINUED";
        return string.IsNullOrWhiteSpace(entity.WooProductId) ? "MISSING" : "MAPPED";
    }
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
    string PermanentSku,
    string Status,
    string ProductName);

public sealed record SquareCatalogSkuRow(
    string ItemId,
    string? VariationId,
    string ItemName,
    string DisplayName,
    string? Sku);

public sealed record SquareSafeMatchRow(
    Guid ProductId,
    string ProductName,
    string PermanentSku,
    string SquareItemId,
    string? SquareVariationId,
    string SquareDisplayName);
