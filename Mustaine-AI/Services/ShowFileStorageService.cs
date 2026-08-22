using Microsoft.AspNetCore.Components.Forms;

namespace MustaineAI.Services;

/// <summary>
/// Storage boundary for Show Arm files that vendors/owner may need remotely.
/// The default implementation writes to the web application's own persistent storage.
/// On the Ops VPS, configure SHOW_ARM_STORAGE_ROOT to a persistent volume and optionally
/// SHOW_ARM_PUBLIC_BASE_URL when files are served from a different host/CDN.
///
/// Heavy production artwork/process files do NOT belong here; this storage is only for
/// small Show Arm documents such as maps, vendor packets and show-facing attachments.
/// </summary>
public interface IShowFileStorageService
{
    Task<StoredShowFile> SaveMapAsync(IBrowserFile file, long showEditionId, int year, CancellationToken cancellationToken = default);
    string MapUrl(string storedFileName);
}

public sealed record StoredShowFile(string StoredFileName, string OriginalFileName, string PublicUrl);

public sealed class ShowFileStorageService(IWebHostEnvironment environment, IConfiguration configuration) : IShowFileStorageService
{
    private const long MaxMapBytes = 20L * 1024L * 1024L;

    public async Task<StoredShowFile> SaveMapAsync(IBrowserFile file, long showEditionId, int year, CancellationToken cancellationToken = default)
    {
        var extension = Path.GetExtension(file.Name).ToLowerInvariant();
        if (extension is not (".pdf" or ".png" or ".jpg" or ".jpeg"))
        {
            throw new InvalidOperationException("Maps must be PDF, PNG or JPG.");
        }

        var root = ResolveStorageRoot();
        Directory.CreateDirectory(root);

        var storedFileName = $"show-{showEditionId}-{year}-{Guid.NewGuid():N}{extension}";
        var destination = Path.Combine(root, storedFileName);

        await using var input = file.OpenReadStream(MaxMapBytes, cancellationToken);
        await using var output = File.Create(destination);
        await input.CopyToAsync(output, cancellationToken);

        return new StoredShowFile(storedFileName, file.Name, MapUrl(storedFileName));
    }

    public string MapUrl(string storedFileName)
    {
        if (string.IsNullOrWhiteSpace(storedFileName)) return string.Empty;

        var baseUrl = configuration["ShowArm:PublicBaseUrl"]
            ?? Environment.GetEnvironmentVariable("SHOW_ARM_PUBLIC_BASE_URL");
        var relative = $"/uploads/show-maps/{Uri.EscapeDataString(storedFileName)}";
        return string.IsNullOrWhiteSpace(baseUrl)
            ? relative
            : baseUrl.TrimEnd('/') + relative;
    }

    private string ResolveStorageRoot()
    {
        var configured = configuration["ShowArm:StorageRoot"]
            ?? Environment.GetEnvironmentVariable("SHOW_ARM_STORAGE_ROOT");
        return string.IsNullOrWhiteSpace(configured)
            ? Path.Combine(environment.WebRootPath, "uploads", "show-maps")
            : Path.GetFullPath(configured);
    }
}
