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
    Task<NebulaBatchResult> CreateProductBatchAsync(NebulaProductWorkflowInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> DuplicateProductAsync(NebulaDuplicateWorkflowInput input, CancellationToken cancellationToken = default);
    Task<NebulaBatchResult> RetryBatchAsync(Guid batchId, CancellationToken cancellationToken = default);
    Task<SquareSkuReconciliationReport> ReconcileSquareCatalogAsync(CancellationToken cancellationToken = default);
}

public sealed class ProductRegistryService(
    ApplicationDbContext db,
    ISellableSkuService sellableSkuService,
    IPermanentSkuService permanentSkuService,
    ISquareApiService squareApiService,
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
        return new NebulaWorkspace(dashboard, families, artworks, batches);
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

        var permanentSku = await permanentSkuService.GetOrAssignAsync(product.Id, cancellationToken);
        var squareSku = await sellableSkuService.GetOrAssignAsync(product.Id, cancellationToken);

        return new CreateDraftProductResult(product.Id, squareSku, permanentSku, product.LifecycleStatus, product.Name);
    }

    public async Task<SaveFamilyTemplateResult> SaveFamilyTemplateAsync(SaveFamilyTemplateInput input, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(input.FamilyName))
            throw new InvalidOperationException("Family name is required.");

        var familyName = input.FamilyName.Trim();
        var familyKey = BuildFamilyKey(familyName, input.ProductTypeCode);
        var template = input.TemplateId is { } templateId
            ? await db.ProductFamilyTemplates
                .SingleOrDefaultAsync(x => x.Id == templateId, cancellationToken)
            : await db.ProductFamilyTemplates
                .SingleOrDefaultAsync(x => x.FamilyKey == familyKey, cancellationToken);

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
            var options = template.VariantOptions.Count == 0
                ? [new NebulaVariantOption("LEATHER", "", "Regular", true, 0)]
                : template.VariantOptions.Where(x => x.IsDefaultSelected).OrderBy(x => x.SortOrder).ToList();

            foreach (var option in options)
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
        }

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
                source.LeatherCode is null
                    ? []
                    : [new NebulaVariantOption("LEATHER", source.LeatherCode, FriendlyOptionName(source.LeatherCode), true, 0)],
                false);

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

        return await FinalizeBatchAsync(batch.Id, input.SyncToSquare, cancellationToken);
    }

    public async Task<NebulaBatchResult> RetryBatchAsync(Guid batchId, CancellationToken cancellationToken = default)
    {
        var batch = await db.NebulaCreationBatches
            .SingleOrDefaultAsync(x => x.Id == batchId, cancellationToken);

        if (batch is null)
            throw new InvalidOperationException("Nebula batch was not found.");

        var variants = await db.NebulaCreationBatchVariants
            .Include(x => x.SellableProduct)
            .Where(x => x.BatchId == batchId)
            .ToListAsync(cancellationToken);

        foreach (var variant in variants.Where(x => x.RetryAllowed && x.SellableProductId is not null && x.Status is "SQUARE_FAILED" or "DRAFT_READY"))
        {
            await TrySyncVariantAsync(variant, cancellationToken);
        }

        return await FinalizeBatchAsync(batchId, syncToSquare: true, cancellationToken);
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
                storedOptions.Where(x => x.ProductFamilyTemplateId == template.Id)
                    .Select(MapVariantOption)
                    .ToList(),
                true))
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
                var sample = group
                    .OrderByDescending(x => x.CreatedAt)
                    .First();
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
                    false);
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

        var artwork = await db.ProductArtworks
            .SingleOrDefaultAsync(x => x.ArtworkKey == key, cancellationToken);

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

        if (artworkKey is not null)
        {
            var existingVariant = await db.SellableProducts
                .AsNoTracking()
                .FirstOrDefaultAsync(
                    x => x.ProductTypeCode == productTypeCode
                         && x.ArtworkKey == artworkKey
                         && x.LeatherCode == leatherCode,
                    cancellationToken);
            if (existingVariant is not null)
                throw new InvalidOperationException($"A variant already exists for {template.FamilyName} / {artwork.ArtworkName} / {option.OptionName}.");
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
            CreatedSource = "NEBULA_ROUND2",
            IsActive = true,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        };

        db.SellableProducts.Add(product);
        await db.SaveChangesAsync(cancellationToken);

        var permanentSku = await permanentSkuService.GetOrAssignAsync(product.Id, cancellationToken);
        var squareSku = await sellableSkuService.GetOrAssignAsync(product.Id, cancellationToken);

        var designPath = artwork?.DesignAssetPath ?? artworkVisualService.FindImageUrl(artwork?.ArtworkName);
        var imageElement = new SellableProductElementEntity
        {
            SellableProductId = product.Id,
            ElementType = "ARTWORK",
            ElementKey = artwork?.ArtworkKey ?? $"{product.Id:N}",
            ElementName = artwork?.ArtworkName ?? product.Name,
            CategoryName = product.SquareCategoryName,
            DesignFileName = designPath,
            HasImage = true,
            SortOrder = 0,
            CreatedAt = DateTimeOffset.UtcNow
        };
        db.SellableProductElements.Add(imageElement);

        var batchVariant = new NebulaCreationBatchVariantEntity
        {
            BatchId = batch.Id,
            ProductName = product.Name,
            ProductFamilyTemplateId = template.TemplateId,
            SellableProductId = product.Id,
            ProductTypeCode = product.ProductTypeCode,
            LeatherCode = product.LeatherCode,
            Status = "DRAFT_READY",
            ReservedSquareSku = squareSku,
            RetryAllowed = syncToSquare,
            CreatedAt = DateTimeOffset.UtcNow,
            UpdatedAt = DateTimeOffset.UtcNow
        };
        db.NebulaCreationBatchVariants.Add(batchVariant);
        await db.SaveChangesAsync(cancellationToken);

        logger.LogInformation(
            "Created Nebula draft variant {ProductName} with permanent SKU {PermanentSku} and Square SKU {SquareSku}.",
            product.Name,
            permanentSku,
            squareSku);
    }

    private async Task<NebulaBatchResult> FinalizeBatchAsync(Guid batchId, bool syncToSquare, CancellationToken cancellationToken)
    {
        var batch = await db.NebulaCreationBatches
            .SingleAsync(x => x.Id == batchId, cancellationToken);
        var variants = await db.NebulaCreationBatchVariants
            .Include(x => x.SellableProduct)
            .Where(x => x.BatchId == batchId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        if (syncToSquare)
        {
            foreach (var variant in variants.Where(x => x.Status == "DRAFT_READY" && x.SellableProductId is not null))
            {
                await TrySyncVariantAsync(variant, cancellationToken);
            }
        }

        var refreshedVariants = await db.NebulaCreationBatchVariants
            .AsNoTracking()
            .Where(x => x.BatchId == batchId)
            .OrderBy(x => x.CreatedAt)
            .ToListAsync(cancellationToken);

        var failureCount = refreshedVariants.Count(x => x.Status == "SQUARE_FAILED");
        var successCount = refreshedVariants.Count(x => x.Status == "SQUARE_SYNCED");
        var pendingCount = refreshedVariants.Count - failureCount - successCount;

        batch.Status = failureCount > 0
            ? (successCount > 0 ? "PARTIAL_FAILURE" : "FAILED")
            : syncToSquare
                ? "COMPLETE"
                : "DRAFT_READY";
        batch.LastError = failureCount > 0
            ? $"{failureCount} variant(s) failed Square sync."
            : null;
        batch.CompletedAt = batch.Status is "COMPLETE" or "DRAFT_READY" ? DateTimeOffset.UtcNow : null;
        batch.UpdatedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(cancellationToken);

        var message = batch.Status switch
        {
            "COMPLETE" => $"Created {refreshedVariants.Count} variant(s). Square synced {successCount}.",
            "DRAFT_READY" => $"Created {refreshedVariants.Count} draft variant(s). Square sync was skipped.",
            "PARTIAL_FAILURE" => $"Created {refreshedVariants.Count} variant(s). Square synced {successCount}; {failureCount} failed and can be retried.",
            _ => $"Created {refreshedVariants.Count} variant(s). {pendingCount} still need follow-up."
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

    private static NebulaBatchVariantSummary MapBatchVariant(NebulaCreationBatchVariantEntity entity)
        => new(
            entity.Id,
            entity.SellableProductId,
            entity.ProductName,
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
                var name = parts.Length > 1 && !string.IsNullOrWhiteSpace(parts[1])
                    ? parts[1].Trim()
                    : FriendlyOptionName(code);
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
    string PermanentSku,
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

public sealed record SkuConflictRow(
    string Sku,
    string Message,
    IReadOnlyList<string> Details);

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
