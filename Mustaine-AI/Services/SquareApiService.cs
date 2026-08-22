// Integration service for Square POS and payments platform
// Handles API communication with Square, syncing of sales data, team members, and catalog items
// Supports both Sandbox and Production Square environments

using System.ComponentModel.DataAnnotations;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using MustaineAI.Data;
using Microsoft.Extensions.Options;
using Microsoft.EntityFrameworkCore;

namespace MustaineAI.Services;

/// <summary>
/// Defines operations for integrating with the Square POS and payments API.
/// </summary>
public interface ISquareApiService
{
    /// <summary>Gets the current Square connection settings (credentials, environment, etc.).</summary>
    Task<SquareConnectionSettings> GetConnectionSettingsAsync(CancellationToken cancellationToken = default);

    /// <summary>Saves Square API credentials and settings to the database.</summary>
    Task SaveConnectionSettingsAsync(
        SquareConnectionSettings settings,
        CancellationToken cancellationToken = default);

    /// <summary>Tests connectivity to Square API and retrieves available locations.</summary>
    Task<SquareConnectionResult> TestConnectionAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>Retrieves all business locations from Square.</summary>
    Task<IReadOnlyList<SquareLocation>> GetLocationsAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>Retrieves all product categories from the Square catalog.</summary>
    Task<IReadOnlyList<SquareCatalogCategory>> GetCatalogCategoriesAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>Retrieves all sellable items from the Square catalog.</summary>
    Task<IReadOnlyList<SquareCatalogItem>> GetCatalogItemsAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>Retrieves a customer from Square by customer ID for show-order fulfillment.</summary>
    Task<SquareCustomer?> GetCustomerAsync(
        string customerId,
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>
    /// Syncs sales orders and team member data from Square into the local database.
    /// Fetches all orders within a date range for all locations and associates them with team members if possible.
    /// </summary>
    Task<SquareSyncResult> SyncSalesAndTeamMembersAsync(
        SquareConnectionSettings? settings = null,
        DateTimeOffset? beginTime = null,
        CancellationToken cancellationToken = default);

    /// <summary>
    /// Syncs a sellable product's SKU and pricing information to Square catalog as a new item/variation.
    /// </summary>
    Task<SquareCatalogSyncResult> SyncSellableProductSkuAsync(
        Guid sellableProductId,
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);

    /// <summary>
    /// Deletes all catalog objects in Square for the connected account.
    /// </summary>
    Task<SquareCatalogClearResult> ClearCatalogAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default);
}

/// <summary>
/// Sealed implementation of ISquareApiService. Handles all HTTP communication with Square APIs.
/// Uses dependency injection for HttpClientFactory, database context, and logging.
/// </summary>
public sealed class SquareApiService : ISquareApiService
{
    /// <summary>JSON serialization options configured for web API defaults (camelCase, etc.).</summary>
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    
    /// <summary>Factory for creating configured HTTP clients.</summary>
    private readonly IHttpClientFactory _httpClientFactory;
    
    /// <summary>Database context for persisting synced data and reading configuration.</summary>
    private readonly ApplicationDbContext _dbContext;
    
    /// <summary>Default Square connection settings from configuration (appsettings.json).</summary>
    private readonly IOptions<SquareConnectionSettings> _defaultSettings;
    
    /// <summary>Logger for diagnostic messages and warnings.</summary>
    private readonly ILogger<SquareApiService> _logger;

    public SquareApiService(
        IHttpClientFactory httpClientFactory,
        ApplicationDbContext dbContext,
        IOptions<SquareConnectionSettings> defaultSettings,
        ILogger<SquareApiService> logger)
    {
        _httpClientFactory = httpClientFactory;
        _dbContext = dbContext;
        _defaultSettings = defaultSettings;
        _logger = logger;
    }

    /// <summary>Retrieves one Square customer for fulfillment display.</summary>
    public async Task<SquareCustomer?> GetCustomerAsync(
        string customerId,
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(customerId)) return null;

        var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
        if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            throw new InvalidOperationException("A Square access token is required.");

        var request = new HttpRequestMessage(HttpMethod.Get, $"v2/customers/{Uri.EscapeDataString(customerId)}");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);
        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);

        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));
        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) throw CreateSquareApiException(response, payload);

        var decoded = JsonSerializer.Deserialize<SquareCustomerResponseDto>(payload, JsonOptions);
        var c = decoded?.Customer;
        if (c is null) return null;
        return new SquareCustomer(c.Id, c.GivenName, c.FamilyName, c.CompanyName, c.EmailAddress, c.PhoneNumber,
            c.Address is null ? null : new SquareCustomerAddress(c.Address.AddressLine1, c.Address.AddressLine2, c.Address.Locality, c.Address.AdministrativeDistrictLevel1, c.Address.PostalCode, c.Address.Country));
    }


    /// <summary>
    /// Tests if we can successfully connect to Square and retrieve data.
    /// Verifies API credentials by attempting to fetch locations.
    /// </summary>
    public async Task<SquareConnectionResult> TestConnectionAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            // Attempt to fetch locations as a connection test
            var locations = await GetLocationsAsync(settings, cancellationToken);

            return new SquareConnectionResult(
                true,
                locations.Count == 0
                    ? "Connected to Square, but no locations were returned."
                    : $"Connected to Square. Found {locations.Count} location(s).",
                locations);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to connect to Square.");
            return new SquareConnectionResult(false, exception.Message, []);
        }
    }

    /// <summary>
    /// Retrieves all locations/businesses from the Square account.
    /// Required before syncing sales data (orders are associated with specific locations).
    /// </summary>
    public async Task<IReadOnlyList<SquareLocation>> GetLocationsAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        // Resolve settings - use provided settings or fall back to database/configuration
        var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
        if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
        {
            throw new InvalidOperationException("A Square access token is required.");
        }

        // Build HTTP request to Square v2 Locations API
        var request = new HttpRequestMessage(HttpMethod.Get, "v2/locations");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

        // Optional: add API version header to ensure consistent behavior
        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
        {
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
        }

        // Send request to Square
        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);

        // Handle API errors
        if (!response.IsSuccessStatusCode)
        {
            throw CreateSquareApiException(response, payload);
        }

        // Parse and return locations from response
        var locationsResponse = JsonSerializer.Deserialize<SquareLocationsResponse>(payload, JsonOptions);
        return locationsResponse?.Locations ?? [];
    }

    /// <summary>
    /// Retrieves all product categories from the Square catalog.
    /// </summary>
    public async Task<IReadOnlyList<SquareCatalogCategory>> GetCatalogCategoriesAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
            if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            {
                return [];  // Return empty if no credentials
            }

            // Fetch all catalog objects, then filter for CATEGORY type
            var objects = await ListCatalogObjectsAsync(resolvedSettings, "CATEGORY", cancellationToken);
            return objects
                .Where(candidate => string.Equals(candidate.Type, "CATEGORY", StringComparison.OrdinalIgnoreCase))
                .Select(candidate => new SquareCatalogCategory(
                    candidate.Id,
                    candidate.CategoryData?.Name ?? "Untitled category"))
                .OrderBy(category => category.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to load Square catalog categories.");
            return [];  // Return empty on error
        }
    }

    /// <summary>
    /// Retrieves all sellable items from the Square catalog.
    /// </summary>
    public async Task<IReadOnlyList<SquareCatalogItem>> GetCatalogItemsAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
            if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            {
                return [];
            }

            var objects = await ListCatalogObjectsAsync(resolvedSettings, "ITEM", cancellationToken);
            return objects
                .Where(candidate => string.Equals(candidate.Type, "ITEM", StringComparison.OrdinalIgnoreCase))
                .Select(candidate => new SquareCatalogItem(
                    candidate.Id,
                    candidate.ItemData?.Name ?? "Untitled item",
                    candidate.ItemData?.ReportingCategory?.Id ?? candidate.ItemData?.Categories?.FirstOrDefault()?.Id ?? candidate.ItemData?.CategoryId,
                    (candidate.ItemData?.Variations ?? [])
                        .Where(variation => string.Equals(variation.Type, "ITEM_VARIATION", StringComparison.OrdinalIgnoreCase))
                        .Select(variation => new SquareCatalogVariation(
                            variation.Id,
                            variation.ItemVariationData?.Name ?? "Regular",
                            variation.ItemVariationData?.Sku,
                            variation.ItemVariationData?.PriceMoney?.Amount))
                        .OrderBy(variation => variation.Name, StringComparer.OrdinalIgnoreCase)
                        .ToList()))
                .OrderBy(item => item.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to load Square catalog items.");
            return [];
        }
    }

    public async Task<SquareSyncResult> SyncSalesAndTeamMembersAsync(
        SquareConnectionSettings? settings = null,
        DateTimeOffset? beginTime = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
            if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            {
                return new SquareSyncResult(false, "A Square access token is required.", 0, 0, 0, 0);
            }

            var syncStartedAt = DateTimeOffset.UtcNow;
            var effectiveBeginTime = beginTime ?? syncStartedAt.AddDays(-30);
            var locations = await GetLocationsAsync(resolvedSettings, cancellationToken);
            var locationIds = locations
                .Select(location => location.Id)
                .Where(locationId => !string.IsNullOrWhiteSpace(locationId))
                .Select(locationId => locationId!)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToArray();

            var teamMembers = await GetTeamMembersAsync(resolvedSettings, cancellationToken);
            var teamMemberEntities = teamMembers
                .Where(member => !string.IsNullOrWhiteSpace(member.Id))
                .Select(member => MapTeamMember(member, syncStartedAt))
                .ToList();

            var orders = await SearchOrdersAsync(
                resolvedSettings,
                locationIds,
                effectiveBeginTime,
                syncStartedAt,
                cancellationToken);
            var paymentsById = await GetPaymentsByIdAsync(
                resolvedSettings,
                orders.SelectMany(GetOrderPaymentIds).Distinct(StringComparer.OrdinalIgnoreCase).ToArray(),
                cancellationToken);
            var lineItemEntities = await BuildLineItemsAsync(resolvedSettings, orders, syncStartedAt, cancellationToken);

            var saleEntities = orders
                .Where(order => !string.IsNullOrWhiteSpace(order.Id))
                .Select(order => MapSale(order, paymentsById, teamMemberEntities, syncStartedAt))
                .ToList();

            await UpsertTeamMembersAsync(teamMemberEntities, cancellationToken);
            await DeleteSyncedSalesWindowAsync(effectiveBeginTime, syncStartedAt, locationIds, cancellationToken);
            await UpsertSalesAsync(saleEntities, cancellationToken);
            await UpsertLineItemsAsync(lineItemEntities, cancellationToken);

            await _dbContext.SaveChangesAsync(cancellationToken);

            var importedTeamMembers = teamMemberEntities.Count;
            var importedSales = saleEntities.Count;
            var importedLineItems = lineItemEntities.Count;
            var assignedSales = saleEntities.Count(sale => !string.IsNullOrWhiteSpace(sale.TeamMemberId));
            var returnedRange = saleEntities.Count == 0
                ? "No orders were returned for that window."
                : $"Returned orders from {saleEntities.Min(sale => sale.CreatedAt).ToLocalTime():g} through {saleEntities.Max(sale => sale.CreatedAt).ToLocalTime():g}.";
            var message = $"Synced {importedTeamMembers} team member(s), {importedSales} sale(s), and {importedLineItems} line item(s) from {locations.Count} location(s). Assigned {assignedSales} sale(s) to team members and left {importedSales - assignedSales} unassigned. Requested orders from {effectiveBeginTime.ToLocalTime():g} through {syncStartedAt.ToLocalTime():g}. {returnedRange}";

            return new SquareSyncResult(true, message, importedTeamMembers, importedSales, importedLineItems, locations.Count);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to sync Square team members and sales.");
            return new SquareSyncResult(false, exception.Message, 0, 0, 0, 0);
        }
    }

    public async Task<SquareCatalogSyncResult> SyncSellableProductSkuAsync(
        Guid sellableProductId,
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
            if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            {
                return new SquareCatalogSyncResult(false, "A Square access token is required.", null, null);
            }

            var product = await _dbContext.SellableProducts
                .SingleOrDefaultAsync(product => product.Id == sellableProductId, cancellationToken);

            if (product is null)
            {
                return new SquareCatalogSyncResult(false, "Sellable product was not found.", null, null);
            }

            if (!string.IsNullOrWhiteSpace(product.SquareCatalogItemId) &&
                !string.IsNullOrWhiteSpace(product.SquareCatalogVariationId))
            {
                return new SquareCatalogSyncResult(
                    true,
                    $"Product {product.Identifier} is already linked to Square catalog item {product.SquareCatalogItemId}.",
                    product.SquareCatalogItemId,
                    product.SquareCatalogVariationId);
            }

            var squareCategoryName = await _dbContext.SellableProductElements
                .AsNoTracking()
                .Where(element => element.SellableProductId == product.Id && element.HasImage)
                .OrderBy(element => element.SortOrder)
                .Select(element => element.CategoryName)
                .FirstOrDefaultAsync(cancellationToken);

            if (string.IsNullOrWhiteSpace(squareCategoryName))
            {
                return new SquareCatalogSyncResult(false, "Link an image element before syncing. The image element defines the Square category.", null, null);
            }

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));
            var squareCategoryId = product.SquareCategoryId;
            if (string.IsNullOrWhiteSpace(squareCategoryId))
            {
                squareCategoryId = await UpsertSquareCategoryAsync(product, squareCategoryName, resolvedSettings, client, cancellationToken);
            }

            var clientItemId = $"#item-{product.Id:N}";
            var clientVariationId = $"#variation-{product.Id:N}";
            var request = new HttpRequestMessage(HttpMethod.Post, "v2/catalog/object");
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            request.Content = new StringContent(
                JsonSerializer.Serialize(new SquareUpsertCatalogObjectRequest
                {
                    IdempotencyKey = $"sellable-product-{product.Id:N}-{product.UpdatedAt.UtcDateTime:yyyyMMddHHmmss}",
                    Object = new SquareCatalogUpsertObject
                    {
                        Type = "ITEM",
                        Id = clientItemId,
                        PresentAtAllLocations = true,
                        ItemData = new SquareCatalogItemUpsertData
                        {
                            Name = product.Name,
                            Description = product.Notes,
                            CategoryId = squareCategoryId,
                            Variations =
                            [
                                new SquareCatalogUpsertObject
                                {
                                    Type = "ITEM_VARIATION",
                                    Id = clientVariationId,
                                    PresentAtAllLocations = true,
                                    ItemVariationData = new SquareCatalogItemVariationUpsertData
                                    {
                                        ItemId = clientItemId,
                                        Name = "Regular",
                                        Sku = product.SquareSku ?? product.Identifier,
                                        PricingType = "FIXED_PRICING",
                                        PriceMoney = new SquareMoneyDto
                                        {
                                            Amount = product.PriceCents,
                                            Currency = product.Currency,
                                        },
                                    },
                                },
                            ],
                        },
                    },
                }, JsonOptions),
                Encoding.UTF8,
                "application/json");

            using var response = await client.SendAsync(request, cancellationToken);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                throw CreateSquareApiException(response, payload);
            }

            var decoded = JsonSerializer.Deserialize<SquareUpsertCatalogObjectResponse>(payload, JsonOptions);
            var itemId = ResolveSquareCatalogId(decoded, clientItemId) ?? decoded?.CatalogObject?.Id;
            var variationId = ResolveSquareCatalogId(decoded, clientVariationId)
                ?? decoded?.CatalogObject?.ItemData?.Variations?.FirstOrDefault()?.Id;

            if (string.IsNullOrWhiteSpace(itemId) || string.IsNullOrWhiteSpace(variationId))
            {
                return new SquareCatalogSyncResult(false, "Square synced the product but did not return the expected catalog IDs.", itemId, variationId);
            }

            product.SquareCategoryId = squareCategoryId;
            product.SquareCategoryName = squareCategoryName;
            product.SquareCatalogItemId = itemId;
            product.SquareCatalogVariationId = variationId;
            product.SquareSyncedAt = DateTimeOffset.UtcNow;
            product.UpdatedAt = DateTimeOffset.UtcNow;

            await _dbContext.SaveChangesAsync(cancellationToken);

            return new SquareCatalogSyncResult(true, $"Synced SKU {product.SquareSku ?? product.Identifier} to Square catalog.", itemId, variationId);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to sync sellable product SKU to Square.");
            return new SquareCatalogSyncResult(false, exception.Message, null, null);
        }
    }

    public async Task<SquareCatalogClearResult> ClearCatalogAsync(
        SquareConnectionSettings? settings = null,
        CancellationToken cancellationToken = default)
    {
        try
        {
            var resolvedSettings = await ResolveSettingsAsync(settings, cancellationToken);
            if (string.IsNullOrWhiteSpace(resolvedSettings.AccessToken))
            {
                return new SquareCatalogClearResult(false, "A Square access token is required.", 0, 0);
            }

            var objects = await ListCatalogObjectsAsync(resolvedSettings, objectType: null, cancellationToken);
            var objectIds = objects
                .Select(candidate => candidate.Id)
                .Where(candidate => !string.IsNullOrWhiteSpace(candidate))
                .Select(candidate => candidate!)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToList();

            if (objectIds.Count == 0)
            {
                return new SquareCatalogClearResult(true, "Square catalog is already empty.", 0, 0);
            }

            const int batchSize = 100;
            var deletedCount = 0;
            var batchesExecuted = 0;

            for (var index = 0; index < objectIds.Count; index += batchSize)
            {
                var batchIds = objectIds
                    .Skip(index)
                    .Take(batchSize)
                    .ToArray();

                if (batchIds.Length == 0)
                {
                    continue;
                }

                var request = new HttpRequestMessage(HttpMethod.Post, "v2/catalog/batch-delete");
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

                if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
                {
                    request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
                }

                request.Content = new StringContent(
                    JsonSerializer.Serialize(new SquareBatchDeleteCatalogObjectsRequest
                    {
                        ObjectIds = batchIds,
                    }, JsonOptions),
                    Encoding.UTF8,
                    "application/json");

                var client = _httpClientFactory.CreateClient();
                client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

                using var response = await client.SendAsync(request, cancellationToken);
                var payload = await response.Content.ReadAsStringAsync(cancellationToken);

                if (!response.IsSuccessStatusCode)
                {
                    throw CreateSquareApiException(response, payload);
                }

                deletedCount += batchIds.Length;
                batchesExecuted += 1;
            }

            return new SquareCatalogClearResult(
                true,
                $"Deleted {deletedCount} catalog object(s) from Square in {batchesExecuted} batch request(s).",
                deletedCount,
                batchesExecuted);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(exception, "Failed to clear Square catalog.");
            return new SquareCatalogClearResult(false, exception.Message, 0, 0);
        }
    }

    private async Task<string> UpsertSquareCategoryAsync(
        SellableProductEntity product,
        string squareCategoryName,
        SquareConnectionSettings resolvedSettings,
        HttpClient client,
        CancellationToken cancellationToken)
    {
        var clientCategoryId = $"#category-{product.Id:N}";
        var request = new HttpRequestMessage(HttpMethod.Post, "v2/catalog/object");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
        {
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
        }

        request.Content = new StringContent(
            JsonSerializer.Serialize(new SquareUpsertCatalogObjectRequest
            {
                IdempotencyKey = $"sellable-product-category-{product.Id:N}-{product.UpdatedAt.UtcDateTime:yyyyMMddHHmmss}",
                Object = new SquareCatalogUpsertObject
                {
                    Type = "CATEGORY",
                    Id = clientCategoryId,
                    PresentAtAllLocations = true,
                    CategoryData = new SquareCatalogCategoryUpsertData
                    {
                        Name = squareCategoryName,
                    },
                },
            }, JsonOptions),
            Encoding.UTF8,
            "application/json");

        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);

        if (!response.IsSuccessStatusCode)
        {
            throw CreateSquareApiException(response, payload);
        }

        var decoded = JsonSerializer.Deserialize<SquareUpsertCatalogObjectResponse>(payload, JsonOptions);
        var categoryId = ResolveSquareCatalogId(decoded, clientCategoryId) ?? decoded?.CatalogObject?.Id;

        if (string.IsNullOrWhiteSpace(categoryId))
        {
            throw new InvalidOperationException("Square synced the category but did not return a catalog category ID.");
        }

        return categoryId;
    }

    public async Task<SquareConnectionSettings> GetConnectionSettingsAsync(CancellationToken cancellationToken = default)
    {
        var savedSettings = await GetSavedConnectionSettingsAsync(cancellationToken);
        return ResolveSettings(savedSettings);
    }

    public async Task SaveConnectionSettingsAsync(
        SquareConnectionSettings settings,
        CancellationToken cancellationToken = default)
    {
        await _dbContext.Database.MigrateAsync(cancellationToken);

        var resolvedSettings = ResolveSettings(settings);
        var savedSettings = await _dbContext.SquareConnectionSettings
            .SingleOrDefaultAsync(setting => setting.Id == SquareConnectionSettingsEntity.DefaultId, cancellationToken);

        if (savedSettings is null)
        {
            savedSettings = new SquareConnectionSettingsEntity
            {
                Id = SquareConnectionSettingsEntity.DefaultId,
            };
            _dbContext.SquareConnectionSettings.Add(savedSettings);
        }

        savedSettings.Environment = resolvedSettings.Environment;
        savedSettings.ApplicationId = resolvedSettings.ApplicationId;
        savedSettings.AccessToken = resolvedSettings.AccessToken;
        savedSettings.ApiVersion = resolvedSettings.ApiVersion;
        savedSettings.UpdatedAt = DateTimeOffset.UtcNow;

        await _dbContext.SaveChangesAsync(cancellationToken);
    }

    private async Task<SquareConnectionSettings> ResolveSettingsAsync(
        SquareConnectionSettings? settings,
        CancellationToken cancellationToken)
    {
        var savedSettings = await GetSavedConnectionSettingsAsync(cancellationToken);
        return ResolveSettings(settings, savedSettings);
    }

    private async Task<SquareConnectionSettings?> GetSavedConnectionSettingsAsync(CancellationToken cancellationToken)
    {
        try
        {
            var savedSettings = await _dbContext.SquareConnectionSettings
                .AsNoTracking()
                .SingleOrDefaultAsync(setting => setting.Id == SquareConnectionSettingsEntity.DefaultId, cancellationToken);

            if (savedSettings is null)
            {
                return null;
            }

            return new SquareConnectionSettings
            {
                Environment = savedSettings.Environment,
                ApplicationId = savedSettings.ApplicationId,
                AccessToken = savedSettings.AccessToken,
                ApiVersion = savedSettings.ApiVersion,
            };
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _logger.LogDebug(exception, "Failed to load saved Square connection settings. Falling back to configured defaults.");
            return null;
        }
    }

    private SquareConnectionSettings ResolveSettings(
        SquareConnectionSettings? settings,
        SquareConnectionSettings? savedSettings = null)
    {
        var defaults = _defaultSettings.Value;
        return new SquareConnectionSettings
        {
            ApplicationId = FirstConfigured(settings?.ApplicationId, savedSettings?.ApplicationId, defaults.ApplicationId),
            AccessToken = FirstConfigured(settings?.AccessToken, savedSettings?.AccessToken, defaults.AccessToken),
            ApiVersion = FirstConfigured(settings?.ApiVersion, savedSettings?.ApiVersion, defaults.ApiVersion),
            Environment = FirstConfigured(settings?.Environment, savedSettings?.Environment, defaults.Environment) ?? SquareEnvironments.Sandbox,
        };
    }

    private static string? FirstConfigured(params string?[] values) =>
        values.FirstOrDefault(value => !string.IsNullOrWhiteSpace(value));

    private static string? ResolveSquareCatalogId(SquareUpsertCatalogObjectResponse? response, string clientObjectId) =>
        response?.IdMappings?
            .FirstOrDefault(mapping => string.Equals(mapping.ClientObjectId, clientObjectId, StringComparison.OrdinalIgnoreCase))
            ?.ObjectId;

    private async Task<IReadOnlyList<SquareCatalogObjectDto>> ListCatalogObjectsAsync(
        SquareConnectionSettings resolvedSettings,
        string? objectType,
        CancellationToken cancellationToken)
    {
        var objects = new List<SquareCatalogObjectDto>();
        string? cursor = null;

        do
        {
            var builder = new StringBuilder("v2/catalog/list");
            var hasQuery = false;

            if (!string.IsNullOrWhiteSpace(objectType))
            {
                builder.Append('?');
                builder.Append("types=");
                builder.Append(Uri.EscapeDataString(objectType));
                hasQuery = true;
            }

            if (!string.IsNullOrWhiteSpace(cursor))
            {
                builder.Append(hasQuery ? "&cursor=" : "?cursor=");
                builder.Append(Uri.EscapeDataString(cursor));
            }

            var request = new HttpRequestMessage(HttpMethod.Get, builder.ToString());
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

            using var response = await client.SendAsync(request, cancellationToken);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                throw CreateSquareApiException(response, payload);
            }

            var decoded = JsonSerializer.Deserialize<SquareCatalogListResponse>(payload, JsonOptions);
            if (decoded?.Objects is { Count: > 0 } pageObjects)
            {
                objects.AddRange(pageObjects);
            }

            cursor = decoded?.Cursor;
        }
        while (!string.IsNullOrWhiteSpace(cursor));

        return objects;
    }

    private async Task<IReadOnlyList<SquareTeamMemberDto>> GetTeamMembersAsync(
        SquareConnectionSettings resolvedSettings,
        CancellationToken cancellationToken)
    {
        var members = new List<SquareTeamMemberDto>();
        string? cursor = null;

        do
        {
            var request = new HttpRequestMessage(HttpMethod.Post, "v2/team-members/search");
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            var payload = JsonSerializer.Serialize(new SquareTeamMemberSearchRequest
            {
                Query = new SquareTeamMemberQuery(),
                Limit = 200,
                Cursor = cursor,
            }, JsonOptions);

            request.Content = new StringContent(payload, Encoding.UTF8, "application/json");

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

            using var response = await client.SendAsync(request, cancellationToken);
            var responsePayload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                throw CreateSquareApiException(response, responsePayload);
            }

            var decoded = JsonSerializer.Deserialize<SquareTeamMembersResponse>(responsePayload, JsonOptions);
            if (decoded?.TeamMembers is { Count: > 0 } pageMembers)
            {
                members.AddRange(pageMembers);
            }

            cursor = decoded?.Cursor;
        }
        while (!string.IsNullOrWhiteSpace(cursor));

        return members;
    }

    private async Task<IReadOnlyList<SquarePaymentDto>> GetPaymentsAsync(
        SquareConnectionSettings resolvedSettings,
        string locationId,
        DateTimeOffset beginTime,
        DateTimeOffset endTime,
        CancellationToken cancellationToken)
    {
        var payments = new List<SquarePaymentDto>();
        string? cursor = null;

        do
        {
            var requestUri = BuildPaymentsUri(
                GetBaseUrl(resolvedSettings.Environment),
                locationId,
                beginTime,
                endTime,
                cursor,
                100);

            var request = new HttpRequestMessage(HttpMethod.Get, requestUri);
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

            using var response = await client.SendAsync(request, cancellationToken);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                throw CreateSquareApiException(response, payload);
            }

            var decoded = JsonSerializer.Deserialize<SquarePaymentsResponse>(payload, JsonOptions);
            if (decoded?.Payments is { Count: > 0 } pagePayments)
            {
                payments.AddRange(pagePayments);
            }

            cursor = decoded?.Cursor;
        }
        while (!string.IsNullOrWhiteSpace(cursor));

        return payments;
    }

    private async Task<IReadOnlyDictionary<string, SquarePaymentDto>> GetPaymentsByIdAsync(
        SquareConnectionSettings resolvedSettings,
        IReadOnlyList<string> paymentIds,
        CancellationToken cancellationToken)
    {
        var payments = new Dictionary<string, SquarePaymentDto>(StringComparer.OrdinalIgnoreCase);

        foreach (var paymentId in paymentIds)
        {
            var request = new HttpRequestMessage(HttpMethod.Get, $"v2/payments/{Uri.EscapeDataString(paymentId)}");
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

            using var response = await client.SendAsync(request, cancellationToken);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                _logger.LogDebug("Failed to retrieve Square payment {PaymentId}. {Payload}", paymentId, payload);
                continue;
            }

            var decoded = JsonSerializer.Deserialize<SquarePaymentResponse>(payload, JsonOptions);
            if (decoded?.Payment?.Id is { Length: > 0 } id)
            {
                payments[id] = decoded.Payment;
            }
        }

        return payments;
    }

    private async Task<IReadOnlyList<SquareOrderDto>> GetOrdersAsync(
        SquareConnectionSettings resolvedSettings,
        IReadOnlyList<string> orderIds,
        CancellationToken cancellationToken)
    {
        if (orderIds.Count == 0)
        {
            return [];
        }

        var request = new HttpRequestMessage(HttpMethod.Post, "v2/orders/batch-retrieve");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
        {
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
        }

        request.Content = new StringContent(
            JsonSerializer.Serialize(new SquareBatchRetrieveOrdersRequest
            {
                OrderIds = orderIds.ToArray(),
            }, JsonOptions),
            Encoding.UTF8,
            "application/json");

        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);

        if (!response.IsSuccessStatusCode)
        {
            throw CreateSquareApiException(response, payload);
        }

        var decoded = JsonSerializer.Deserialize<SquareOrdersResponse>(payload, JsonOptions);
        return decoded?.Orders ?? [];
    }

    private async Task<IReadOnlyList<SquareOrderDto>> SearchOrdersAsync(
        SquareConnectionSettings resolvedSettings,
        IReadOnlyList<string> locationIds,
        DateTimeOffset beginTime,
        DateTimeOffset endTime,
        CancellationToken cancellationToken)
    {
        var orders = new List<SquareOrderDto>();
        string? cursor = null;

        do
        {
            var request = new HttpRequestMessage(HttpMethod.Post, "v2/orders/search");
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

            if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
            {
                request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
            }

            request.Content = new StringContent(
                JsonSerializer.Serialize(new SquareSearchOrdersRequest
                {
                    LocationIds = locationIds.ToArray(),
                    Cursor = cursor,
                    Limit = 500,
                    Query = new SquareSearchOrdersQuery
                    {
                        Filter = new SquareSearchOrdersFilter
                        {
                            DateTimeFilter = new SquareSearchOrdersDateTimeFilter
                            {
                                CreatedAt = new SquareSearchOrdersTimeRange
                                {
                                    StartAt = beginTime.ToUniversalTime().ToString("O"),
                                    EndAt = endTime.ToUniversalTime().ToString("O"),
                                },
                            },
                            StateFilter = new SquareSearchOrdersStateFilter
                            {
                                States = ["COMPLETED"],
                            },
                        },
                        Sort = new SquareSearchOrdersSort
                        {
                            SortField = "CREATED_AT",
                            SortOrder = "ASC",
                        },
                    },
                }, JsonOptions),
                Encoding.UTF8,
                "application/json");

            var client = _httpClientFactory.CreateClient();
            client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

            using var response = await client.SendAsync(request, cancellationToken);
            var payload = await response.Content.ReadAsStringAsync(cancellationToken);

            if (!response.IsSuccessStatusCode)
            {
                throw CreateSquareApiException(response, payload);
            }

            var decoded = JsonSerializer.Deserialize<SquareOrdersResponse>(payload, JsonOptions);
            if (decoded?.Orders is { Count: > 0 } pageOrders)
            {
                orders.AddRange(pageOrders);
            }

            cursor = decoded?.Cursor;
        }
        while (!string.IsNullOrWhiteSpace(cursor));

        return orders;
    }

    private async Task<IReadOnlyList<SquareSaleLineItemEntity>> BuildLineItemsAsync(
        SquareConnectionSettings resolvedSettings,
        IReadOnlyList<SquareOrderDto> orders,
        DateTimeOffset syncedAt,
        CancellationToken cancellationToken)
    {
        var catalogCache = new Dictionary<string, SquareCatalogItemLookup>();
        var categoryCache = new Dictionary<string, string?>();
        var lineItems = new List<SquareSaleLineItemEntity>();

        foreach (var order in orders.OrderBy(order => order.CreatedAt ?? syncedAt))
        {
            if (string.IsNullOrWhiteSpace(order.Id) || order.LineItems is not { Count: > 0 } orderLineItems)
            {
                continue;
            }

            for (var index = 0; index < orderLineItems.Count; index++)
            {
                var lineItem = orderLineItems[index];
                var lookup = await ResolveCatalogLookupAsync(resolvedSettings, lineItem, catalogCache, categoryCache, cancellationToken);
                var quantity = decimal.TryParse(lineItem.Quantity, System.Globalization.NumberStyles.Number, System.Globalization.CultureInfo.InvariantCulture, out var parsedQuantity)
                    ? parsedQuantity
                    : 0m;

                lineItems.Add(new SquareSaleLineItemEntity
                {
                    SquareOrderId = order.Id,
                    LineItemUid = lineItem.Uid ?? $"{order.Id}-{index}",
                    LineItemName = lineItem.Name,
                    VariationName = lineItem.VariationName,
                    CatalogObjectId = lineItem.CatalogObjectId,
                    ReportingCategoryId = lookup.ReportingCategoryId,
                    ReportingCategoryName = lookup.ReportingCategoryName,
                    Note = lineItem.Note,
                    ModifiersJson = JsonSerializer.Serialize(lineItem.Modifiers ?? []),
                    Quantity = quantity,
                    GrossAmountCents = GetLineItemGrossBeforeTaxCents(lineItem, quantity),
                    SortOrder = index,
                    SaleCreatedAt = order.CreatedAt ?? syncedAt,
                    SyncedAt = syncedAt,
                });
            }
        }

        return lineItems;
    }

    private async Task<SquareCatalogItemLookup> ResolveCatalogLookupAsync(
        SquareConnectionSettings resolvedSettings,
        SquareOrderLineItemDto lineItem,
        IDictionary<string, SquareCatalogItemLookup> catalogCache,
        IDictionary<string, string?> categoryCache,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(lineItem.CatalogObjectId))
        {
            return SquareCatalogItemLookup.Empty;
        }

        if (catalogCache.TryGetValue(lineItem.CatalogObjectId, out var cached))
        {
            return cached;
        }

        var catalogObject = await GetCatalogObjectAsync(resolvedSettings, lineItem.CatalogObjectId, lineItem.CatalogVersion, cancellationToken);
        if (catalogObject?.Object?.ItemVariationData?.ItemId is null)
        {
            catalogCache[lineItem.CatalogObjectId] = SquareCatalogItemLookup.Empty;
            return SquareCatalogItemLookup.Empty;
        }

        var itemObject = catalogObject.RelatedObjects?.FirstOrDefault(item => string.Equals(item.Type, "ITEM", StringComparison.OrdinalIgnoreCase) && item.ItemData is not null);
        var categoryId = itemObject?.ItemData?.ReportingCategory?.Id;
        string? categoryName = null;

        if (!string.IsNullOrWhiteSpace(categoryId))
        {
            if (!categoryCache.TryGetValue(categoryId, out categoryName))
            {
                categoryName = await GetCatalogCategoryNameAsync(resolvedSettings, categoryId!, cancellationToken);
                categoryCache[categoryId!] = categoryName;
            }
        }

        var lookup = new SquareCatalogItemLookup(categoryId, categoryName);
        catalogCache[lineItem.CatalogObjectId] = lookup;
        return lookup;
    }

    private async Task<string?> GetCatalogCategoryNameAsync(
        SquareConnectionSettings resolvedSettings,
        string categoryId,
        CancellationToken cancellationToken)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, $"v2/catalog/object/{Uri.EscapeDataString(categoryId)}");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
        {
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
        }

        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);

        // Historical orders can point to reporting categories that were later deleted.
        // Missing category metadata should never abort a sales sync; keep the line item
        // and simply leave its reporting category name blank.
        if (response.StatusCode == System.Net.HttpStatusCode.NotFound)
        {
            return null;
        }

        if (!response.IsSuccessStatusCode)
        {
            throw CreateSquareApiException(response, payload);
        }

        var decoded = JsonSerializer.Deserialize<SquareCatalogObjectResponse>(payload, JsonOptions);
        return decoded?.Object?.CategoryData?.Name;
    }

    private async Task<SquareCatalogObjectResponse?> GetCatalogObjectAsync(
        SquareConnectionSettings resolvedSettings,
        string catalogObjectId,
        long? catalogVersion,
        CancellationToken cancellationToken)
    {
        var query = new Dictionary<string, string?>
        {
            ["include_related_objects"] = "true",
        };

        if (catalogVersion is not null)
        {
            query["catalog_version"] = catalogVersion.Value.ToString();
        }

        var builder = new StringBuilder($"v2/catalog/object/{Uri.EscapeDataString(catalogObjectId)}");
        builder.Append('?');
        var first = true;
        foreach (var parameter in query)
        {
            if (string.IsNullOrWhiteSpace(parameter.Value))
            {
                continue;
            }

            if (!first)
            {
                builder.Append('&');
            }

            builder.Append(Uri.EscapeDataString(parameter.Key));
            builder.Append('=');
            builder.Append(Uri.EscapeDataString(parameter.Value!));
            first = false;
        }

        var request = new HttpRequestMessage(HttpMethod.Get, builder.ToString());
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", resolvedSettings.AccessToken);

        if (!string.IsNullOrWhiteSpace(resolvedSettings.ApiVersion))
        {
            request.Headers.TryAddWithoutValidation("Square-Version", resolvedSettings.ApiVersion);
        }

        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(GetBaseUrl(resolvedSettings.Environment));

        using var response = await client.SendAsync(request, cancellationToken);
        var payload = await response.Content.ReadAsStringAsync(cancellationToken);

        // Historical Square orders can reference catalog objects that were later deleted.
        // A missing historical catalog object must not abort the entire sales sync;
        // the order line itself still contains the historical name/variation/amount data.
        if (response.StatusCode == System.Net.HttpStatusCode.NotFound)
        {
            return null;
        }

        if (!response.IsSuccessStatusCode)
        {
            throw CreateSquareApiException(response, payload);
        }

        return JsonSerializer.Deserialize<SquareCatalogObjectResponse>(payload, JsonOptions);
    }

    private async Task UpsertTeamMembersAsync(
        IReadOnlyList<SquareTeamMemberEntity> teamMemberEntities,
        CancellationToken cancellationToken)
    {
        if (teamMemberEntities.Count == 0)
        {
            return;
        }

        var ids = teamMemberEntities.Select(teamMember => teamMember.SquareTeamMemberId).ToArray();
        var existing = await _dbContext.SquareTeamMembers
            .Where(teamMember => ids.Contains(teamMember.SquareTeamMemberId))
            .ToDictionaryAsync(teamMember => teamMember.SquareTeamMemberId, cancellationToken);

        foreach (var teamMember in teamMemberEntities)
        {
            if (existing.TryGetValue(teamMember.SquareTeamMemberId, out var tracked))
            {
                _dbContext.Entry(tracked).CurrentValues.SetValues(teamMember);
            }
            else
            {
                _dbContext.SquareTeamMembers.Add(teamMember);
            }
        }
    }

    private async Task DeleteSyncedSalesWindowAsync(
        DateTimeOffset beginTime,
        DateTimeOffset endTime,
        IReadOnlyCollection<string> locationIds,
        CancellationToken cancellationToken)
    {
        await _dbContext.SquareSaleLineItems
            .Where(lineItem => lineItem.SaleCreatedAt >= beginTime && lineItem.SaleCreatedAt <= endTime)
            .ExecuteDeleteAsync(cancellationToken);

        await _dbContext.SquareSales
            .Where(sale =>
                sale.CreatedAt >= beginTime &&
                sale.CreatedAt <= endTime &&
                (sale.LocationId == null || locationIds.Contains(sale.LocationId)))
            .ExecuteDeleteAsync(cancellationToken);
    }

    private async Task UpsertLineItemsAsync(
        IReadOnlyList<SquareSaleLineItemEntity> lineItemEntities,
        CancellationToken cancellationToken)
    {
        if (lineItemEntities.Count == 0)
        {
            return;
        }

        var keys = lineItemEntities.Select(lineItem => new { lineItem.SquareOrderId, lineItem.LineItemUid }).ToArray();
        var orderIds = keys.Select(key => key.SquareOrderId).Distinct().ToArray();
        var existing = await _dbContext.SquareSaleLineItems
            .Where(lineItem => orderIds.Contains(lineItem.SquareOrderId))
            .ToListAsync(cancellationToken);

        foreach (var lineItem in lineItemEntities)
        {
            var tracked = existing.FirstOrDefault(existingLineItem =>
                existingLineItem.SquareOrderId == lineItem.SquareOrderId &&
                existingLineItem.LineItemUid == lineItem.LineItemUid);

            if (tracked is not null)
            {
                _dbContext.Entry(tracked).CurrentValues.SetValues(lineItem);
            }
            else
            {
                _dbContext.SquareSaleLineItems.Add(lineItem);
            }
        }
    }

    private async Task UpsertSalesAsync(
        IReadOnlyList<SquareSaleEntity> saleEntities,
        CancellationToken cancellationToken)
    {
        if (saleEntities.Count == 0)
        {
            return;
        }

        var ids = saleEntities.Select(sale => sale.SquarePaymentId).ToArray();
        var existing = await _dbContext.SquareSales
            .Where(sale => ids.Contains(sale.SquarePaymentId))
            .ToDictionaryAsync(sale => sale.SquarePaymentId, cancellationToken);

        foreach (var sale in saleEntities)
        {
            if (existing.TryGetValue(sale.SquarePaymentId, out var tracked))
            {
                _dbContext.Entry(tracked).CurrentValues.SetValues(sale);
            }
            else
            {
                _dbContext.SquareSales.Add(sale);
            }
        }
    }

    private static SquareTeamMemberEntity MapTeamMember(SquareTeamMemberDto member, DateTimeOffset syncedAt)
    {
        var displayName = string.Join(" ", new[] { member.GivenName, member.FamilyName }.Where(value => !string.IsNullOrWhiteSpace(value)));

        return new SquareTeamMemberEntity
        {
            SquareTeamMemberId = member.Id ?? string.Empty,
            GivenName = member.GivenName,
            FamilyName = member.FamilyName,
            DisplayName = string.IsNullOrWhiteSpace(displayName) ? member.Id : displayName,
            EmailAddress = member.EmailAddress,
            PhoneNumber = member.PhoneNumber,
            Status = member.Status,
            IsOwner = member.IsOwner,
            LocationAssignmentType = member.AssignedLocations?.AssignmentType,
            AssignedLocationIdsJson = JsonSerializer.Serialize(member.AssignedLocations?.LocationIds ?? []),
            SquareUpdatedAt = member.UpdatedAt,
            SyncedAt = syncedAt,
        };
    }

    private static SquareSaleEntity MapSale(
        SquarePaymentDto payment,
        IReadOnlyCollection<SquareTeamMemberEntity> teamMembers,
        DateTimeOffset syncedAt)
    {
        var teamMemberName = teamMembers.FirstOrDefault(member => string.Equals(member.SquareTeamMemberId, payment.TeamMemberId, StringComparison.OrdinalIgnoreCase))
            ?.DisplayName;
        var amountCents = GetPaymentAmountCents(payment);

        return new SquareSaleEntity
        {
            SquarePaymentId = payment.Id ?? string.Empty,
            LocationId = payment.LocationId,
            TeamMemberId = payment.TeamMemberId,
            TeamMemberName = teamMemberName,
            OrderId = payment.OrderId,
            Status = payment.Status,
            AmountCents = amountCents,
            TipCents = payment.TipMoney?.Amount ?? 0,
            OrderGrossBeforeTaxCents = amountCents,
            OrderTaxCents = 0,
            OrderDiscountCents = 0,
            OrderServiceChargeCents = 0,
            OrderAdjustmentCents = 0,
            OrderTotalCents = amountCents,
            Currency = payment.TotalMoney?.Currency ?? payment.AmountMoney?.Currency ?? payment.TipMoney?.Currency,
            ReceiptNumber = payment.ReceiptNumber,
            CreatedAt = payment.CreatedAt ?? syncedAt,
            UpdatedAt = payment.UpdatedAt,
            SyncedAt = syncedAt,
        };
    }

    private static SquareSaleEntity MapSale(
        SquareOrderDto order,
        IReadOnlyDictionary<string, SquarePaymentDto> paymentsById,
        IReadOnlyCollection<SquareTeamMemberEntity> teamMembers,
        DateTimeOffset syncedAt)
    {
        var tender = order.Tenders?
            .Where(tender => !string.IsNullOrWhiteSpace(tender.PaymentId) || !string.IsNullOrWhiteSpace(tender.Id))
            .OrderBy(tender => tender.CreatedAt ?? order.CreatedAt ?? syncedAt)
            .FirstOrDefault();
        var paymentId = FirstConfigured(tender?.PaymentId, order.PaymentId);
        var payment = paymentId is not null && paymentsById.TryGetValue(paymentId, out var foundPayment)
            ? foundPayment
            : null;
        var teamMemberId = FirstConfigured(payment?.TeamMemberId, tender?.TeamMemberId);
        var teamMemberName = teamMembers.FirstOrDefault(member => string.Equals(member.SquareTeamMemberId, teamMemberId, StringComparison.OrdinalIgnoreCase))
            ?.DisplayName;
        var tipCents = payment?.TipMoney?.Amount ?? order.Tenders?.Sum(tender => tender.TipMoney?.Amount ?? 0) ?? 0;
        var amountCents = payment is null ? GetOrderAmountCents(order, tipCents) : GetPaymentAmountCents(payment);
        var orderGrossBeforeTaxCents = GetOrderGrossBeforeTaxCents(order);
        var orderTaxCents = FirstMoneyAmount(order.NetAmounts?.TaxMoney, order.TotalTaxMoney);
        var orderDiscountCents = FirstMoneyAmount(order.NetAmounts?.DiscountMoney, order.TotalDiscountMoney);
        var orderServiceChargeCents = FirstMoneyAmount(order.NetAmounts?.ServiceChargeMoney, order.TotalServiceChargeMoney);
        var orderAdjustmentCents = orderTaxCents + orderServiceChargeCents - orderDiscountCents;

        if (orderAdjustmentCents == 0 && orderGrossBeforeTaxCents > 0)
        {
            orderAdjustmentCents = amountCents - orderGrossBeforeTaxCents;
        }

        return new SquareSaleEntity
        {
            SquarePaymentId = FirstConfigured(payment?.Id, tender?.PaymentId, order.PaymentId, tender?.Id, order.Id) ?? string.Empty,
            LocationId = order.LocationId,
            TeamMemberId = teamMemberId,
            TeamMemberName = teamMemberName,
            OrderId = order.Id,
            CustomerId = order.CustomerId,
            Status = order.State,
            AmountCents = amountCents,
            TipCents = tipCents,
            OrderGrossBeforeTaxCents = orderGrossBeforeTaxCents,
            OrderTaxCents = orderTaxCents,
            OrderDiscountCents = orderDiscountCents,
            OrderServiceChargeCents = orderServiceChargeCents,
            OrderAdjustmentCents = orderAdjustmentCents,
            OrderTotalCents = amountCents,
            Currency = payment?.TotalMoney?.Currency ?? payment?.AmountMoney?.Currency ?? order.NetAmounts?.TotalMoney?.Currency ?? order.TotalMoney?.Currency ?? tender?.AmountMoney?.Currency,
            ReceiptNumber = null,
            CreatedAt = payment?.CreatedAt ?? order.CreatedAt ?? tender?.CreatedAt ?? syncedAt,
            UpdatedAt = payment?.UpdatedAt ?? order.UpdatedAt,
            SyncedAt = syncedAt,
        };
    }

    private static long GetPaymentAmountCents(SquarePaymentDto payment)
    {
        if (payment.AmountMoney?.Amount is { } amount)
        {
            return amount;
        }

        var total = payment.TotalMoney?.Amount ?? 0;
        var tip = payment.TipMoney?.Amount ?? 0;

        return Math.Max(total - tip, 0);
    }

    private static long GetOrderAmountCents(SquareOrderDto order, long tipCents)
    {
        if (order.NetAmounts?.TotalMoney?.Amount is { } netTotal)
        {
            return Math.Max(netTotal - tipCents, 0);
        }

        if (order.TotalMoney?.Amount is { } total)
        {
            return Math.Max(total - tipCents, 0);
        }

        return order.LineItems?.Sum(lineItem => lineItem.TotalMoney?.Amount ?? lineItem.BasePriceMoney?.Amount ?? 0) ?? 0;
    }

    private static long GetOrderGrossBeforeTaxCents(SquareOrderDto order)
    {
        return order.LineItems?.Sum(lineItem => GetLineItemGrossBeforeTaxCents(lineItem)) ?? 0;
    }

    private static long GetLineItemGrossBeforeTaxCents(SquareOrderLineItemDto lineItem, decimal? parsedQuantity = null)
    {
        if (lineItem.GrossSalesMoney?.Amount is { } grossSales)
        {
            return grossSales;
        }

        if (lineItem.BasePriceMoney?.Amount is { } basePrice)
        {
            var quantity = parsedQuantity ?? (decimal.TryParse(
                lineItem.Quantity,
                System.Globalization.NumberStyles.Number,
                System.Globalization.CultureInfo.InvariantCulture,
                out var parsed)
                    ? parsed
                    : 1m);

            return (long)Math.Round(basePrice * quantity, MidpointRounding.AwayFromZero);
        }

        return lineItem.TotalMoney?.Amount ?? 0;
    }

    private static long FirstMoneyAmount(params SquareMoneyDto?[] moneyValues)
    {
        foreach (var money in moneyValues)
        {
            if (money?.Amount is { } amount)
            {
                return amount;
            }
        }

        return 0;
    }

    private static IEnumerable<string> GetOrderPaymentIds(SquareOrderDto order)
    {
        if (!string.IsNullOrWhiteSpace(order.PaymentId))
        {
            yield return order.PaymentId;
        }

        if (order.Tenders is null)
        {
            yield break;
        }

        foreach (var tender in order.Tenders)
        {
            if (!string.IsNullOrWhiteSpace(tender.PaymentId))
            {
                yield return tender.PaymentId;
            }
        }
    }

    private sealed record SquareCatalogItemLookup(string? ReportingCategoryId, string? ReportingCategoryName)
    {
        public static SquareCatalogItemLookup Empty { get; } = new(null, null);
    }

    private static string BuildPaymentsUri(
        string baseUrl,
        string locationId,
        DateTimeOffset beginTime,
        DateTimeOffset endTime,
        string? cursor,
        int limit)
    {
        var query = new Dictionary<string, string?>
        {
            ["begin_time"] = beginTime.ToUniversalTime().ToString("O"),
            ["end_time"] = endTime.ToUniversalTime().ToString("O"),
            ["location_id"] = locationId,
            ["limit"] = limit.ToString(),
        };

        if (!string.IsNullOrWhiteSpace(cursor))
        {
            query["cursor"] = cursor;
        }

        var builder = new StringBuilder($"{baseUrl.TrimEnd('/')}/v2/payments");
        builder.Append('?');

        var first = true;
        foreach (var parameter in query)
        {
            if (string.IsNullOrWhiteSpace(parameter.Value))
            {
                continue;
            }

            if (!first)
            {
                builder.Append('&');
            }

            builder.Append(Uri.EscapeDataString(parameter.Key));
            builder.Append('=');
            builder.Append(Uri.EscapeDataString(parameter.Value!));
            first = false;
        }

        return builder.ToString();
    }

    private static string GetBaseUrl(string environment) =>
        string.Equals(environment, SquareEnvironments.Production, StringComparison.OrdinalIgnoreCase)
            ? "https://connect.squareup.com/"
            : "https://connect.squareupsandbox.com/";

    private Exception CreateSquareApiException(HttpResponseMessage response, string payload)
    {
        var message = new StringBuilder()
            .Append("Square API request failed with status ")
            .Append((int)response.StatusCode)
            .Append(" (")
            .Append(response.ReasonPhrase)
            .Append(").")
            .ToString();

        if (string.IsNullOrWhiteSpace(payload))
        {
            return new InvalidOperationException(message);
        }

        try
        {
            var errorResponse = JsonSerializer.Deserialize<SquareErrorResponse>(payload, JsonOptions);
            var errorDetails = errorResponse?.Errors?
                .Where(error => !string.IsNullOrWhiteSpace(error.Detail))
                .Select(error => error.Detail!)
                .ToArray();

            if (errorDetails is { Length: > 0 })
            {
                return new InvalidOperationException($"{message} {string.Join(" ", errorDetails)}");
            }
        }
        catch
        {
            // Fall back to the raw payload below.
        }

        return new InvalidOperationException($"{message} {payload}");
    }
}

public sealed record SquareSyncResult(
    bool IsSuccessful,
    string Message,
    int TeamMembersImported,
    int SalesImported,
    int LineItemsImported,
    int LocationsImported);

public sealed record SquareCatalogSyncResult(
    bool IsSuccessful,
    string Message,
    string? SquareCatalogItemId,
    string? SquareCatalogVariationId);

public sealed record SquareCatalogClearResult(
    bool IsSuccessful,
    string Message,
    int DeletedObjectCount,
    int BatchRequestsExecuted);

public sealed class SquareConnectionSettings
{
    [Required]
    public string Environment { get; set; } = SquareEnvironments.Sandbox;

    public string? ApplicationId { get; set; }

    [Required]
    public string? AccessToken { get; set; }

    public string? ApiVersion { get; set; }
}

public static class SquareEnvironments
{
    public const string Sandbox = "Sandbox";
    public const string Production = "Production";
}

public sealed record SquareConnectionResult(
    bool IsConnected,
    string Message,
    IReadOnlyList<SquareLocation> Locations);

public sealed record SquareLocation(
    [property: JsonPropertyName("id")] string? Id,
    [property: JsonPropertyName("name")] string? Name,
    [property: JsonPropertyName("business_name")] string? BusinessName);

internal sealed class SquareLocationsResponse
{
    [JsonPropertyName("locations")]
    public List<SquareLocation>? Locations { get; set; }
}

internal sealed class SquareTeamMembersResponse
{
    [JsonPropertyName("team_members")]
    public List<SquareTeamMemberDto>? TeamMembers { get; set; }

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }
}

internal sealed class SquareTeamMemberSearchRequest
{
    [JsonPropertyName("query")]
    public SquareTeamMemberQuery? Query { get; set; }

    [JsonPropertyName("limit")]
    public int? Limit { get; set; }

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }
}

internal sealed class SquareTeamMemberQuery
{
    [JsonPropertyName("filter")]
    public SquareTeamMemberFilter? Filter { get; set; }
}

internal sealed class SquareTeamMemberFilter
{
    [JsonPropertyName("location_ids")]
    public List<string>? LocationIds { get; set; }

    [JsonPropertyName("status")]
    public string? Status { get; set; }
}

internal sealed class SquareTeamMemberDto
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("is_owner")]
    public bool IsOwner { get; set; }

    [JsonPropertyName("status")]
    public string? Status { get; set; }

    [JsonPropertyName("given_name")]
    public string? GivenName { get; set; }

    [JsonPropertyName("family_name")]
    public string? FamilyName { get; set; }

    [JsonPropertyName("email_address")]
    public string? EmailAddress { get; set; }

    [JsonPropertyName("phone_number")]
    public string? PhoneNumber { get; set; }

    [JsonPropertyName("updated_at")]
    public DateTimeOffset? UpdatedAt { get; set; }

    [JsonPropertyName("assigned_locations")]
    public SquareAssignedLocationsDto? AssignedLocations { get; set; }
}

internal sealed class SquareAssignedLocationsDto
{
    [JsonPropertyName("assignment_type")]
    public string? AssignmentType { get; set; }

    [JsonPropertyName("location_ids")]
    public List<string>? LocationIds { get; set; }
}

internal sealed class SquarePaymentsResponse
{
    [JsonPropertyName("payments")]
    public List<SquarePaymentDto>? Payments { get; set; }

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }
}

internal sealed class SquarePaymentResponse
{
    [JsonPropertyName("payment")]
    public SquarePaymentDto? Payment { get; set; }
}

internal sealed class SquarePaymentDto
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("location_id")]
    public string? LocationId { get; set; }

    [JsonPropertyName("team_member_id")]
    public string? TeamMemberId { get; set; }

    [JsonPropertyName("order_id")]
    public string? OrderId { get; set; }

    [JsonPropertyName("status")]
    public string? Status { get; set; }

    [JsonPropertyName("amount_money")]
    public SquareMoneyDto? AmountMoney { get; set; }

    [JsonPropertyName("tip_money")]
    public SquareMoneyDto? TipMoney { get; set; }

    [JsonPropertyName("total_money")]
    public SquareMoneyDto? TotalMoney { get; set; }

    [JsonPropertyName("created_at")]
    public DateTimeOffset? CreatedAt { get; set; }

    [JsonPropertyName("updated_at")]
    public DateTimeOffset? UpdatedAt { get; set; }

    [JsonPropertyName("receipt_number")]
    public string? ReceiptNumber { get; set; }
}

internal sealed class SquareOrdersResponse
{
    [JsonPropertyName("orders")]
    public List<SquareOrderDto>? Orders { get; set; }

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }
}

internal sealed class SquareBatchRetrieveOrdersRequest
{
    [JsonPropertyName("order_ids")]
    public string[] OrderIds { get; set; } = [];
}

internal sealed class SquareSearchOrdersRequest
{
    [JsonPropertyName("location_ids")]
    public string[] LocationIds { get; set; } = [];

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }

    [JsonPropertyName("limit")]
    public int Limit { get; set; }

    [JsonPropertyName("query")]
    public SquareSearchOrdersQuery? Query { get; set; }
}

internal sealed class SquareBatchDeleteCatalogObjectsRequest
{
    [JsonPropertyName("object_ids")]
    public string[] ObjectIds { get; set; } = [];
}

internal sealed class SquareSearchOrdersQuery
{
    [JsonPropertyName("filter")]
    public SquareSearchOrdersFilter? Filter { get; set; }

    [JsonPropertyName("sort")]
    public SquareSearchOrdersSort? Sort { get; set; }
}

internal sealed class SquareSearchOrdersFilter
{
    [JsonPropertyName("date_time_filter")]
    public SquareSearchOrdersDateTimeFilter? DateTimeFilter { get; set; }

    [JsonPropertyName("state_filter")]
    public SquareSearchOrdersStateFilter? StateFilter { get; set; }
}

internal sealed class SquareSearchOrdersDateTimeFilter
{
    [JsonPropertyName("created_at")]
    public SquareSearchOrdersTimeRange? CreatedAt { get; set; }
}

internal sealed class SquareSearchOrdersTimeRange
{
    [JsonPropertyName("start_at")]
    public string? StartAt { get; set; }

    [JsonPropertyName("end_at")]
    public string? EndAt { get; set; }
}

internal sealed class SquareSearchOrdersStateFilter
{
    [JsonPropertyName("states")]
    public string[] States { get; set; } = [];
}

internal sealed class SquareSearchOrdersSort
{
    [JsonPropertyName("sort_field")]
    public string? SortField { get; set; }

    [JsonPropertyName("sort_order")]
    public string? SortOrder { get; set; }
}

internal sealed class SquareOrderDto
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("payment_id")]
    public string? PaymentId { get; set; }

    [JsonPropertyName("location_id")]
    public string? LocationId { get; set; }

    [JsonPropertyName("customer_id")]
    public string? CustomerId { get; set; }

    [JsonPropertyName("state")]
    public string? State { get; set; }

    [JsonPropertyName("created_at")]
    public DateTimeOffset? CreatedAt { get; set; }

    [JsonPropertyName("updated_at")]
    public DateTimeOffset? UpdatedAt { get; set; }

    [JsonPropertyName("total_money")]
    public SquareMoneyDto? TotalMoney { get; set; }

    [JsonPropertyName("total_tax_money")]
    public SquareMoneyDto? TotalTaxMoney { get; set; }

    [JsonPropertyName("total_discount_money")]
    public SquareMoneyDto? TotalDiscountMoney { get; set; }

    [JsonPropertyName("total_service_charge_money")]
    public SquareMoneyDto? TotalServiceChargeMoney { get; set; }

    [JsonPropertyName("net_amounts")]
    public SquareOrderNetAmountsDto? NetAmounts { get; set; }

    [JsonPropertyName("line_items")]
    public List<SquareOrderLineItemDto>? LineItems { get; set; }

    [JsonPropertyName("tenders")]
    public List<SquareOrderTenderDto>? Tenders { get; set; }
}

internal sealed class SquareOrderNetAmountsDto
{
    [JsonPropertyName("total_money")]
    public SquareMoneyDto? TotalMoney { get; set; }

    [JsonPropertyName("tax_money")]
    public SquareMoneyDto? TaxMoney { get; set; }

    [JsonPropertyName("discount_money")]
    public SquareMoneyDto? DiscountMoney { get; set; }

    [JsonPropertyName("service_charge_money")]
    public SquareMoneyDto? ServiceChargeMoney { get; set; }
}

internal sealed class SquareOrderTenderDto
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("payment_id")]
    public string? PaymentId { get; set; }

    [JsonPropertyName("team_member_id")]
    public string? TeamMemberId { get; set; }

    [JsonPropertyName("amount_money")]
    public SquareMoneyDto? AmountMoney { get; set; }

    [JsonPropertyName("tip_money")]
    public SquareMoneyDto? TipMoney { get; set; }

    [JsonPropertyName("created_at")]
    public DateTimeOffset? CreatedAt { get; set; }
}

internal sealed class SquareOrderLineItemDto
{
    [JsonPropertyName("uid")]
    public string? Uid { get; set; }

    [JsonPropertyName("catalog_object_id")]
    public string? CatalogObjectId { get; set; }

    [JsonPropertyName("catalog_version")]
    public long? CatalogVersion { get; set; }

    [JsonPropertyName("name")]
    public string? Name { get; set; }

    [JsonPropertyName("variation_name")]
    public string? VariationName { get; set; }

    [JsonPropertyName("note")]
    public string? Note { get; set; }

    [JsonPropertyName("modifiers")]
    public List<SquareOrderLineItemModifierDto>? Modifiers { get; set; }

    [JsonPropertyName("quantity")]
    public string? Quantity { get; set; }

    [JsonPropertyName("base_price_money")]
    public SquareMoneyDto? BasePriceMoney { get; set; }

    [JsonPropertyName("gross_sales_money")]
    public SquareMoneyDto? GrossSalesMoney { get; set; }

    [JsonPropertyName("total_money")]
    public SquareMoneyDto? TotalMoney { get; set; }
}

internal sealed class SquareOrderLineItemModifierDto
{
    [JsonPropertyName("uid")]
    public string? Uid { get; set; }

    [JsonPropertyName("catalog_object_id")]
    public string? CatalogObjectId { get; set; }

    [JsonPropertyName("name")]
    public string? Name { get; set; }

    [JsonPropertyName("quantity")]
    public string? Quantity { get; set; }

    [JsonPropertyName("base_price_money")]
    public SquareMoneyDto? BasePriceMoney { get; set; }

    [JsonPropertyName("total_price_money")]
    public SquareMoneyDto? TotalPriceMoney { get; set; }
}

internal sealed class SquareCatalogListResponse
{
    [JsonPropertyName("objects")]
    public List<SquareCatalogObjectDto>? Objects { get; set; }

    [JsonPropertyName("cursor")]
    public string? Cursor { get; set; }
}

internal sealed class SquareCatalogObjectResponse
{
    [JsonPropertyName("object")]
    public SquareCatalogObjectDto? Object { get; set; }

    [JsonPropertyName("related_objects")]
    public List<SquareCatalogObjectDto>? RelatedObjects { get; set; }
}

internal sealed class SquareUpsertCatalogObjectRequest
{
    [JsonPropertyName("idempotency_key")]
    public string IdempotencyKey { get; set; } = string.Empty;

    [JsonPropertyName("object")]
    public SquareCatalogUpsertObject Object { get; set; } = new();
}

internal sealed class SquareUpsertCatalogObjectResponse
{
    [JsonPropertyName("catalog_object")]
    public SquareCatalogUpsertObject? CatalogObject { get; set; }

    [JsonPropertyName("id_mappings")]
    public List<SquareCatalogIdMapping>? IdMappings { get; set; }
}

internal sealed class SquareCatalogIdMapping
{
    [JsonPropertyName("client_object_id")]
    public string? ClientObjectId { get; set; }

    [JsonPropertyName("object_id")]
    public string? ObjectId { get; set; }
}

internal sealed class SquareCatalogUpsertObject
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = string.Empty;

    [JsonPropertyName("id")]
    public string Id { get; set; } = string.Empty;

    [JsonPropertyName("present_at_all_locations")]
    public bool? PresentAtAllLocations { get; set; }

    [JsonPropertyName("item_data")]
    public SquareCatalogItemUpsertData? ItemData { get; set; }

    [JsonPropertyName("item_variation_data")]
    public SquareCatalogItemVariationUpsertData? ItemVariationData { get; set; }

    [JsonPropertyName("category_data")]
    public SquareCatalogCategoryUpsertData? CategoryData { get; set; }
}

internal sealed class SquareCatalogItemUpsertData
{
    [JsonPropertyName("name")]
    public string Name { get; set; } = string.Empty;

    [JsonPropertyName("description")]
    public string? Description { get; set; }

    [JsonPropertyName("category_id")]
    public string? CategoryId { get; set; }

    [JsonPropertyName("variations")]
    public List<SquareCatalogUpsertObject> Variations { get; set; } = [];
}

internal sealed class SquareCatalogCategoryUpsertData
{
    [JsonPropertyName("name")]
    public string Name { get; set; } = string.Empty;
}

internal sealed class SquareCatalogItemVariationUpsertData
{
    [JsonPropertyName("item_id")]
    public string ItemId { get; set; } = string.Empty;

    [JsonPropertyName("name")]
    public string Name { get; set; } = string.Empty;

    [JsonPropertyName("sku")]
    public string Sku { get; set; } = string.Empty;

    [JsonPropertyName("pricing_type")]
    public string PricingType { get; set; } = string.Empty;

    [JsonPropertyName("price_money")]
    public SquareMoneyDto? PriceMoney { get; set; }
}

internal sealed class SquareCatalogObjectDto
{
    [JsonPropertyName("type")]
    public string? Type { get; set; }

    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("item_data")]
    public SquareCatalogItemDataDto? ItemData { get; set; }

    [JsonPropertyName("item_variation_data")]
    public SquareCatalogItemVariationDataDto? ItemVariationData { get; set; }

    [JsonPropertyName("category_data")]
    public SquareCatalogCategoryDataDto? CategoryData { get; set; }
}

internal sealed class SquareCatalogItemDataDto
{
    [JsonPropertyName("name")]
    public string? Name { get; set; }

    [JsonPropertyName("category_id")]
    public string? CategoryId { get; set; }

    [JsonPropertyName("reporting_category")]
    public SquareCatalogCategoryRefDto? ReportingCategory { get; set; }

    [JsonPropertyName("categories")]
    public List<SquareCatalogCategoryRefDto> Categories { get; set; } = [];

    [JsonPropertyName("variations")]
    public List<SquareCatalogObjectDto> Variations { get; set; } = [];
}

internal sealed class SquareCatalogItemVariationDataDto
{
    [JsonPropertyName("item_id")]
    public string? ItemId { get; set; }

    [JsonPropertyName("name")]
    public string? Name { get; set; }

    [JsonPropertyName("sku")]
    public string? Sku { get; set; }

    [JsonPropertyName("price_money")]
    public SquareMoneyDto? PriceMoney { get; set; }
}

internal sealed class SquareCatalogCategoryDataDto
{
    [JsonPropertyName("name")]
    public string? Name { get; set; }
}

internal sealed class SquareCatalogCategoryRefDto
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }
}

public sealed record SquareCatalogCategory(string? Id, string Name);

public sealed record SquareCatalogItem(string? Id, string Name, string? CategoryId, IReadOnlyList<SquareCatalogVariation> Variations);

public sealed record SquareCatalogVariation(string? Id, string Name, string? Sku, long? PriceCents);

internal sealed class SquareCustomerResponseDto
{
    [JsonPropertyName("customer")] public SquareCustomerDto? Customer { get; set; }
}

internal sealed class SquareCustomerDto
{
    [JsonPropertyName("id")] public string? Id { get; set; }
    [JsonPropertyName("given_name")] public string? GivenName { get; set; }
    [JsonPropertyName("family_name")] public string? FamilyName { get; set; }
    [JsonPropertyName("company_name")] public string? CompanyName { get; set; }
    [JsonPropertyName("email_address")] public string? EmailAddress { get; set; }
    [JsonPropertyName("phone_number")] public string? PhoneNumber { get; set; }
    [JsonPropertyName("address")] public SquareCustomerAddressDto? Address { get; set; }
}

internal sealed class SquareCustomerAddressDto
{
    [JsonPropertyName("address_line_1")] public string? AddressLine1 { get; set; }
    [JsonPropertyName("address_line_2")] public string? AddressLine2 { get; set; }
    [JsonPropertyName("locality")] public string? Locality { get; set; }
    [JsonPropertyName("administrative_district_level_1")] public string? AdministrativeDistrictLevel1 { get; set; }
    [JsonPropertyName("postal_code")] public string? PostalCode { get; set; }
    [JsonPropertyName("country")] public string? Country { get; set; }
}

public sealed record SquareCustomer(string? Id, string? GivenName, string? FamilyName, string? CompanyName, string? EmailAddress, string? PhoneNumber, SquareCustomerAddress? Address);
public sealed record SquareCustomerAddress(string? AddressLine1, string? AddressLine2, string? Locality, string? State, string? PostalCode, string? Country);

internal sealed class SquareMoneyDto
{
    [JsonPropertyName("amount")]
    public long Amount { get; set; }

    [JsonPropertyName("currency")]
    public string? Currency { get; set; }
}

internal sealed class SquareErrorResponse
{
    [JsonPropertyName("errors")]
    public List<SquareErrorDetail>? Errors { get; set; }
}

internal sealed class SquareErrorDetail
{
    [JsonPropertyName("category")]
    public string? Category { get; set; }

    [JsonPropertyName("code")]
    public string? Code { get; set; }

    [JsonPropertyName("detail")]
    public string? Detail { get; set; }
}
