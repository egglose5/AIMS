FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src

COPY Mustaine-AI/Mustaine-AI.csproj Mustaine-AI/
RUN dotnet restore Mustaine-AI/Mustaine-AI.csproj

COPY . .
RUN dotnet publish Mustaine-AI/Mustaine-AI.csproj -c Release -o /app/publish /p:UseAppHost=false

FROM mcr.microsoft.com/dotnet/aspnet:10.0 AS final
WORKDIR /app
RUN apt-get update \
	&& apt-get install -y --no-install-recommends libgssapi-krb5-2 \
	&& rm -rf /var/lib/apt/lists/*
COPY --from=build /app/publish .
COPY --from=build /src/Mustaine-AI/HistoricalEmailArchives ./HistoricalEmailArchives
EXPOSE 8080
ENTRYPOINT ["dotnet", "Mustaine-AI.dll"]
