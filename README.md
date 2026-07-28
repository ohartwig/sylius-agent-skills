<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-agent-skills

Publishes the [Agent Skills](https://github.com/cloudflare/agent-skills-discovery-rfc)
discovery index at `/.well-known/agent-skills/index.json`, and the `SKILL.md`
documents it names.

## Install

```bash
composer require koh/sylius-agent-skills
```

```php
// config/bundles.php
Koh\SyliusAgentSkills\KohSyliusAgentSkillsBundle::class => ['all' => true],
```

```yaml
# config/routes/koh_agent.yaml
koh_agent_skills:
    resource: '@KohSyliusAgentSkillsBundle/config/routes.php'
```

No configuration — skills come from the registry.

## The digest is computed, not configured

Each index entry carries a SHA-256 digest of its artifact. This package renders
the `SKILL.md` **and** hashes it in the same place, so the digest always matches
the bytes served.

A digest kept in configuration goes stale the moment a description changes, and
a client that verifies it then rejects a perfectly good skill — while the shop
looks fine to everyone not checking.

A provider that hosts its artifact elsewhere may set `url` and `digest` on the
`AgentSkill` itself; those are used verbatim.

## Registering skills

Implement `AgentSkillProviderInterface` from `koh/sylius-agent-contracts`:

```php
final class ProductSearchSkillProvider implements AgentSkillProviderInterface
{
    public function getAgentSkills(AgentContext $context): iterable
    {
        yield new AgentSkill(
            id: 'product-search',
            name: 'Product Search',
            description: 'Search the catalogue by keyword.',
            tags: ['commerce'],
        );
    }
}
```

The same skill also appears in the A2A agent card and the MCP server card, if
those bundles are installed. Described once, projected three ways.

With nothing registered the index returns **404** — an index listing no skills
claims the shop offers none, which is not the same as none being registered.

## Decommission

Remove the `composer require`, the bundle entry and the route import. No table,
no cache pool. Skill definitions live in whichever bundle provides them.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
