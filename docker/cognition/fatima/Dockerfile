# Module-owned headless FAtiMA/CiF driver.
#
# The toolkit under ./toolkit is vendored from nagi1/FAtiMAtry (itself a fork of
# GAIPS/FAtiMA-Toolkit at 56b7cbd9) and maintained here as part of this module.
# It is a faithful copy of that fork's tracked tree, plus Applications/CiFSeeder,
# which authors our social-exchange scenarios through the real APIs.
#
# Upstream ships Windows-only tooling next to the engine: the WinForms authoring
# tools and the Workflow-based WebAPIWF cannot build on Linux. The engine assets
# are netstandard2.0 and the HTTP server targets net8.0, so publishing the single
# server project is enough — the solution must never be built here, or the
# Windows projects are pulled in and the build fails.
FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build

WORKDIR /src
COPY toolkit/ ./

WORKDIR /src/FAtiMA-Toolkit
RUN dotnet publish Applications/FAtiMAHTTPServer/FAtiMAHTTPServer.csproj -c Release -o /app

FROM mcr.microsoft.com/dotnet/runtime:8.0 AS runtime

# curl is only for the container health check; the base runtime image ships none.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=build /app .

# The reference scenario stays in the image as an authored starting point; the
# module authors its own assets rather than shipping the paper's chat demo.
COPY --from=build /src/FAtiMA-Toolkit/Applications/Scenarios/seed /app/scenarios

# Both ports are unprivileged, so the process never needs root. The HTTPS prefix
# is bound from the second argument because FAtiMA defaults it to 443, which a
# non-root user cannot bind.
USER $APP_UID

EXPOSE 8000
ENTRYPOINT ["dotnet", "FAtiMAHTTPServer.dll"]
CMD ["8000", "8443"]
