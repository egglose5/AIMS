using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface IWooCommerceApiService
{
    Task<WooCommerceConnectionResult> TestConnectionAsync(WooCommerceConnectionInput? input = null, CancellationToken cancellationToken = default);
    Task<IReadOnlyList<WooCommerceCatalogEntry>> GetPublishedCatalogAsync(CancellationToken cancellationToken = default);
}

public sealed class WooCommerceApiService : IWooCommerceApiService
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly ApplicationDbContext _dbContext;

    public WooCommerceApiService(IHttpClientFactory httpClientFactory, ApplicationDbContext dbContext)
    {
        _httpClientFactory = httpClientFactory;
        _dbContext = dbContext;
    }

    public async Task<WooCommerceConnectionResult> TestConnectionAsync(WooCommerceConnectionInput? input = null, CancellationToken cancellationToken = default)
    {
        try
        {
            var settings = input ?? await LoadSettingsAsync(cancellationToken);
            Validate(settings);
            var client = CreateClient(settings);
            using var response = await client.GetAsync("wp-json/wc/v3/products?status=publish&per_page=1", cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
                return new(false, $"WooCommerce returned {(int)response.StatusCode} {response.ReasonPhrase}. {TrimError(body)}");
            return new(true, "Connected to WooCommerce. Published products are readable.");
        }
        catch (Exception ex) { return new(false, ex.Message); }
    }

    public async Task<IReadOnlyList<WooCommerceCatalogEntry>> GetPublishedCatalogAsync(CancellationToken cancellationToken = default)
    {
        var settings = await LoadSettingsAsync(cancellationToken);
        Validate(settings);
        var client = CreateClient(settings);
        var products = new List<WooProduct>();
        for (var page = 1; ; page++)
        {
            var batch = await GetAsync<List<WooProduct>>(client, $"wp-json/wc/v3/products?status=publish&per_page=100&page={page}", cancellationToken) ?? [];
            products.AddRange(batch);
            if (batch.Count < 100) break;
        }

        var result = new List<WooCommerceCatalogEntry>();
        foreach (var product in products)
        {
            if (string.Equals(product.Type, "variable", StringComparison.OrdinalIgnoreCase) && product.Variations.Count > 0)
            {
                var variations = new List<WooVariation>();
                for (var page = 1; ; page++)
                {
                    var batch = await GetAsync<List<WooVariation>>(client, $"wp-json/wc/v3/products/{product.Id}/variations?per_page=100&page={page}", cancellationToken) ?? [];
                    variations.AddRange(batch);
                    if (batch.Count < 100) break;
                }
                foreach (var variation in variations.Where(v => string.Equals(v.Status, "publish", StringComparison.OrdinalIgnoreCase)))
                {
                    var options = string.Join(" / ", variation.Attributes.Select(a => a.Option).Where(x => !string.IsNullOrWhiteSpace(x)));
                    result.Add(new(product.Id, variation.Id, product.Name, options, variation.Sku, BuildDisplayName(product.Name, variation.Attributes), product.Categories.Select(c => c.Name).Where(x => !string.IsNullOrWhiteSpace(x)).ToList()));
                }
            }
            else
            {
                result.Add(new(product.Id, null, product.Name, null, product.Sku, product.Name, product.Categories.Select(c => c.Name).Where(x => !string.IsNullOrWhiteSpace(x)).ToList()));
            }
        }
        return result.OrderBy(x => x.ProductName, StringComparer.OrdinalIgnoreCase).ThenBy(x => x.Options).ToList();
    }

    private async Task<WooCommerceConnectionInput> LoadSettingsAsync(CancellationToken ct)
    {
        var row = await _dbContext.WooCommerceConnectionSettings.AsNoTracking().SingleOrDefaultAsync(x => x.Id == WooCommerceConnectionSettingsEntity.DefaultId, ct);
        return new(row?.StoreUrl, row?.ConsumerKey, row?.ConsumerSecret);
    }

    private HttpClient CreateClient(WooCommerceConnectionInput settings)
    {
        var client = _httpClientFactory.CreateClient();
        client.BaseAddress = new Uri(settings.StoreUrl!.TrimEnd('/') + "/");
        var token = Convert.ToBase64String(Encoding.UTF8.GetBytes($"{settings.ConsumerKey}:{settings.ConsumerSecret}"));
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Basic", token);
        return client;
    }

    private static async Task<T?> GetAsync<T>(HttpClient client, string path, CancellationToken ct)
    {
        using var response = await client.GetAsync(path, ct);
        var body = await response.Content.ReadAsStringAsync(ct);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException($"WooCommerce returned {(int)response.StatusCode} {response.ReasonPhrase}. {TrimError(body)}");
        return JsonSerializer.Deserialize<T>(body, JsonOptions);
    }

    private static void Validate(WooCommerceConnectionInput s)
    {
        if (string.IsNullOrWhiteSpace(s.StoreUrl)) throw new InvalidOperationException("WooCommerce Store URL is required.");
        if (!Uri.TryCreate(s.StoreUrl, UriKind.Absolute, out _)) throw new InvalidOperationException("WooCommerce Store URL must be a full https:// address.");
        if (string.IsNullOrWhiteSpace(s.ConsumerKey)) throw new InvalidOperationException("WooCommerce Consumer Key is required.");
        if (string.IsNullOrWhiteSpace(s.ConsumerSecret)) throw new InvalidOperationException("WooCommerce Consumer Secret is required.");
    }

    private static string BuildDisplayName(string productName, List<WooAttribute> attributes)
    {
        var options = attributes.Select(a => a.Option).Where(x => !string.IsNullOrWhiteSpace(x)).ToList();
        return options.Count == 0 ? productName : $"{productName} - {string.Join(" - ", options)}";
    }

    private static string TrimError(string value) => value.Length <= 240 ? value : value[..240] + "…";
    private sealed class WooProduct { public long Id { get; set; } public string Name { get; set; } = ""; public string Type { get; set; } = ""; public string? Sku { get; set; } public List<long> Variations { get; set; } = []; public List<WooCategory> Categories { get; set; } = []; }
    private sealed class WooCategory { public long Id { get; set; } public string Name { get; set; } = ""; }
    private sealed class WooVariation { public long Id { get; set; } public string Status { get; set; } = ""; public string? Sku { get; set; } public List<WooAttribute> Attributes { get; set; } = []; }
    private sealed class WooAttribute { public string Name { get; set; } = ""; public string Option { get; set; } = ""; }
}

public sealed record WooCommerceConnectionInput(string? StoreUrl, string? ConsumerKey, string? ConsumerSecret);
public sealed record WooCommerceConnectionResult(bool IsConnected, string Message);
public sealed record WooCommerceCatalogEntry(long ProductId, long? VariationId, string ProductName, string? Options, string? Sku, string DisplayName, IReadOnlyList<string> Categories);
