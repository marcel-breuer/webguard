# Documentation index

## Product and architecture

- [Features](features.md)
- [Architecture and technology stack](architecture.md)
- [Architecture notes](architecture/api-boundaries.md): API boundaries, first-party UI contract, derived monitoring health, operational metrics.

## API and integrations

- [REST API guide](api/rest-api.md): authentication, scoped keys, conventions, errors, rate limits, and the full endpoint table.
- [OpenAPI contract](api/openapi.json): machine-readable REST contract, kept in sync by a test.
- [MCP server guide](mcp.md): client setup, tool catalogue, resources, prompts, and permission model.
- [Shared API contract and mobile API](api/external-v1.md)
- [Scanner instance API](integrations/webguard-instance-api.md)
- [Server agent API](integrations/server-agent-api.md)

## Operations and development

- [Installation, Docker, and operations](installation.md)
- [SvelteKit production cutover](operations/sveltekit-cutover.md)
- [Test concept](test-concept.md)
- [Contributing](contributing.md)
- [Releases and changelog](releases.md)
