<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-agent-skills

Publishes the [Agent Skills discovery](https://github.com/cloudflare/agent-skills-discovery-rfc)
index (RFC v0.2.0, `$schema`
`https://schemas.agentskills.io/discovery/0.2.0/schema.json`) at
`/.well-known/agent-skills/index.json`, and the `SKILL.md` documents it names.

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

A provider that hosts its artifact elsewhere sets `url` **and** `digest` on the
`AgentSkill` itself; both are used verbatim. This is also the only way to
publish a `type: 'archive'` skill — this package renders `SKILL.md` files, not
archives. A `url` without a `digest` is not accepted: the digest this package
could compute would be over its own rendering, not over the bytes at that URL.

## The rendered SKILL.md

```markdown
---
name: product-search
description: "Search the catalogue by keyword."
---

# Product Search

Search the catalogue by keyword.

**Tags:** commerce

## Interface

- **Input:** text/plain
- **Output:** application/json
```

The frontmatter is what the Agent Skills format requires; `name` is the skill
id and equals the directory the document is served from. The description is
always emitted as a quoted string, so a colon or a `#` in it stays text.

An `## Access` section pointing at `/auth.md` is added only while an auth method
is registered (`AuthMethodDescriptorInterface`), so the document never links to
a page that does not exist.

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

### What the index admits

The skill registry is shared with the A2A card and the MCP server card. The
discovery RFC is stricter than either, so a skill is listed only if

- its id is a valid Agent Skills name: 1–64 characters of `a-z`, `0-9` and
  `-`, with no leading, trailing or consecutive hyphen;
- its description is not empty and at most 1024 characters;
- its type is `skill-md` or `archive`;
- a `digest` it sets has the form `sha256:` + 64 lowercase hex digits;
- an `archive`, or a `skill-md` with its own `url`, brings both `url` and
  `digest`.

A skill that fails a rule is left out of the index — and its `SKILL.md` route
404s — with a warning in the log naming the skill and the rule. It still
appears in the A2A and MCP cards.

With nothing publishable the index returns **404** and is not announced in
`llms.txt` or the API catalogue — an index listing no skills claims the shop
offers none, which is not the same as none being registered.

## Decommission

Remove the `composer require`, the bundle entry and the route import. No table,
no cache pool. Skill definitions live in whichever bundle provides them.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
