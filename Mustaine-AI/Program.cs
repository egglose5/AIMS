// Control-App: Production Control System
// Main application entry point and startup configuration
// This Blazor Server application manages production operations, inventory, and e-commerce integrations

using Microsoft.AspNetCore.Components.Authorization;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;
using Npgsql;
using MustaineAI.Components;
using MustaineAI.Components.Account;
using MustaineAI.Data;
using MustaineAI.Services;

// Initialize the ASP.NET Core application builder
var builder = WebApplication.CreateBuilder(args);

// Force-enable static web assets when running without a launch profile.
builder.WebHost.UseStaticWebAssets();

// Configure web host URLs:
// - honor ASPNETCORE_URLS when explicitly provided
// - keep a local-machine fallback for direct runs
// - do not force URLS in containers (let image defaults/HTTP_PORTS apply)
var configuredUrls = Environment.GetEnvironmentVariable("ASPNETCORE_URLS");
if (!string.IsNullOrWhiteSpace(configuredUrls))
{
    builder.WebHost.UseUrls(configuredUrls);
}
else
{
    var runningInContainer = string.Equals(
        Environment.GetEnvironmentVariable("DOTNET_RUNNING_IN_CONTAINER"),
        "true",
        StringComparison.OrdinalIgnoreCase);

    if (!runningInContainer)
    {
        builder.WebHost.UseUrls("http://127.0.0.1:5299");
    }
}

// ===== UI & RENDERING =====
// Configure Razor components and interactive server-side rendering
builder.Services.AddRazorComponents()
    .AddInteractiveServerComponents();

// ===== HTTP & API INTEGRATION =====
// Add HTTP client factory for making external API calls
builder.Services.AddHttpClient();
builder.Services.AddScoped<ISquareApiService, SquareApiService>();
builder.Services.AddScoped<IWooCommerceApiService, WooCommerceApiService>();
builder.Services.AddScoped<IPermanentSkuService, PermanentSkuService>();
builder.Services.AddScoped<ISellableSkuService, SellableSkuService>();
builder.Services.AddScoped<IProductRegistryService, ProductRegistryService>();
builder.Services.AddScoped<INebulaAssetStorageService, NebulaAssetStorageService>();
builder.Services.AddSingleton<IArtworkVisualService, ArtworkVisualService>();
builder.Services.AddScoped<IShowWebResearchService, ShowWebResearchService>();
builder.Services.AddScoped<IShowPlacementService, ShowPlacementService>();
builder.Services.AddHostedService<ShowFinderBackgroundService>();
builder.Services.AddScoped<IShowDatabaseImportService, ShowDatabaseImportService>();
builder.Services.AddScoped<IShowFileStorageService, ShowFileStorageService>();
builder.Services.AddScoped<IShowArmGatewayService, ShowArmGatewayService>();
builder.Services.AddScoped<IBrainEmailIntakeService, BrainEmailIntakeService>();
builder.Services.AddScoped<IBrainCoreService, BrainCoreService>();
builder.Services.AddScoped<IBrainMemoryService, BrainMemoryService>();
builder.Services.AddScoped<IBrainToolGatewayService, BrainToolGatewayService>();
builder.Services.AddScoped<IBrainRuntimeGatewayAdapter, LocalBrainRuntimeGatewayAdapter>();
builder.Services.AddScoped<IBrainModelRouter, BrainModelRouter>();
builder.Services.AddScoped<IBrainReasoningService, ShowBrainReasoningService>();
builder.Services.AddScoped<IBrainDecisionLearningService, BrainDecisionLearningService>();
builder.Services.AddScoped<IScoutDiscoveryService, ScoutDiscoveryService>();
builder.Services.AddScoped<IScoutResearchService, ScoutResearchService>(); // SCOUT_S2_RESEARCH

// ===== AUTHENTICATION & AUTHORIZATION =====
// Enable cascading authentication state to child components in the Blazor component hierarchy
builder.Services.AddCascadingAuthenticationState();
// Add helper for redirecting users after authentication state changes
builder.Services.AddScoped<IdentityRedirectManager>();
// Use a revalidating authentication provider that re-checks auth state periodically
builder.Services.AddScoped<AuthenticationStateProvider, IdentityRevalidatingAuthenticationStateProvider>();

// Configure authentication with ASP.NET Core Identity
builder.Services.AddAuthentication(options =>
    {
        options.DefaultScheme = IdentityConstants.ApplicationScheme;  // Default: application cookies
        options.DefaultSignInScheme = IdentityConstants.ExternalScheme; // External: OAuth/external providers
    })
    .AddIdentityCookies(); // Use secure Identity-managed cookies
builder.Services.AddAuthorization(); // Enable authorization policy checks

// Persist Data Protection keys so auth/session cookies remain valid across container restarts.
var dataProtectionKeysPath = Environment.GetEnvironmentVariable("ASPNETCORE_DATA_PROTECTION_KEYS_PATH")
    ?? Path.Combine(builder.Environment.ContentRootPath, ".aspnet", "DataProtection-Keys");
Directory.CreateDirectory(dataProtectionKeysPath);
builder.Services
    .AddDataProtection()
    .PersistKeysToFileSystem(new DirectoryInfo(dataProtectionKeysPath))
    .SetApplicationName("Mustaine-AI");

// ===== DATABASE CONNECTION CONFIGURATION =====
// Resolve PostgreSQL connection details from environment variables with fallback names for Docker Compose compatibility
var postgresHost = Environment.GetEnvironmentVariable("POSTGRES_HOST")
    ?? Environment.GetEnvironmentVariable("POSTGRES_PORT_5432_TCP_ADDR")  // Docker Compose linked service format
    ?? Environment.GetEnvironmentVariable("DB_HOST");
var postgresPort = Environment.GetEnvironmentVariable("POSTGRES_PORT")
    ?? Environment.GetEnvironmentVariable("POSTGRES_PORT_5432_TCP_PORT")  // Docker Compose linked service format
    ?? Environment.GetEnvironmentVariable("DB_PORT");
var postgresDb = Environment.GetEnvironmentVariable("POSTGRES_DB")
    ?? Environment.GetEnvironmentVariable("DB_NAME");
var postgresUser = Environment.GetEnvironmentVariable("POSTGRES_USER")
    ?? Environment.GetEnvironmentVariable("DB_USER");
var postgresPassword = Environment.GetEnvironmentVariable("POSTGRES_PASSWORD")
    ?? Environment.GetEnvironmentVariable("DB_PASSWORD");

// Attempt to resolve connection string from multiple sources.
var connectionString = Environment.GetEnvironmentVariable("ConnectionStrings__DefaultConnection")
    ?? Environment.GetEnvironmentVariable("ConnectionStrings__postgres")
    ?? builder.Configuration.GetConnectionString("DefaultConnection");
var connectionSource = "Configuration";

if (!string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ConnectionStrings__DefaultConnection"))
    || !string.IsNullOrWhiteSpace(Environment.GetEnvironmentVariable("ConnectionStrings__postgres")))
{
    connectionSource = "Environment";
}

// When the app is started without a launch profile, ASP.NET defaults to Production.
// If no connection string is injected, try development appsettings as a local fallback.
if (string.IsNullOrWhiteSpace(connectionString)
    && builder.Environment.IsProduction()
    && !string.Equals(Environment.GetEnvironmentVariable("DOTNET_RUNNING_IN_CONTAINER"), "true", StringComparison.OrdinalIgnoreCase))
{
    var developmentConnectionString = new ConfigurationBuilder()
        .SetBasePath(builder.Environment.ContentRootPath)
        .AddJsonFile("appsettings.Development.json", optional: true)
        .Build()
        .GetConnectionString("DefaultConnection");

    if (!string.IsNullOrWhiteSpace(developmentConnectionString))
    {
        connectionString = developmentConnectionString;
        connectionSource = "DevelopmentConfigFallback";
        Console.WriteLine("[Startup] Using development connection string fallback because no explicit connection string was supplied.");
    }
}

// If no connection string found, construct one from individual PostgreSQL environment variables
if (string.IsNullOrWhiteSpace(connectionString))
{
    // Check if any PostgreSQL environment variables are set
    var hasPostgresEnvironment = !string.IsNullOrWhiteSpace(postgresHost)
        || !string.IsNullOrWhiteSpace(postgresPort)
        || !string.IsNullOrWhiteSpace(postgresDb)
        || !string.IsNullOrWhiteSpace(postgresUser)
        || !string.IsNullOrWhiteSpace(postgresPassword);

    // Construct a safe fallback so startup does not crash when connection string injection is delayed/missing.
    // Database connectivity is still validated later by EF Core and handled by migration/seed try-catch blocks.
    if (!builder.Environment.IsDevelopment() && !hasPostgresEnvironment)
    {
        Console.WriteLine("[Startup] No explicit DB connection string found. Falling back to localhost defaults.");
    }

    connectionString = $"Host={postgresHost ?? "localhost"};Port={postgresPort ?? "5432"};Database={postgresDb ?? "MustaineAI"};Username={postgresUser ?? "postgres"};Password={postgresPassword ?? "postgres"}";
    connectionSource = "ConstructedFallback";
}

var connectionBuilder = new NpgsqlConnectionStringBuilder(connectionString)
{
    // Give PostgreSQL a reasonable chance to respond before the UI treats it as unavailable.
    Timeout = 10,
};

var commandTimeoutSeconds = builder.Configuration.GetValue("Database:CommandTimeoutSeconds", 30);
if (commandTimeoutSeconds > 0)
{
    connectionBuilder.CommandTimeout = commandTimeoutSeconds;
}

connectionString = connectionBuilder.ConnectionString;
Console.WriteLine($"[Startup] Database target: {connectionBuilder.Host}:{connectionBuilder.Port}/{connectionBuilder.Database} (Source: {connectionSource})");

// Configure Entity Framework Core with PostgreSQL database provider
builder.Services.AddDbContext<ApplicationDbContext>(options =>
{
    // Use PostgreSQL with retry-on-failure strategy for resilience (useful for containerized environments)
    options.UseNpgsql(
        connectionString,
        npgsqlOptions =>
        {
            npgsqlOptions.CommandTimeout(commandTimeoutSeconds);
            npgsqlOptions.EnableRetryOnFailure(
                maxRetryCount: 2,
                maxRetryDelay: TimeSpan.FromSeconds(2),
                errorCodesToAdd: null);
        });

    // In development, log warnings about pending model changes (helps catch migration issues)
    if (builder.Environment.IsDevelopment())
    {
        options.ConfigureWarnings(warnings =>
            warnings.Log(RelationalEventId.PendingModelChangesWarning));
    }
});

// ===== SHOW ARM DATABASE BOUNDARY =====
// The Show Arm is the part of Control App that must be available to Jaime/vendors from anywhere.
// It therefore gets its own configurable connection. During local testing, no extra configuration
// is required: it falls back to DefaultConnection and behaves exactly like the pre-split app.
// On Ops, set ConnectionStrings__ShowArmConnection to the small cloud PostgreSQL database.
var showArmConnectionString = Environment.GetEnvironmentVariable("ConnectionStrings__ShowArmConnection")
    ?? builder.Configuration.GetConnectionString("ShowArmConnection")
    ?? connectionString;

var showArmConnectionBuilder = new NpgsqlConnectionStringBuilder(showArmConnectionString)
{
    Timeout = 10,
    CommandTimeout = commandTimeoutSeconds,
};
showArmConnectionString = showArmConnectionBuilder.ConnectionString;
var showArmIsSeparate = !string.Equals(showArmConnectionString, connectionString, StringComparison.Ordinal);
Console.WriteLine($"[Startup] Show Arm database target: {showArmConnectionBuilder.Host}:{showArmConnectionBuilder.Port}/{showArmConnectionBuilder.Database} (Separate from local DB: {showArmIsSeparate})");

builder.Services.AddDbContext<ShowArmDbContext>(options =>
{
    options.UseNpgsql(
        showArmConnectionString,
        npgsqlOptions =>
        {
            npgsqlOptions.CommandTimeout(commandTimeoutSeconds);
            npgsqlOptions.EnableRetryOnFailure(
                maxRetryCount: 2,
                maxRetryDelay: TimeSpan.FromSeconds(2),
                errorCodesToAdd: null);
        });

    if (builder.Environment.IsDevelopment())
    {
        options.ConfigureWarnings(warnings => warnings.Log(RelationalEventId.PendingModelChangesWarning));
    }
});

// Add database developer page exception filter to provide detailed error pages in development
builder.Services.AddDatabaseDeveloperPageExceptionFilter();

// Configure ASP.NET Core Identity for user management and authentication
builder.Services.AddIdentityCore<ApplicationUser>(options =>
    {
        // Relaxed password requirements for development purposes (adjust for production)
        options.SignIn.RequireConfirmedAccount = false;
        options.Password.RequiredLength = 5;
        options.Password.RequireDigit = false;
        options.Password.RequireLowercase = false;
        options.Password.RequireNonAlphanumeric = false;
        options.Password.RequireUppercase = false;
        options.User.RequireUniqueEmail = false;
    })
    .AddEntityFrameworkStores<ApplicationDbContext>()  // Use EF Core for user/role storage
    .AddSignInManager()                                // Add sign-in manager
    .AddDefaultTokenProviders();                       // Add token providers for password reset, 2FA, etc.

// Build the application
builder.Services.AddScoped<IScoutIntegrationService, ScoutIntegrationService>();

var app = builder.Build();

// ===== DATABASE INITIALIZATION =====
var canSeedDefaultAdminUser = true;

// Apply pending database migrations at startup (configurable in appsettings)
if (builder.Configuration.GetValue("Database:ApplyMigrationsOnStartup", true))
{
    using var scope = app.Services.CreateScope();
    var dbContext = scope.ServiceProvider.GetRequiredService<ApplicationDbContext>();

    try
    {
        // Apply any pending migrations to the database
        // EF Core will create the database if it doesn't exist
        await dbContext.Database.MigrateAsync();

        // Self-heal critical columns in case migration history drifted from actual schema.
        await EnsureProductionQueueSchemaAsync(dbContext);
        await EnsureElementsSchemaAsync(dbContext);
        await EnsureFulfillmentSchemaAsync(dbContext);
        await EnsureShowApplicationPlatformSchemaAsync(dbContext);
        await EnsureBrainCoreSchemaAsync(dbContext);
        await EnsureBrainMemorySchemaAsync(dbContext);
    }
    catch (Exception exception)
    {
        // If migrations fail, disable admin user seeding and continue with a warning
        // This allows the app to start even if the database is temporarily unavailable
        canSeedDefaultAdminUser = false;
        app.Logger.LogWarning(
            exception,
            "Database migrations could not be applied at startup. The database may be unavailable.");
    }

    try
    {
        // Self-heal critical schema in case migration history drifted from the live database.
        await EnsureProductionQueueSchemaAsync(dbContext);
        await EnsureElementsSchemaAsync(dbContext);
        await EnsureFulfillmentSchemaAsync(dbContext);
        await EnsureShowApplicationPlatformSchemaAsync(dbContext);
        await EnsureBrainCoreSchemaAsync(dbContext);
        await EnsureBrainMemorySchemaAsync(dbContext);
        await EnsureNebulaSchemaAsync(dbContext);
    }
    catch (Exception exception)
    {
        canSeedDefaultAdminUser = false;
        app.Logger.LogWarning(
            exception,
            "Database compatibility checks could not complete at startup.");
    }
}

// Seed the bootstrap admin user only when explicitly enabled.
if (canSeedDefaultAdminUser)
{
    try
    {
        using var scope = app.Services.CreateScope();
        await SeedBootstrapAdminUserAsync(scope.ServiceProvider, app.Configuration, app.Logger);
    }
    catch (Exception exception)
    {
        // If seeding fails, log warning but continue - existing owner access may still be intact.
        app.Logger.LogWarning(
            exception,
            "Bootstrap admin processing could not complete at startup. Existing authentication remains unchanged.");
    }
}

try
{
    using var scope = app.Services.CreateScope();
    await LogOwnershipAndSchemaDiagnosticsAsync(scope.ServiceProvider, app.Logger, showArmIsSeparate);
}
catch (Exception exception)
{
    app.Logger.LogWarning(exception, "Startup diagnostics could not be collected.");
}

// ===== HTTP REQUEST PIPELINE =====
// Configure the HTTP request pipeline based on environment
if (app.Environment.IsDevelopment())
{
    // In development, use migrations endpoint for database management
    app.UseMigrationsEndPoint();
}
else
{
    // In production, use centralized error handler
    app.UseExceptionHandler("/Error", createScopeForErrors: true);
    // Enable HSTS (HTTP Strict Transport Security) for production
    // Default value is 30 days - adjust for your security requirements
    app.UseHsts();
}

// Map status code pages (e.g., 404 errors) to a not-found page
app.UseStatusCodePagesWithReExecute("/not-found", createScopeForStatusCodePages: true);

// Enable authentication/authorization middleware
app.UseAuthentication();  // Verify user identity (validates cookies, tokens, etc.)
app.UseAuthorization();   // Check if user is authorized for the requested resource

// Enable antiforgery token validation for form submissions
app.UseAntiforgery();

// Map static assets (CSS, JS, images, etc.)
app.MapStaticAssets();

// Map Razor components with interactive server rendering
app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

// Map Identity API endpoints (login, logout, register, etc.)
app.MapAdditionalIdentityEndpoints();

app.MapScoutIntegrationEndpoints();
app.MapShowArmGatewayEndpoints();

app.Run();


static async Task EnsureShowApplicationPlatformSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"Platform\" character varying(80);",
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"ApplicationUrl\" character varying(1600);",
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"ExternalApplicationId\" character varying(240);",
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"ExternalStatus\" character varying(120);",
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"NextAction\" character varying(500);",
        "ALTER TABLE IF EXISTS \"ShowApplications\" ADD COLUMN IF NOT EXISTS \"LastCheckedAt\" timestamp with time zone;"
    };
    foreach (var command in commands) await dbContext.Database.ExecuteSqlRawAsync(command);
}

static async Task EnsureFulfillmentSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        "CREATE TABLE IF NOT EXISTS \"FulfillmentOrderLines\" (\"Id\" uuid NOT NULL, \"SourceChannel\" varchar(40) NOT NULL, \"SourceOrderId\" varchar(192) NOT NULL, \"SourceLineItemId\" varchar(192) NOT NULL, \"SourceOrderNumber\" varchar(120), \"SourceCustomerId\" varchar(192), \"CustomerName\" varchar(220), \"CustomerEmail\" varchar(256), \"CustomerPhone\" varchar(40), \"ShipToName\" varchar(220), \"ShipAddress1\" varchar(220), \"ShipAddress2\" varchar(220), \"ShipCity\" varchar(120), \"ShipState\" varchar(80), \"ShipPostalCode\" varchar(32), \"ShipCountry\" varchar(80), \"ProductName\" varchar(220) NOT NULL DEFAULT '', \"VariationName\" varchar(220), \"Sku\" varchar(120), \"Quantity\" numeric(18,4) NOT NULL DEFAULT 1, \"UnitPriceCents\" bigint NOT NULL DEFAULT 0, \"Currency\" varchar(4) NOT NULL DEFAULT 'USD', \"OrderNotes\" varchar(2000), \"SelectionJson\" text, \"ProductionStatus\" varchar(40) NOT NULL DEFAULT 'UNASSESSED', \"FulfillmentStatus\" varchar(40) NOT NULL DEFAULT 'OPEN', \"Carrier\" varchar(80), \"TrackingNumber\" varchar(192), \"ShippedAt\" timestamptz, \"OrderCreatedAt\" timestamptz NOT NULL DEFAULT NOW(), \"CreatedAt\" timestamptz NOT NULL DEFAULT NOW(), \"UpdatedAt\" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT \"PK_FulfillmentOrderLines\" PRIMARY KEY (\"Id\"));",
        "CREATE UNIQUE INDEX IF NOT EXISTS \"IX_FulfillmentOrderLines_Source\" ON \"FulfillmentOrderLines\" (\"SourceChannel\", \"SourceOrderId\", \"SourceLineItemId\");",
        "CREATE INDEX IF NOT EXISTS \"IX_FulfillmentOrderLines_FulfillmentStatus\" ON \"FulfillmentOrderLines\" (\"FulfillmentStatus\");",
        "CREATE INDEX IF NOT EXISTS \"IX_FulfillmentOrderLines_ProductionStatus\" ON \"FulfillmentOrderLines\" (\"ProductionStatus\");",
        "CREATE INDEX IF NOT EXISTS \"IX_FulfillmentOrderLines_OrderCreatedAt\" ON \"FulfillmentOrderLines\" (\"OrderCreatedAt\");",
        // Preserve the existing Show Orders workflow while seeding its current state into the shared backbone.
        "INSERT INTO \"FulfillmentOrderLines\" (\"Id\", \"SourceChannel\", \"SourceOrderId\", \"SourceLineItemId\", \"ProductionStatus\", \"FulfillmentStatus\", \"OrderCreatedAt\", \"CreatedAt\", \"UpdatedAt\") SELECT gen_random_uuid(), 'SQUARE_SHOW_ORDER', s.\"SquareOrderId\", s.\"LineItemUid\", CASE WHEN s.\"Status\" = 'NEEDS_PRODUCTION' THEN 'NEEDS_PRODUCTION' ELSE 'UNASSESSED' END, CASE WHEN s.\"Status\" = 'READY_TO_SHIP' THEN 'READY_TO_SHIP' WHEN s.\"Status\" = 'SHIPPED' THEN 'SHIPPED' WHEN s.\"Status\" = 'COMPLETE' THEN 'COMPLETE' ELSE 'OPEN' END, s.\"UpdatedAt\", NOW(), s.\"UpdatedAt\" FROM \"ShowOrderFulfillments\" s ON CONFLICT (\"SourceChannel\", \"SourceOrderId\", \"SourceLineItemId\") DO NOTHING;"
    };

    foreach (var command in commands)
    {
        await dbContext.Database.ExecuteSqlRawAsync(command);
    }
}

static async Task EnsureProductionQueueSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        "ALTER TABLE IF EXISTS \"ProductionQueueItems\" ADD COLUMN IF NOT EXISTS \"Sku\" character varying(120);",
        "ALTER TABLE IF EXISTS \"ProductionQueueItems\" ADD COLUMN IF NOT EXISTS \"Quantity\" integer NOT NULL DEFAULT 0;",
        "ALTER TABLE IF EXISTS \"ProductionQueueItems\" ADD COLUMN IF NOT EXISTS \"FrontFaceTier\" character varying(32);",
        "ALTER TABLE IF EXISTS \"ProductionQueueItems\" ADD COLUMN IF NOT EXISTS \"FrontFaceColor\" character varying(32);",
    };

    foreach (var command in commands)
    {
        await dbContext.Database.ExecuteSqlRawAsync(command);
    }
}

static async Task EnsureElementsSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        "CREATE TABLE IF NOT EXISTS \"FinishedGoodsManufacturingFiles\" (\"Id\" uuid NOT NULL, \"ElementKey\" character varying(260) NOT NULL, \"SourceGroupKey\" character varying(120) NOT NULL DEFAULT '', \"ElementLabel\" character varying(220) NOT NULL DEFAULT '', \"StoredFileName\" character varying(260) NOT NULL DEFAULT '', \"RelativeFilePath\" character varying(400) NOT NULL DEFAULT '', \"InputDefinition\" text NULL, \"OutputDefinition\" text NULL, \"UploadedAt\" timestamp with time zone NOT NULL DEFAULT NOW(), \"UpdatedAt\" timestamp with time zone NOT NULL DEFAULT NOW(), CONSTRAINT \"PK_FinishedGoodsManufacturingFiles\" PRIMARY KEY (\"Id\"));",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"ElementKey\" character varying(260);",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"SourceGroupKey\" character varying(120) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"ElementLabel\" character varying(220) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"StoredFileName\" character varying(260) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"RelativeFilePath\" character varying(400) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"InputDefinition\" text;",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"OutputDefinition\" text;",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"UploadedAt\" timestamp with time zone NOT NULL DEFAULT NOW();",
        "ALTER TABLE IF EXISTS \"FinishedGoodsManufacturingFiles\" ADD COLUMN IF NOT EXISTS \"UpdatedAt\" timestamp with time zone NOT NULL DEFAULT NOW();",
        "CREATE UNIQUE INDEX IF NOT EXISTS \"IX_FinishedGoodsManufacturingFiles_ElementKey\" ON \"FinishedGoodsManufacturingFiles\" (\"ElementKey\");",
        "CREATE INDEX IF NOT EXISTS \"IX_FinishedGoodsManufacturingFiles_SourceGroupKey\" ON \"FinishedGoodsManufacturingFiles\" (\"SourceGroupKey\");",

        "CREATE TABLE IF NOT EXISTS \"PinnedElements\" (\"ElementKey\" character varying(260) NOT NULL, \"DisplayName\" character varying(220) NOT NULL DEFAULT '', \"SourceGroup\" character varying(120) NOT NULL DEFAULT '', \"CreatedAt\" timestamp with time zone NOT NULL DEFAULT NOW(), CONSTRAINT \"PK_PinnedElements\" PRIMARY KEY (\"ElementKey\"));",
        "ALTER TABLE IF EXISTS \"PinnedElements\" ADD COLUMN IF NOT EXISTS \"DisplayName\" character varying(220) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"PinnedElements\" ADD COLUMN IF NOT EXISTS \"SourceGroup\" character varying(120) NOT NULL DEFAULT '';",
        "ALTER TABLE IF EXISTS \"PinnedElements\" ADD COLUMN IF NOT EXISTS \"CreatedAt\" timestamp with time zone NOT NULL DEFAULT NOW();",
        "CREATE INDEX IF NOT EXISTS \"IX_PinnedElements_CreatedAt\" ON \"PinnedElements\" (\"CreatedAt\");",
    };

    foreach (var command in commands)
    {
        await dbContext.Database.ExecuteSqlRawAsync(command);
    }
}

static async Task SeedBootstrapAdminUserAsync(IServiceProvider services, IConfiguration configuration, ILogger logger)
{
    var enabled = configuration.GetValue<bool>("ControlApp:BootstrapAdmin:Enabled")
        || string.Equals(Environment.GetEnvironmentVariable("CONTROLAPP_BOOTSTRAP_ADMIN_ENABLED"), "true", StringComparison.OrdinalIgnoreCase);
    if (!enabled)
    {
        logger.LogInformation("Bootstrap admin is disabled. No startup admin account changes were made.");
        return;
    }

    var adminUserName = configuration["ControlApp:BootstrapAdmin:UserName"]
        ?? Environment.GetEnvironmentVariable("CONTROLAPP_BOOTSTRAP_ADMIN_USERNAME")
        ?? "admin";
    var adminEmail = configuration["ControlApp:BootstrapAdmin:Email"]
        ?? Environment.GetEnvironmentVariable("CONTROLAPP_BOOTSTRAP_ADMIN_EMAIL")
        ?? "admin@control-app.local";
    var adminPassword = configuration["ControlApp:BootstrapAdmin:Password"]
        ?? Environment.GetEnvironmentVariable("CONTROLAPP_BOOTSTRAP_ADMIN_PASSWORD");

    if (string.IsNullOrWhiteSpace(adminPassword) || string.Equals(adminPassword, "admin", StringComparison.Ordinal))
    {
        logger.LogWarning("Bootstrap admin is enabled but no non-default password was supplied. Startup bootstrap creation was skipped.");
        return;
    }

    var userManager = services.GetRequiredService<UserManager<ApplicationUser>>();
    var user = await userManager.FindByNameAsync(adminUserName)
        ?? await userManager.FindByEmailAsync(adminEmail);

    if (user is null)
    {
        user = new ApplicationUser
        {
            UserName = adminUserName,
            Email = adminEmail,
            EmailConfirmed = true,
        };

        var createResult = await userManager.CreateAsync(user, adminPassword);
        if (!createResult.Succeeded)
        {
            throw new InvalidOperationException($"Bootstrap admin user could not be created. {string.Join(" ", createResult.Errors.Select(error => error.Description))}");
        }

        logger.LogInformation("Bootstrap admin user {UserName} was created because explicit bootstrap settings were supplied.", adminUserName);
        return;
    }

    if (!user.EmailConfirmed)
    {
        user.EmailConfirmed = true;
        await userManager.UpdateAsync(user);
    }

    logger.LogInformation("Bootstrap admin user {UserName} already exists. Startup did not reset its password.", user.UserName);
}

static async Task LogOwnershipAndSchemaDiagnosticsAsync(IServiceProvider services, ILogger logger, bool showArmIsSeparate)
{
    var dbContext = services.GetRequiredService<ApplicationDbContext>();

    var identityUserCount = await dbContext.Users.AsNoTracking().CountAsync();
    var mappedShowAdminCount = await dbContext.ShowVendorProfiles.AsNoTracking()
        .CountAsync(x => x.IsActive && x.IsShowAdmin && x.ApplicationUserId != null);
    var unmappedShowAdminCount = await dbContext.ShowVendorProfiles.AsNoTracking()
        .CountAsync(x => x.IsActive && x.IsShowAdmin && x.ApplicationUserId == null);
    var fulfillmentLineCount = await dbContext.FulfillmentOrderLines.AsNoTracking().CountAsync();

    logger.LogInformation(
        "Ownership diagnostics: identityUsers={IdentityUsers}, mappedShowAdmins={MappedShowAdmins}, unmappedShowAdmins={UnmappedShowAdmins}, showArmSeparateConnection={ShowArmSeparateConnection}",
        identityUserCount,
        mappedShowAdminCount,
        unmappedShowAdminCount,
        showArmIsSeparate);
    logger.LogInformation(
        "Schema diagnostics: FulfillmentOrderLines rows={FulfillmentLineCount}, DataProtectionPath={DataProtectionPath}",
        fulfillmentLineCount,
        Environment.GetEnvironmentVariable("ASPNETCORE_DATA_PROTECTION_KEYS_PATH") ?? "local-default");
}


static async Task EnsureBrainCoreSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        """CREATE TABLE IF NOT EXISTS "BrainAgentProfiles" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "AgentKey" varchar(80) NOT NULL, "DisplayName" varchar(160) NOT NULL, "Purpose" varchar(2000) NOT NULL, "ArmScope" varchar(120) NOT NULL, "RuntimeKind" varchar(120) NOT NULL, "AutonomyLevel" varchar(80) NOT NULL, "Enabled" boolean NOT NULL DEFAULT TRUE, "CreatedAt" timestamptz NOT NULL, "UpdatedAt" timestamptz NOT NULL);""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_BrainAgentProfiles_AgentKey" ON "BrainAgentProfiles" ("AgentKey");""",
        """CREATE TABLE IF NOT EXISTS "BrainCapabilityGrants" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "AgentKey" varchar(80) NOT NULL, "CapabilityKey" varchar(200) NOT NULL, "AccessMode" varchar(40) NOT NULL, "RequiresHumanApproval" boolean NOT NULL DEFAULT FALSE, "BoundaryNote" varchar(2000) NULL, "UpdatedAt" timestamptz NOT NULL);""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_BrainCapabilityGrants_AgentKey_CapabilityKey" ON "BrainCapabilityGrants" ("AgentKey", "CapabilityKey");""",
        """CREATE TABLE IF NOT EXISTS "BrainAuditEvents" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "AgentKey" varchar(80) NOT NULL, "EventType" varchar(100) NOT NULL, "TargetArm" varchar(120) NOT NULL, "ActionKey" varchar(200) NOT NULL, "Outcome" varchar(80) NOT NULL, "Rationale" text NULL, "CorrelationId" varchar(160) NULL, "OccurredAt" timestamptz NOT NULL);""",
        """ALTER TABLE "BrainAuditEvents" ALTER COLUMN "Rationale" TYPE text;""", // B93_SCHEMA_LONG_TEXT
        """CREATE INDEX IF NOT EXISTS "IX_BrainAuditEvents_OccurredAt" ON "BrainAuditEvents" ("OccurredAt");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainAuditEvents_AgentKey" ON "BrainAuditEvents" ("AgentKey");"""
    };
    foreach (var command in commands) await dbContext.Database.ExecuteSqlRawAsync(command);
}


static async Task EnsureBrainMemorySchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "Recommendation" TYPE text;""", // B93_SCHEMA_DECISION_TEXT
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "RecommendationReasoning" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "HumanDecision" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "HumanReasoning" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "Outcome" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainDecisionRecords" ALTER COLUMN "OutcomeNotes" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainLearningCandidates" ALTER COLUMN "ProposedLesson" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainLearningCandidates" ALTER COLUMN "Reasoning" TYPE text;""",
        """ALTER TABLE IF EXISTS "BrainLearningCandidates" ALTER COLUMN "EvidenceRefs" TYPE text;""",

        """CREATE TABLE IF NOT EXISTS "BrainMemoryItems" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "MemoryKey" varchar(80) NOT NULL, "MemoryType" varchar(40) NOT NULL, "ArmScope" varchar(120) NOT NULL, "SubjectType" varchar(120) NULL, "SubjectKey" varchar(240) NULL, "Title" varchar(300) NOT NULL, "Content" text NOT NULL, "Status" varchar(40) NOT NULL, "Confidence" numeric(5,4) NOT NULL, "SourceType" varchar(80) NOT NULL, "SourceRef" varchar(1000) NULL, "EvidenceSummary" text NULL, "CreatedBy" varchar(120) NOT NULL, "SupersedesMemoryId" bigint NULL, "CreatedAt" timestamptz NOT NULL, "UpdatedAt" timestamptz NOT NULL, "LastConfirmedAt" timestamptz NULL);""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_BrainMemoryItems_MemoryKey" ON "BrainMemoryItems" ("MemoryKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainMemoryItems_ArmScope_SubjectType_SubjectKey" ON "BrainMemoryItems" ("ArmScope", "SubjectType", "SubjectKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainMemoryItems_MemoryType_Status" ON "BrainMemoryItems" ("MemoryType", "Status");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainMemoryItems_UpdatedAt" ON "BrainMemoryItems" ("UpdatedAt");""",
        """CREATE TABLE IF NOT EXISTS "BrainDecisionRecords" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "DecisionKey" varchar(80) NOT NULL, "AgentKey" varchar(80) NOT NULL, "ArmScope" varchar(120) NOT NULL, "SubjectType" varchar(120) NULL, "SubjectKey" varchar(240) NULL, "DecisionType" varchar(100) NOT NULL, "Recommendation" text NOT NULL, "RecommendationReasoning" text NULL, "RecommendationConfidence" numeric(5,4) NULL, "HumanDecision" text NULL, "HumanReasoning" text NULL, "Outcome" text NULL, "OutcomeNotes" text NULL, "RecommendedAt" timestamptz NOT NULL, "DecidedAt" timestamptz NULL, "OutcomeRecordedAt" timestamptz NULL);""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_BrainDecisionRecords_DecisionKey" ON "BrainDecisionRecords" ("DecisionKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainDecisionRecords_ArmScope_SubjectType_SubjectKey" ON "BrainDecisionRecords" ("ArmScope", "SubjectType", "SubjectKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainDecisionRecords_RecommendedAt" ON "BrainDecisionRecords" ("RecommendedAt");""",
        """CREATE TABLE IF NOT EXISTS "BrainLearningCandidates" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "LearningKey" varchar(80) NOT NULL, "AgentKey" varchar(80) NOT NULL, "ArmScope" varchar(120) NOT NULL, "SubjectType" varchar(120) NULL, "SubjectKey" varchar(240) NULL, "ProposedLesson" text NOT NULL, "Reasoning" text NULL, "EvidenceRefs" text NULL, "Confidence" numeric(5,4) NOT NULL, "Status" varchar(40) NOT NULL, "ReviewReason" text NULL, "PromotedMemoryId" bigint NULL, "CreatedAt" timestamptz NOT NULL, "ReviewedAt" timestamptz NULL);""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_BrainLearningCandidates_LearningKey" ON "BrainLearningCandidates" ("LearningKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainLearningCandidates_Status_CreatedAt" ON "BrainLearningCandidates" ("Status", "CreatedAt");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainLearningCandidates_ArmScope_SubjectType_SubjectKey" ON "BrainLearningCandidates" ("ArmScope", "SubjectType", "SubjectKey");""",
        """CREATE TABLE IF NOT EXISTS "BrainContradictions" ("Id" bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, "MemoryAId" bigint NOT NULL, "MemoryBId" bigint NOT NULL, "Description" text NOT NULL, "Status" varchar(40) NOT NULL, "DetectedBy" varchar(120) NOT NULL, "Resolution" text NULL, "ResolvedBy" varchar(120) NULL, "CreatedAt" timestamptz NOT NULL, "ResolvedAt" timestamptz NULL);""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainContradictions_Status_CreatedAt" ON "BrainContradictions" ("Status", "CreatedAt");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainContradictions_MemoryAId" ON "BrainContradictions" ("MemoryAId");""",
        """CREATE INDEX IF NOT EXISTS "IX_BrainContradictions_MemoryBId" ON "BrainContradictions" ("MemoryBId");"""
    };
    foreach (var command in commands) await dbContext.Database.ExecuteSqlRawAsync(command);
}

static async Task EnsureNebulaSchemaAsync(ApplicationDbContext dbContext)
{
    var commands = new[]
    {
        """CREATE TABLE IF NOT EXISTS "ProductFamilyTemplates" ("Id" uuid NOT NULL, "FamilyKey" varchar(120) NOT NULL, "FamilyName" varchar(160) NOT NULL, "ProductTypeCode" varchar(40), "ProductionFamily" varchar(120), "SquareCategoryName" varchar(120), "SquareCategoryId" varchar(192), "WooCategoryName" varchar(160), "TaxBehavior" varchar(40) NOT NULL DEFAULT 'STANDARD', "InventoryBehavior" varchar(40) NOT NULL DEFAULT 'TRACKED', "FulfillmentModel" varchar(40) NOT NULL DEFAULT 'MANUFACTURED', "DefaultPriceCents" bigint NOT NULL DEFAULT 0, "Currency" varchar(4) NOT NULL DEFAULT 'USD', "ShippingLengthInches" numeric(10,2), "ShippingWidthInches" numeric(10,2), "ShippingHeightInches" numeric(10,2), "ShippingWeightOunces" numeric(10,2), "SellInPerson" boolean NOT NULL DEFAULT TRUE, "SellOnline" boolean NOT NULL DEFAULT TRUE, "TrackInventory" boolean NOT NULL DEFAULT TRUE, "DefaultDescription" text, "DefaultNotes" varchar(500), "IsActive" boolean NOT NULL DEFAULT TRUE, "CreatedAt" timestamptz NOT NULL DEFAULT NOW(), "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT "PK_ProductFamilyTemplates" PRIMARY KEY ("Id"));""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_ProductFamilyTemplates_FamilyKey" ON "ProductFamilyTemplates" ("FamilyKey");""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_ProductFamilyTemplates_FamilyName_ProductTypeCode" ON "ProductFamilyTemplates" ("FamilyName", "ProductTypeCode");""",
        """CREATE TABLE IF NOT EXISTS "ProductFamilyVariantOptions" ("Id" uuid NOT NULL, "ProductFamilyTemplateId" uuid NOT NULL, "DimensionKey" varchar(40) NOT NULL DEFAULT 'LEATHER', "OptionCode" varchar(40) NOT NULL, "OptionName" varchar(120) NOT NULL, "IsDefaultSelected" boolean NOT NULL DEFAULT TRUE, "IsEnabled" boolean NOT NULL DEFAULT TRUE, "SortOrder" integer NOT NULL DEFAULT 0, "CreatedAt" timestamptz NOT NULL DEFAULT NOW(), "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT "PK_ProductFamilyVariantOptions" PRIMARY KEY ("Id"), CONSTRAINT "FK_ProductFamilyVariantOptions_ProductFamilyTemplates_ProductFamilyTemplateId" FOREIGN KEY ("ProductFamilyTemplateId") REFERENCES "ProductFamilyTemplates" ("Id") ON DELETE CASCADE);""",
        """CREATE INDEX IF NOT EXISTS "IX_ProductFamilyVariantOptions_ProductFamilyTemplateId" ON "ProductFamilyVariantOptions" ("ProductFamilyTemplateId");""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_ProductFamilyVariantOptions_ProductFamilyTemplateId_DimensionKey_OptionCode" ON "ProductFamilyVariantOptions" ("ProductFamilyTemplateId", "DimensionKey", "OptionCode");""",
        """CREATE TABLE IF NOT EXISTS "ProductArtworks" ("Id" uuid NOT NULL, "ArtworkKey" varchar(260) NOT NULL, "ArtworkName" varchar(220) NOT NULL, "DesignAssetPath" varchar(400), "ProductImagePath" varchar(400), "Notes" varchar(500), "CreatedAt" timestamptz NOT NULL DEFAULT NOW(), "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT "PK_ProductArtworks" PRIMARY KEY ("Id"));""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_ProductArtworks_ArtworkKey" ON "ProductArtworks" ("ArtworkKey");""",
        """CREATE TABLE IF NOT EXISTS "NebulaCreationBatches" ("Id" uuid NOT NULL, "OperationKey" varchar(80) NOT NULL, "WorkflowType" varchar(40) NOT NULL, "Status" varchar(40) NOT NULL, "RequestedName" varchar(220) NOT NULL, "ArtworkKey" varchar(260), "ArtworkName" varchar(220), "PayloadJson" text, "LastError" varchar(2000), "CompletedAt" timestamptz, "CreatedAt" timestamptz NOT NULL DEFAULT NOW(), "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT "PK_NebulaCreationBatches" PRIMARY KEY ("Id"));""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_NebulaCreationBatches_OperationKey" ON "NebulaCreationBatches" ("OperationKey");""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatches_Status" ON "NebulaCreationBatches" ("Status");""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatches_WorkflowType_CreatedAt" ON "NebulaCreationBatches" ("WorkflowType", "CreatedAt");""",
        """CREATE TABLE IF NOT EXISTS "NebulaCreationBatchVariants" ("Id" uuid NOT NULL, "BatchId" uuid NOT NULL, "ProductFamilyTemplateId" uuid, "SellableProductId" uuid, "ProductName" varchar(220) NOT NULL, "ProductTypeCode" varchar(40), "LeatherCode" varchar(8), "Status" varchar(40) NOT NULL DEFAULT 'PENDING_DRAFT', "ReservedSquareSku" varchar(120), "SquareCatalogItemId" varchar(192), "SquareCatalogVariationId" varchar(192), "WooProductId" varchar(192), "WooVariationId" varchar(192), "LastError" varchar(2000), "AttemptCount" integer NOT NULL DEFAULT 0, "RetryAllowed" boolean NOT NULL DEFAULT TRUE, "LastAttemptedAt" timestamptz, "CreatedAt" timestamptz NOT NULL DEFAULT NOW(), "UpdatedAt" timestamptz NOT NULL DEFAULT NOW(), CONSTRAINT "PK_NebulaCreationBatchVariants" PRIMARY KEY ("Id"), CONSTRAINT "FK_NebulaCreationBatchVariants_NebulaCreationBatches_BatchId" FOREIGN KEY ("BatchId") REFERENCES "NebulaCreationBatches" ("Id") ON DELETE CASCADE, CONSTRAINT "FK_NebulaCreationBatchVariants_ProductFamilyTemplates_ProductFamilyTemplateId" FOREIGN KEY ("ProductFamilyTemplateId") REFERENCES "ProductFamilyTemplates" ("Id") ON DELETE RESTRICT, CONSTRAINT "FK_NebulaCreationBatchVariants_SellableProducts_SellableProductId" FOREIGN KEY ("SellableProductId") REFERENCES "SellableProducts" ("Id") ON DELETE RESTRICT);""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatchVariants_BatchId" ON "NebulaCreationBatchVariants" ("BatchId");""",
        """CREATE UNIQUE INDEX IF NOT EXISTS "IX_NebulaCreationBatchVariants_BatchId_ProductTypeCode_LeatherCode" ON "NebulaCreationBatchVariants" ("BatchId", "ProductTypeCode", "LeatherCode");""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatchVariants_ProductFamilyTemplateId" ON "NebulaCreationBatchVariants" ("ProductFamilyTemplateId");""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatchVariants_SellableProductId" ON "NebulaCreationBatchVariants" ("SellableProductId");""",
        """CREATE INDEX IF NOT EXISTS "IX_NebulaCreationBatchVariants_Status" ON "NebulaCreationBatchVariants" ("Status");"""
    };

    foreach (var command in commands)
    {
        await dbContext.Database.ExecuteSqlRawAsync(command);
    }
}
