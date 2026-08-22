using System.Text.RegularExpressions;

namespace MustaineAI.Services;

public interface IArtworkVisualService
{
    IReadOnlyList<ArtworkVisual> GetAll();
    string? FindImageUrl(string? artworkName);
}

public sealed record ArtworkVisual(string Key, string Name, string ImageUrl);

public sealed class ArtworkVisualService : IArtworkVisualService
{
    private readonly IWebHostEnvironment _environment;
    private readonly object _gate = new();
    private IReadOnlyList<ArtworkVisual>? _cache;
    private Dictionary<string, string>? _imageByNormalizedName;

    private static readonly HashSet<string> ImageExtensions = new(StringComparer.OrdinalIgnoreCase)
    {
        ".jpg", ".jpeg", ".png", ".webp"
    };

    public ArtworkVisualService(IWebHostEnvironment environment)
    {
        _environment = environment;
    }

    public IReadOnlyList<ArtworkVisual> GetAll()
    {
        EnsureLoaded();
        return _cache!;
    }

    public string? FindImageUrl(string? artworkName)
    {
        if (string.IsNullOrWhiteSpace(artworkName)) return null;
        EnsureLoaded();
        _imageByNormalizedName!.TryGetValue(Normalize(artworkName), out var url);
        return url;
    }

    private void EnsureLoaded()
    {
        if (_cache is not null) return;
        lock (_gate)
        {
            if (_cache is not null) return;

            var root = Path.Combine(_environment.WebRootPath, "process-files", "front-faces");
            var visuals = new List<ArtworkVisual>();

            if (Directory.Exists(root))
            {
                foreach (var dir in Directory.EnumerateDirectories(root, "*", SearchOption.AllDirectories))
                {
                    var image = Directory.EnumerateFiles(dir)
                        .Where(x => ImageExtensions.Contains(Path.GetExtension(x)))
                        .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                        .FirstOrDefault();
                    if (image is null) continue;

                    var name = Path.GetFileName(dir);
                    var key = Path.GetRelativePath(root, dir).Replace('\\', '/');
                    var relativeImage = Path.GetRelativePath(_environment.WebRootPath, image).Replace('\\', '/');
                    var imageUrl = "/" + string.Join('/', relativeImage.Split('/').Select(Uri.EscapeDataString));
                    visuals.Add(new ArtworkVisual(key, name, imageUrl));
                }
            }

            // Visual selector rule: one card per actual artwork name.
            // This only collapses the display list; no process files are moved,
            // deleted, renamed, or merged on disk.
            _cache = visuals
                .GroupBy(x => Normalize(x.Name), StringComparer.OrdinalIgnoreCase)
                .Select(g => g
                    .OrderBy(x => x.Key.Count(c => c == '/'))
                    .ThenBy(x => x.Key, StringComparer.OrdinalIgnoreCase)
                    .First())
                .OrderBy(x => x.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();

            _imageByNormalizedName = _cache
                .GroupBy(x => Normalize(x.Name), StringComparer.OrdinalIgnoreCase)
                .ToDictionary(x => x.Key, x => x.First().ImageUrl, StringComparer.OrdinalIgnoreCase);
        }
    }

    private static string Normalize(string value)
    {
        var text = value.Trim()
            .Replace("—", "-")
            .Replace("–", "-")
            .Replace("’", "'");
        text = Regex.Replace(text, @"\s*-\s*(Black|Brown)\s*$", "", RegexOptions.IgnoreCase);
        text = Regex.Replace(text, @"\s+Modular\s+Book\s*$", "", RegexOptions.IgnoreCase);
        text = Regex.Replace(text, @"\s+Wall\s+Scroll\s*$", "", RegexOptions.IgnoreCase);
        return Regex.Replace(text, @"\s+", " ").Trim().ToUpperInvariant();
    }
}
