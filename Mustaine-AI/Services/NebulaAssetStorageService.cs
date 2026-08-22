using Microsoft.AspNetCore.Components.Forms;

namespace MustaineAI.Services;

public interface INebulaAssetStorageService
{
    Task<StoredNebulaAsset> SaveAssetAsync(IBrowserFile file, string assetType, CancellationToken cancellationToken = default);
}

public sealed record StoredNebulaAsset(string StoredFileName, string RelativeUrl, long Size);

public sealed class NebulaAssetStorageService(IWebHostEnvironment environment) : INebulaAssetStorageService
{
    public async Task<StoredNebulaAsset> SaveAssetAsync(IBrowserFile file, string assetType, CancellationToken cancellationToken = default)
    {
        var safeType = string.IsNullOrWhiteSpace(assetType) ? "misc" : assetType.Trim().ToLowerInvariant();
        var root = Path.Combine(environment.WebRootPath, "uploads", "nebula", safeType);
        Directory.CreateDirectory(root);

        var extension = Path.GetExtension(file.Name);
        var baseName = Path.GetFileNameWithoutExtension(file.Name);
        var safeBaseName = string.Concat(baseName.Where(ch => char.IsLetterOrDigit(ch) || ch is '-' or '_'));
        if (string.IsNullOrWhiteSpace(safeBaseName))
            safeBaseName = "asset";

        var storedFileName = $"{DateTimeOffset.UtcNow:yyyyMMddHHmmss}-{Guid.NewGuid():N}-{safeBaseName}{extension}";
        var fullPath = Path.Combine(root, storedFileName);

        await using var write = File.Create(fullPath);
        await using var read = file.OpenReadStream(maxAllowedSize: 25 * 1024 * 1024, cancellationToken);
        await read.CopyToAsync(write, cancellationToken);

        var relative = $"/uploads/nebula/{Uri.EscapeDataString(safeType)}/{Uri.EscapeDataString(storedFileName)}";
        return new StoredNebulaAsset(storedFileName, relative, file.Size);
    }
}
