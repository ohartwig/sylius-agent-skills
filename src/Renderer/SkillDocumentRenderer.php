<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Renderer;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Registry\AuthMethodRegistry;

/**
 * Renders one skill as the SKILL.md the index points at.
 *
 * The index carries a SHA-256 digest of each artifact, so whatever is served
 * here has to be byte-identical to whatever the digest was computed over. Both
 * go through this one method for exactly that reason — a document rendered in
 * one place and hashed in another drifts the first time either changes.
 */
final class SkillDocumentRenderer
{
    private const YAML_STRING_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly ?AuthMethodRegistry $authMethods = null)
    {
    }

    public function render(AgentSkill $skill, AgentContext $context): string
    {
        // The Agent Skills format requires YAML frontmatter with `name` and
        // `description`; the name has to equal the directory the document is
        // served from. The description is emitted as a double-quoted scalar —
        // a JSON string is one — so a colon, a hash or a leading dash in it can
        // never turn into YAML structure.
        $out = "---\n";
        $out .= 'name: '.$skill->id."\n";
        $out .= 'description: '.json_encode($skill->description, self::YAML_STRING_FLAGS)."\n";
        $out .= "---\n\n";

        $out .= '# '.$skill->name."\n\n".$skill->description."\n";

        if ([] !== $skill->tags) {
            $out .= "\n**Tags:** ".implode(', ', $skill->tags)."\n";
        }

        $out .= "\n## Interface\n\n";
        $out .= '- **Input:** '.implode(', ', $skill->inputModes)."\n";
        $out .= '- **Output:** '.implode(', ', $skill->outputModes)."\n";

        // auth.md only exists once some bundle describes an auth method; a
        // pointer to it before then sends the agent to a 404.
        if (null !== $this->authMethods && [] !== $this->authMethods->all($context)) {
            $out .= "\n## Access\n\n";
            $out .= 'See '.$context->url('/auth.md')." for how to authenticate.\n";
        }

        return $out;
    }

    public function digest(string $document): string
    {
        return 'sha256:'.hash('sha256', $document);
    }
}
